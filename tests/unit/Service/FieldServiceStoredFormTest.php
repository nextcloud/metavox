<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Validation\LinkSettings;
use OCA\MetaVox\Validation\FieldValue;
use OCA\MetaVox\Service\ApiValidationException;
use OCA\MetaVox\Service\MetadataVersions;
use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\FileReferenceService;
use OCA\MetaVox\Service\PushService;
use OCA\MetaVox\Service\SearchWordsService;
use OCA\MetaVox\Service\ViewService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Every save goes through FieldService, so file links are stored the same way on every route. */
class FieldServiceStoredFormTest extends TestCase {

    private const LINK = 3;
    private const TEXT = 4;
    private const FOLDER_LINK = 5;

    /** @var list<array{int, string}> [team folder, value] per normalize call */
    private array $normalized = [];
    private ?string $stored = null;
    /** @var list<?int> the file each normalized value belongs to */
    private array $selfFileIds = [];
    /** @var list<array{string, bool}> [current value, as the user] per normalize call */
    private array $context = [];
    /** @var array<string, list<array>> rows each table answers with */
    private array $rows = [];
    /** @var list<string> the tables read from, in order */
    private array $reads = [];

    private function service(): FieldService {
        $cache = $this->createMock(ICache::class);
        // The team folder uses all three fields.
        $cache->method('get')->willReturnCallback(fn (string $key) => str_starts_with($key, 'field_folders_') ? [7 => true] : [
            ['id' => self::LINK, 'field_name' => 'link', 'field_type' => 'filelink'],
            ['id' => self::TEXT, 'field_name' => 'note', 'field_type' => 'text'],
            ['id' => self::FOLDER_LINK, 'field_name' => 'folderlink', 'field_type' => 'filelink', 'applies_to_groupfolder' => 1],
        ]);

        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($cache);

        // Nothing is stored yet, unless a test says what is.
        $table = null;
        $qb = $this->createMock(IQueryBuilder::class);
        foreach (['select', 'where', 'andWhere'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('from')->willReturnCallback(function (string $from) use (&$table, $qb) {
            $table = $from;
            $this->reads[] = $from;
            return $qb;
        });
        $qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
        $qb->method('executeQuery')->willReturnCallback(function () use (&$table) {
            $rows = $this->rows[$table] ?? [];
            $result = $this->createMock(IResult::class);
            $result->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows, false]);
            return $result;
        });

        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($qb);
        $db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_MYSQL);
        $db->method('executeStatement')->willReturnCallback(function (string $sql, array $params) {
            // (file_id,) groupfolder_id, field_name, field_value, created_at, updated_at
            $this->stored = $params[count($params) - 3];
            return 1;
        });

        $fileReferences = $this->createMock(FileReferenceService::class);
        $fileReferences->method('normalize')->willReturnCallback(function (string $value, int $gf, ?int $self = null, string $current = '', bool $asUser = true): string {
            $this->normalized[] = [$gf, $value];
            $this->selfFileIds[] = $self;
            $this->context[] = [$current, $asUser];
            return '12:';
        });

        return new FieldService($db, $this->createMock(IGroupManager::class), $this->createMock(IUserManager::class),
            $this->createMock(SearchWordsService::class), $this->createMock(ViewService::class), $this->createMock(PushService::class),
            $cacheFactory, $this->createMock(LoggerInterface::class), $fileReferences, $this->createMock(MetadataVersions::class), new FieldValue($this->createMock(IUserManager::class), $this->createMock(LinkSettings::class)));
    }

    public function testAFileLinkOnAFileIsStoredNormalized(): void {
        $this->assertSame(1, $this->service()->saveValues(7, [100 => ['link' => '12:/HR/contract.pdf']]));

        $this->assertSame([[7, '12:/HR/contract.pdf']], $this->normalized);
        $this->assertSame([100], $this->selfFileIds, 'the file may not link to itself');
        $this->assertSame('12:', $this->stored);
    }

    public function testAFileLinkOnTheTeamFolderIsStoredNormalized(): void {
        $this->assertSame(1, $this->service()->saveGroupfolderValues(7, ['folderlink' => '12:/HR/contract.pdf']));

        $this->assertSame([[7, '12:/HR/contract.pdf']], $this->normalized);
        $this->assertSame('12:', $this->stored);
    }

    public function testATeamFolderValueItsFieldDoesNotTakeIsRefused(): void {
        try {
            $this->service()->saveGroupfolderValues(7, ['folderlink' => true]);
            $this->fail('A yes/no was saved as a file link');
        } catch (ApiValidationException $e) {
            $this->assertSame(['folderlink' => 'Enter a link to a file'], $e->getErrors());
        }
        $this->assertNull($this->stored);
    }

    public function testAFileFieldIsNoTeamFolderValue(): void {
        $this->assertSame(0, $this->service()->saveGroupfolderValues(7, ['note' => 'x']));
        $this->assertNull($this->stored);
    }

    public function testTheLinksStoredOnTheFileGoAlongToBeKept(): void {
        $this->rows['metavox_file_gf_meta'] = [['file_id' => 100, 'field_name' => 'link', 'field_value' => '9:']];

        $this->service()->saveValues(7, [100 => ['link' => '9:;#12:'], 101 => ['link' => '12:']]);

        $this->assertSame([['9:', true], ['', true]], $this->context);
        $this->assertSame(1, count(array_keys($this->reads, 'metavox_file_gf_meta')), 'one query for all files');
    }

    public function testACopyTakesTheLinksAsTheyAre(): void {
        $this->service()->saveValues(7, [100 => ['link' => '9:']], false, true);

        $this->assertSame([['', false]], $this->context);
        $this->assertNotContains('metavox_file_gf_meta', $this->reads);
    }

    public function testNoFileLinkNoExtraQuery(): void {
        $this->service()->saveValues(7, [100 => ['note' => 'x']]);

        $this->assertNotContains('metavox_file_gf_meta', $this->reads);
    }

    public function testTheLinksOfTheTeamFolderGoAlongToo(): void {
        $this->rows['metavox_gf_metadata'] = [['field_name' => 'folderlink', 'field_value' => '9:']];

        $this->service()->saveGroupfolderValues(7, ['folderlink' => '9:;#12:']);

        $this->assertSame([['9:', true]], $this->context);
    }

    public function testOtherFieldsAreStoredAsGiven(): void {
        $this->service()->saveValues(7, [100 => ['note' => '12:/HR/contract.pdf']]);

        $this->assertSame([], $this->normalized);
        $this->assertSame('12:/HR/contract.pdf', $this->stored);
    }
}
