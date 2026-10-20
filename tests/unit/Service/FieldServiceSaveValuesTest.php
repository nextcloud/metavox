<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Validation\LinkSettings;
use OCA\MetaVox\Service\AccountService;
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

/** Many values cost a few queries, not a few per value. */
class FieldServiceSaveValuesTest extends TestCase {

    /** @var list<array{string, array}> each statement and its parameters */
    private array $statements = [];
    /** @var list<array> what the search words got */
    private array $words = [];
    /** @var int[] files a push was queued for */
    private array $pushed = [];
    /** rows the "already filled in" query finds */
    private array $filled = [];
    /** @var string[] transaction calls */
    private array $tx = [];
    /** the statement that fails, counted from 1 */
    private int $failAt = 0;
    /** whether the search index refuses the values */
    private bool $wordsFail = false;
    /** whether the caller already opened a transaction */
    private bool $inTransaction = false;
    /** transactions open now, savepoints included */
    private int $depth = 0;
    /** a value the database refuses, wherever it comes */
    private ?string $refusedValue = null;
    /** @var list<string> the field names a delete leaves */
    private array $kept = [];
    /** @var list<string> what the search words did after a delete */
    private array $afterDelete = [];
    /** @var int[] the fields the team folder uses */
    private array $assigned = [1, 2, 3, 4];

    public function testManyValuesAreOneUpsertAndOneWordUpdate(): void {
        $saved = $this->service()->saveValues(7, [
            10 => ['klant' => 'A', 'status' => 'Open'],
            11 => ['klant' => 'B', 'status' => 'Open'],
            12 => ['klant' => 'C'],
        ]);

        $this->assertSame(5, $saved);
        $this->assertCount(1, $this->statements);
        [$sql, $params] = $this->statements[0];
        $this->assertSame(5, substr_count($sql, '(?, ?, ?, ?, ?, ?)'));
        $this->assertSame([10, 7, 'klant', 'A'], \array_slice($params, 0, 4));
        $this->assertSame([[7, [1 => [10 => 'A', 11 => 'B', 12 => 'C'], 2 => [10 => 'Open', 11 => 'Open']]]], $this->words);
        $this->assertSame([10, 11, 12], $this->pushed);
    }

    public function testLargeSavesGoInBlocks(): void {
        $values = [];
        foreach (range(1, 1200) as $fileId) {
            $values[$fileId] = ['klant' => 'x'];
        }

        $this->assertSame(1200, $this->service()->saveValues(7, $values));

        $this->assertSame([500, 500, 200], array_map(fn (array $s) => substr_count($s[0], '(?, ?, ?, ?, ?, ?)'), $this->statements));
        $this->assertSame(['begin', 'words', 'commit'], $this->tx);
    }

    public function testBlocksAreAllOrNothing(): void {
        $this->failAt = 2;
        $values = array_fill_keys(range(1, 600), ['klant' => 'x']);

        try {
            $this->service()->saveValues(7, $values);
            $this->fail('the error should come through');
        } catch (\RuntimeException) {
        }

        $this->assertSame(['begin', 'rollBack'], $this->tx);
        $this->assertSame([], $this->words);
        $this->assertSame([], $this->pushed);
    }

    /** The value and its search index together: of two saves at once, the last one is found. */
    public function testOneValueAndItsIndexAreOneTransaction(): void {
        $this->service()->saveValues(7, [10 => ['klant' => 'A']]);

        $this->assertSame(['begin', 'words', 'commit'], $this->tx);
    }

    /** Inside the caller's transaction (the batch update's per team folder) no second layer. */
    public function testInsideACallersTransactionItAddsNone(): void {
        $this->inTransaction = true;

        $this->service()->saveValues(7, [10 => ['klant' => 'A']]);

        $this->assertSame(['words'], $this->tx);
    }

    public function testManyFilesAreSavedTogetherWhenTheyCanBe(): void {
        $failed = $this->service()->saveValuesPerFile(7, [10 => ['klant' => 'A'], 11 => ['klant' => 'B']]);

        $this->assertSame([], $failed);
        $this->assertCount(1, $this->statements);
        $this->assertSame(['begin', 'words', 'commit'], $this->tx);
        $this->assertSame([10, 11], $this->pushed);
    }

    /** One file the database will not store: the others are saved file by file. */
    public function testAFileTheDatabaseRefusesLeavesTheOthersSaved(): void {
        $this->refusedValue = 'too long';

        $failed = $this->service()->saveValuesPerFile(7, [10 => ['klant' => 'A'], 11 => ['klant' => 'too long'], 12 => ['klant' => 'C']]);

        $this->assertSame([11], $failed);
        // Together first, then each file in its own savepoint.
        $this->assertSame(['begin', 'rollBack', 'begin', 'words', 'commit', 'begin', 'rollBack', 'begin', 'words', 'commit'], $this->tx);
        $this->assertSame([10, 12], $this->pushed);
        $this->assertSame(0, $this->depth);
    }

    public function testAValueAFieldDoesNotTakeStillRefusesTheLot(): void {
        $this->expectException(ApiValidationException::class);

        $this->service()->saveValuesPerFile(7, [10 => ['klant' => 'A'], 11 => ['status' => 'Unknown']]);
    }

    /** The filter trusts the index: a value it could not index is not saved either. */
    public function testAValueTheIndexCannotTakeIsNotSaved(): void {
        $this->wordsFail = true;

        try {
            $this->service()->saveValues(7, [10 => ['klant' => 'A']]);
            $this->fail('the error should come through');
        } catch (\RuntimeException) {
        }

        $this->assertSame(['begin', 'words', 'rollBack'], $this->tx);
        $this->assertSame([], $this->pushed);
    }

    public function testUnknownFieldsAreSkipped(): void {
        $this->assertSame(1, $this->service()->saveValues(7, [10 => ['klant' => 'A', 'weg' => 'x']]));
        $this->assertSame(0, $this->service()->saveValues(7, [10 => ['weg' => 'x']]));
        $this->assertCount(1, $this->statements);
    }

    public function testOnlyEmptyLeavesWhatIsFilledInAZeroToo(): void {
        // The query finds every stored value that is not empty; '0' is one.
        $this->filled = [['file_id' => 10, 'field_name' => 'status']];

        $saved = $this->service()->saveValues(7, [10 => ['status' => 'Open', 'klant' => 'A'], 11 => ['status' => 'Open']], true);

        $this->assertSame(2, $saved);
        $this->assertSame([[7, [1 => [10 => 'A'], 2 => [11 => 'Open']]]], $this->words);
    }

    public function testEachDatabaseGetsItsOwnUpsert(): void {
        $service = $this->service();

        $this->assertStringEndsWith('ON DUPLICATE KEY UPDATE field_value = VALUES(field_value), updated_at = VALUES(updated_at)', $service->upsertSql(1, true));
        $this->assertStringEndsWith('ON CONFLICT (file_id, groupfolder_id, field_name) DO UPDATE SET field_value = EXCLUDED.field_value, updated_at = EXCLUDED.updated_at', $service->upsertSql(1, false));
    }

    public function testAValueAFieldDoesNotTakeIsRefusedAndNothingIsSaved(): void {
        try {
            $this->service()->saveValues(7, [10 => ['klant' => 'A'], 11 => ['status' => 'Archived']]);
            $this->fail('An option that is not there was saved');
        } catch (ApiValidationException $e) {
            $this->assertSame(['status' => 'Choose one of the options of this field'], $e->getErrors());
        }
        $this->assertSame([], $this->statements);
    }

    public function testAValueStoredLikeThatAlreadyStays(): void {
        // An old value, from before the check, is sent back unchanged with a new one.
        $this->filled = [['file_id' => 10, 'field_name' => 'status', 'field_value' => 'Archived']];

        $this->assertSame(1, $this->service()->saveValues(7, [10 => ['status' => 'Archived', 'klant' => 'A']]));
        $this->assertSame([[7, [1 => [10 => 'A']]]], $this->words);
    }

    public function testARequiredFieldCannotBeEmptied(): void {
        $this->filled = [['file_id' => 10, 'field_name' => 'code', 'field_value' => 'X'], ['file_id' => 10, 'field_name' => 'akkoord', 'field_value' => '1']];
        foreach ([['code', '', 'This field is required'], ['code', null, 'This field is required']] as [$field, $value, $message]) {
            try {
                $this->service()->saveValues(7, [10 => [$field => $value]]);
                $this->fail("$field was emptied");
            } catch (ApiValidationException $e) {
                $this->assertSame([$field => $message], $e->getErrors());
            }
        }
    }

    public function testACheckboxFlaggedRequiredCanBeUnticked(): void {
        // A checkbox is never required, whatever flag 2.x left on it.
        $this->filled = [['file_id' => 10, 'field_name' => 'akkoord', 'field_value' => '1']];
        $this->assertSame(1, $this->service()->saveValues(7, [10 => ['akkoord' => '0']]));
    }

    public function testARequiredFieldThatIsEmptyMayStayEmpty(): void {
        // A file that came in without metadata: saving another field is no reason to refuse.
        $this->assertSame(1, $this->service()->saveValues(7, [10 => ['code' => '', 'klant' => 'A']]));
        $this->assertSame([[7, [1 => [10 => 'A']]]], $this->words);
    }

    public function testAFieldTheTeamFolderDoesNotUseIsLeftOut(): void {
        $this->assigned = [1];

        $this->assertSame(['refused' => [], 'unused' => [10 => ['status']]], $this->service()->refusedValues(7, [10 => ['klant' => 'A', 'status' => 'Open']]));
        $this->assertSame(1, $this->service()->saveValues(7, [10 => ['klant' => 'A', 'status' => 'Open']]));
        $this->assertSame([[7, [1 => [10 => 'A']]]], $this->words);
    }

    public function testClearingAllCanKeepTheRequiredFields(): void {
        $this->service()->clearFilesMetadata(7, [10, 11], true);
        // Not the checkbox flagged required: a checkbox is never required.
        $this->assertSame(['code'], $this->kept);
        $this->assertSame(['reindexed'], $this->afterDelete, 'the kept values stay findable');

        $this->kept = [];
        $this->afterDelete = [];
        $this->service()->clearFilesMetadata(7, [10]);
        $this->assertSame([], $this->kept);
        $this->assertSame(['removed'], $this->afterDelete);
    }

    public function testCopiedValuesAreSavedAsTheyWereStored(): void {
        $this->assigned = [];

        $this->assertSame(1, $this->service()->saveValues(7, [10 => ['status' => 'Archived']], asStored: true));
        $this->assertSame([[7, [2 => [10 => 'Archived']]]], $this->words);
    }

    private function service(): FieldService {
        $cache = $this->createMock(ICache::class);
        $cache->method('get')->willReturnCallback(fn (string $key) => str_starts_with($key, 'field_folders_')
            ? (in_array((int)substr($key, \strlen('field_folders_')), $this->assigned, true) ? [7 => true] : [])
            : ($key === 'fields_all' ? [
            ['id' => 1, 'field_name' => 'klant', 'field_type' => 'text'],
            ['id' => 2, 'field_name' => 'status', 'field_type' => 'select', 'field_options' => ['Open', 'Closed']],
            ['id' => 3, 'field_name' => 'code', 'field_type' => 'text', 'is_required' => true],
            ['id' => 4, 'field_name' => 'akkoord', 'field_type' => 'checkbox', 'is_required' => true],
        ] : null));
        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($cache);

        $db = $this->createMock(IDBConnection::class);
        $db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_MYSQL);
        $db->method('executeStatement')->willReturnCallback(function (string $sql, array $params): int {
            $this->statements[] = [$sql, $params];
            if (\count($this->statements) === $this->failAt) {
                throw new \RuntimeException('SQLSTATE[40001]: deadlock');
            }
            if ($this->refusedValue !== null && \in_array($this->refusedValue, $params, true)) {
                throw new \RuntimeException('SQLSTATE[54000]: index row size exceeds maximum');
            }
            return 1;
        });
        $db->method('inTransaction')->willReturnCallback(fn () => $this->inTransaction || $this->depth > 0);
        foreach (['beginTransaction' => 'begin', 'commit' => 'commit', 'rollBack' => 'rollBack'] as $method => $name) {
            $db->method($method)->willReturnCallback(function () use ($name): void {
                $this->tx[] = $name;
                $this->depth += $name === 'begin' ? 1 : -1;
            });
        }
        $db->method('getQueryBuilder')->willReturnCallback(function () {
            $qb = $this->createMock(IQueryBuilder::class);
            $table = null;
            foreach (['select', 'where', 'andWhere', 'delete'] as $method) {
                $qb->method($method)->willReturnSelf();
            }
            $qb->method('createNamedParameter')->willReturnCallback(fn (mixed $value) => $value);
            $qb->method('from')->willReturnCallback(function (string $from) use (&$table, $qb) {
                $table = $from;
                return $qb;
            });
            $expr = $this->createMock(IExpressionBuilder::class);
            $expr->method('notIn')->willReturnCallback(function (string $column, array $names): string {
                $this->kept = $names;
                return '';
            });
            $qb->method('expr')->willReturn($expr);
            $qb->method('executeQuery')->willReturnCallback(function () use (&$table) {
                $rows = $table === 'metavox_gf_assigns'
                    ? array_map(fn (int $id): array => ['field_id' => $id], $this->assigned)
                    : $this->filled;
                $result = $this->createMock(IResult::class);
                $result->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows, false]);
                return $result;
            });
            return $qb;
        });

        $words = $this->createMock(SearchWordsService::class);
        $words->method('removeFiles')->willReturnCallback(function (): void {
            $this->afterDelete[] = 'removed';
        });
        $words->method('reindexFiles')->willReturnCallback(function (): void {
            $this->afterDelete[] = 'reindexed';
        });
        $words->method('replaceValues')->willReturnCallback(function (int $gf, array $values): void {
            $this->tx[] = 'words';
            if ($this->wordsFail) {
                throw new \RuntimeException('SQLSTATE[HY000]: index');
            }
            $this->words[] = [$gf, $values];
        });
        $push = $this->createMock(PushService::class);
        $push->method('metadataChanged')->willReturnCallback(function (int $gf, int $fileId): void {
            $this->pushed[] = $fileId;
        });

        return new FieldService($db, $this->createMock(IGroupManager::class), $this->createMock(IUserManager::class),
            $words, $this->createMock(ViewService::class), $push,
            $cacheFactory, $this->createMock(LoggerInterface::class), $this->createMock(FileReferenceService::class), $this->createMock(MetadataVersions::class), new FieldValue($this->createMock(AccountService::class), $this->createMock(LinkSettings::class)));
    }
}
