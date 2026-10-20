<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Validation\LinkSettings;
use OCA\MetaVox\Service\AccountService;
use OCA\MetaVox\Validation\FieldValue;
use OCA\MetaVox\Service\ApiValidationException;
use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\FileReferenceService;
use OCA\MetaVox\Service\MetadataVersions;
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

/** The team folders a field is assigned to, for the search: cached, cleared when assignments change. */
class FieldServiceFolderFieldsTest extends TestCase {

    private const FIELDS = [
        ['id' => 7, 'field_name' => 'file_gf_status', 'field_label' => 'Status', 'field_type' => 'select'],
        ['id' => 8, 'field_name' => 'file_gf_tags', 'field_label' => 'Tags', 'field_type' => 'multiselect'],
    ];

    /** @var array<string, mixed> the shared cache */
    private array $cache = [];

    /** @var array<int, list<int>> field id => team folders it is assigned to */
    private array $assigned = [7 => [5, 9]];

    private int $assignQueries = 0;

    private function service(): FieldService {
        $this->cache['fields_all'] ??= self::FIELDS;
        $cache = $this->createMock(ICache::class);
        $cache->method('get')->willReturnCallback(fn (string $key) => $this->cache[$key] ?? null);
        $cache->method('set')->willReturnCallback(function (string $key, $value) {
            $this->cache[$key] = $value;
            return true;
        });
        $cache->method('remove')->willReturnCallback(function (string $key) {
            unset($this->cache[$key]);
            return true;
        });
        $factory = $this->createMock(ICacheFactory::class);
        $factory->method('createDistributed')->willReturn($cache);

        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturnCallback(fn () => $this->queryBuilder());

        return new FieldService(
            $db,
            $this->createMock(IGroupManager::class),
            $this->createMock(IUserManager::class),
            $this->createMock(SearchWordsService::class),
            $this->createMock(ViewService::class),
            $this->createMock(PushService::class),
            $factory,
            $this->createMock(LoggerInterface::class),
            $this->createMock(FileReferenceService::class),
            $this->createMock(MetadataVersions::class), new FieldValue($this->createMock(AccountService::class), $this->createMock(LinkSettings::class)),
        );
    }

    /**
     * Answers the assignments of one field (or, by the first number asked, of
     * one team folder), the in-use check, and the field's name.
     */
    private function queryBuilder(): IQueryBuilder {
        $table = null;
        $fieldId = null;
        $joined = false;
        $qb = $this->createMock(IQueryBuilder::class);
        foreach (['select', 'where', 'andWhere', 'delete', 'setMaxResults'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        // Only the in-use check joins: no field is in use here.
        $qb->method('innerJoin')->willReturnCallback(function () use (&$joined, $qb) {
            $joined = true;
            return $qb;
        });
        $qb->method('from')->willReturnCallback(function ($from) use (&$table, $qb) {
            $table = $from;
            return $qb;
        });
        $qb->method('createNamedParameter')->willReturnCallback(function ($value) use (&$fieldId) {
            $fieldId ??= \is_int($value) ? $value : null;
            return ':p';
        });
        $qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
        $qb->method('executeQuery')->willReturnCallback(function () use (&$table, &$fieldId, &$joined) {
            $rows = [];
            if ($table === 'metavox_gf_assigns' && !$joined) {
                $this->assignQueries++;
                $rows = $this->assigned[$fieldId] ?? [];
            } elseif ($table === 'metavox_gf_fields') {
                $rows = ['file_gf_status'];
            }
            $result = $this->createMock(IResult::class);
            $result->method('fetchOne')->willReturnOnConsecutiveCalls(...[...$rows, false]);
            $result->method('fetch')->willReturn(false);
            return $result;
        });
        $qb->method('executeStatement')->willReturn(1);
        return $qb;
    }

    public function testOnlyTheTeamFoldersThatHaveTheField(): void {
        $this->assertSame([5, 9], $this->service()->groupfoldersWithField('file_gf_status', [3, 5, '9', 12]));
        $this->assertSame([], $this->service()->groupfoldersWithField('file_gf_tags', [3, 5]));
    }

    public function testOneQueryThenTheCache(): void {
        $this->service()->groupfoldersWithField('file_gf_status', [5]);
        // Another request, the same cache.
        $this->service()->groupfoldersWithField('file_gf_status', [9]);

        $this->assertSame(1, $this->assignQueries);
    }

    public function testAnUnknownFieldIsNowhere(): void {
        $this->assertSame([], $this->service()->groupfoldersWithField('file_gf_gone', [5, 9]));
        $this->assertSame(0, $this->assignQueries);
    }

    public function testNoTeamFoldersNoQuery(): void {
        $this->assertSame([], $this->service()->groupfoldersWithField('file_gf_status', []));
        $this->assertSame(0, $this->assignQueries);
    }

    public function testAFieldWithoutTeamFoldersIsCachedToo(): void {
        $this->service()->groupfoldersWithField('file_gf_tags', [3]);
        $this->service()->groupfoldersWithField('file_gf_tags', [5]);

        $this->assertSame(1, $this->assignQueries);
    }

    public function testChangingAssignmentsForgetsTheTeamFoldersOfThoseFields(): void {
        $this->cache['field_folders_7'] = [5 => true];
        $this->cache['field_folders_8'] = [12 => true];
        $this->cache['field_folders_3'] = [12 => true];
        // Team folder 12 has fields 8 and 3, and gets 7 and 8.
        $this->assigned[12] = [8, 3];

        $this->service()->setGroupfolderFields(12, [7, 8]);

        $this->assertArrayNotHasKey('field_folders_7', $this->cache, 'added');
        $this->assertArrayNotHasKey('field_folders_3', $this->cache, 'removed');
        $this->assertArrayHasKey('field_folders_8', $this->cache, 'unchanged');
    }

    public function testOnlyExistingFieldsCanBeAssigned(): void {
        $this->assigned[12] = [8];
        try {
            $this->service()->setGroupfolderFields(12, [7, 99]);
            $this->fail('Assigned a field that does not exist');
        } catch (ApiValidationException $e) {
            $this->assertSame(['field_ids' => 'Choose existing fields'], $e->getErrors());
        }
        $this->assertSame([8], $this->assigned[12]);
    }

    public function testDeletingAFieldForgetsItsTeamFolders(): void {
        $this->service()->groupfoldersWithField('file_gf_status', [5]);
        $this->assertArrayHasKey('field_folders_7', $this->cache);

        $this->service()->deleteField(7);

        $this->assertArrayNotHasKey('field_folders_7', $this->cache);
    }
}
