<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Service\MetadataVersions;
use OCA\MetaVox\Access\ReadableScope;
use OCA\MetaVox\Service\FileAccess;
use OCA\MetaVox\Service\FilterService;
use OCA\MetaVox\Service\FileAccessService;
use OCA\MetaVox\Service\MetadataValueReader;
use OCP\Constants;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FilterServiceTest extends TestCase {

    private const TEAM_FOLDER = 7;

    private MetadataValueReader&MockObject $reader;
    private FileAccessService&MockObject $access;

    protected function setUp(): void {
        $this->reader = $this->createMock(MetadataValueReader::class);
        $this->access = $this->createMock(FileAccessService::class);
    }

    private function service(): FilterService {
        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));
        return new FilterService(
            $this->createMock(IDBConnection::class),
            $cacheFactory,
            $this->createMock(MetadataVersions::class),
            $this->access,
            $this->reader,
        );
    }

    /** The values a user who may read every file gets, without the permission bits. */
    private function values(array $fileIds, array $fieldNames = []): array {
        $this->access->method('getFileAccess')->willReturn(new FileAccess(self::TEAM_FOLDER, true));
        $values = $this->service()->getDirectoryMetadataForUser('alice', self::TEAM_FOLDER, $fileIds, $fieldNames);
        return array_map(fn (array $file) => array_diff_key($file, ['_permissions' => true]), $values);
    }

    public function testReadsFullValues(): void {
        $this->reader->expects($this->once())->method('readValues')
            ->with(self::TEAM_FOLDER, [10, 11])
            ->willReturnCallback(function (int $gf, array $ids, ?int $maxChars = null) {
                $this->assertNull($maxChars);
                return [10 => ['status' => 'Draft']];
            });

        $this->values([10, 11]);
    }

    public function testEveryFileIsInTheResult(): void {
        $this->reader->method('readValues')->willReturn([10 => ['status' => 'Draft']]);

        $this->assertSame(
            [10 => ['status' => 'Draft'], 11 => []],
            $this->values([10, 11]),
        );
    }

    public function testKeepsEmptyAndNullValues(): void {
        $this->reader->method('readValues')->willReturn([10 => ['note' => '', 'status' => null, 'count' => '0']]);

        $this->assertSame(
            [10 => ['note' => '', 'status' => null, 'count' => '0']],
            $this->values([10]),
        );
    }

    public function testOnlyRequestedFields(): void {
        $this->reader->method('readValues')->willReturn([10 => ['status' => 'Draft', 'owner' => 'alice', 'note' => '']]);

        $this->assertSame(
            [10 => ['status' => 'Draft', 'note' => '']],
            $this->values([10], ['status', 'note']),
        );
    }

    public function testDatabaseErrorsAreThrown(): void {
        $this->reader->method('readValues')->willThrowException(new \RuntimeException('SQLSTATE[42S02]'));

        $this->expectException(\RuntimeException::class);
        $this->values([10]);
    }

    public function testUserGetsOnlyReadableFilesWithPermissions(): void {
        $this->access->method('getFileAccess')->willReturnCallback(fn (string $uid, int $fileId) => match ($fileId) {
            10 => new FileAccess(self::TEAM_FOLDER, true),
            11 => new FileAccess(self::TEAM_FOLDER, false),
            12 => new FileAccess(99, true),
            default => FileAccess::none(),
        });
        $this->reader->expects($this->once())->method('readValues')
            ->with(self::TEAM_FOLDER, [10, 11])
            ->willReturn([10 => ['status' => 'Draft']]);

        $this->assertSame([
            10 => ['status' => 'Draft', '_permissions' => Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE],
            11 => ['_permissions' => Constants::PERMISSION_READ],
        ], $this->service()->getDirectoryMetadataForUser('alice', self::TEAM_FOLDER, [10, 11, 12, 13]));
    }

    public function testNoReadableFilesNoQuery(): void {
        $this->access->method('getFileAccess')->willReturn(FileAccess::none());
        $this->reader->expects($this->never())->method('readValues');

        $this->assertSame([], $this->service()->getDirectoryMetadataForUser('alice', self::TEAM_FOLDER, [10]));
    }

    /** rows the distinct values query returns */
    private array $distinctRows = [];
    private int $distinctQueries = 0;
    /** tables joined by the last distinct values query */
    private array $joined = [];
    private string $version = 'v1';

    /** A service with a cache that keeps what it is given, and a database for the distinct values. */
    private function distinctService(): FilterService {
        $stored = [];
        $cache = $this->createMock(ICache::class);
        $cache->method('get')->willReturnCallback(function (string $key) use (&$stored) {
            return $stored[$key] ?? null;
        });
        $cache->method('set')->willReturnCallback(function (string $key, $value) use (&$stored): bool {
            $stored[$key] = $value;
            return true;
        });
        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($cache);
        $versions = $this->createMock(MetadataVersions::class);
        $versions->method('get')->willReturnCallback(fn () => $this->version);

        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturnCallback(function () {
            $qb = $this->createMock(IQueryBuilder::class);
            foreach (['selectDistinct', 'from', 'where', 'andWhere', 'orderBy', 'addOrderBy', 'setMaxResults'] as $method) {
                $qb->method($method)->willReturnSelf();
            }
            $qb->method('innerJoin')->willReturnCallback(function ($from, string $table) use ($qb) {
                $this->joined[] = $table;
                return $qb;
            });
            $qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
            $qb->method('executeQuery')->willReturnCallback(function () {
                $this->distinctQueries++;
                $result = $this->createMock(IResult::class);
                $result->method('fetch')->willReturnOnConsecutiveCalls(...[...$this->distinctRows, false]);
                return $result;
            });
            return $qb;
        });
        return new FilterService($db, $cacheFactory, $versions, $this->access, $this->reader);
    }

    public function testDistinctValuesAreFreshRightAfterAChange(): void {
        $this->distinctRows = [['field_name' => 'klant', 'field_value' => 'A']];
        $service = $this->distinctService();

        $service->distinctValues(self::TEAM_FOLDER, ReadableScope::everything(), ['klant']);
        $first = $service->distinctValues(self::TEAM_FOLDER, ReadableScope::everything(), ['klant']);
        $this->distinctRows = [['field_name' => 'klant', 'field_value' => 'B']];
        $this->version = 'v2';
        $after = $service->distinctValues(self::TEAM_FOLDER, ReadableScope::everything(), ['klant']);

        $this->assertSame(['klant' => ['A']], $first);
        $this->assertSame(['klant' => ['B']], $after);
        $this->assertSame(2, $this->distinctQueries);
    }

    public function testDistinctValuesOnlyOfFilesThatStillExist(): void {
        $this->distinctService()->distinctValues(self::TEAM_FOLDER, ReadableScope::everything());

        $this->assertSame(['filecache'], $this->joined);
    }

    public function testTheSearchFilterOffersEachChoiceApart(): void {
        $this->distinctRows = [
            ['field_name' => 'tags', 'field_value' => 'Rood;#groen'],
            ['field_name' => 'tags', 'field_value' => 'Blauw'],
            ['field_name' => 'tags', 'field_value' => '2024'],
            ['field_name' => 'tags', 'field_value' => 'Rood'],
        ];

        $values = $this->distinctService()->suggestedValues('tags', self::TEAM_FOLDER, ReadableScope::everything());

        $this->assertSame(['2024', 'Blauw', 'groen', 'Rood'], $values);
    }

    /** A field of one value is searched whole, so it is offered whole. */
    public function testTheSearchFilterKeepsAValueOfOneValueFieldWhole(): void {
        $this->distinctRows = [['field_name' => 'status', 'field_value' => 'Klaar;#Gecontroleerd']];

        $values = $this->distinctService()->suggestedValues('status', self::TEAM_FOLDER, ReadableScope::everything(), 50, false);

        $this->assertSame(['Klaar;#Gecontroleerd'], $values);
    }

    public function testTheSearchFilterOffersAtMostTheLimit(): void {
        $this->distinctRows = array_map(fn (int $i) => ['field_name' => 'klant', 'field_value' => 'Klant ' . $i], range(1, 10));

        $this->assertCount(3, $this->distinctService()->suggestedValues('klant', self::TEAM_FOLDER, ReadableScope::everything(), 3));
    }

    /** The SQL the sorted file ids query runs, for a sort on a field of this type. */
    private function sortedSql(string $type): string {
        $sql = null;
        $statement = $this->createMock(IPreparedStatement::class);
        $statement->method('execute')->willReturn($this->createMock(IResult::class));
        $statement->method('fetch')->willReturn(false);
        $db = $this->createMock(IDBConnection::class);
        $db->method('prepare')->willReturnCallback(function (string $query) use (&$sql, $statement) {
            $sql = $query;
            return $statement;
        });
        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));
        $service = new FilterService($db, $cacheFactory, $this->createMock(MetadataVersions::class), $this->access, $this->reader);

        $service->getSortedFilteredFileIds(self::TEAM_FOLDER, ReadableScope::everything(), 'budget', 'desc', [], $type);
        return (string)$sql;
    }

    public function testSortingOrdersOnTheAggregateOfTheGroupedRows(): void {
        $sql = $this->sortedSql('text');

        $this->assertStringContainsString('GROUP BY base.file_id', $sql);
        $this->assertStringContainsString("ORDER BY CASE WHEN MAX(m_sort.field_value) IS NULL OR MAX(m_sort.field_value) = '' THEN 1 ELSE 0 END, MAX(m_sort.field_value) DESC", $sql);
        // PostgreSQL refuses the bare column next to GROUP BY.
        $this->assertDoesNotMatchRegularExpression('/ORDER BY.*[^(]m_sort\.field_value/', $sql);
    }

    public function testNumbersAreSortedAsNumbersAndEmptyOnesAreNoNumber(): void {
        $this->assertStringContainsString("CAST(NULLIF(MAX(m_sort.field_value), '') AS DECIMAL(20,6)) DESC", $this->sortedSql('number'));
    }
}
