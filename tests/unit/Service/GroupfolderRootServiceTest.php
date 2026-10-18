<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Service\GroupfolderRootService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class GroupfolderRootServiceTest extends TestCase {

    /** @var array<string, list<array<string, mixed>>> rows returned per queried table */
    private array $rowsByTable = [];

    /** @var array<string, list<list<array<string, mixed>>>> result sets per table, consumed in order */
    private array $queuedRows = [];

    /** @var string[] tables queried, in order */
    private array $queriedTables = [];

    private function service(): GroupfolderRootService {
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturnCallback(fn () => $this->queryBuilder());
        $db->method('escapeLikeParameter')->willReturnCallback(fn (string $s) => addcslashes($s, '\\_%'));
        return new GroupfolderRootService($db);
    }

    private function queryBuilder(): IQueryBuilder {
        $qb = $this->createMock(IQueryBuilder::class);
        $table = null;

        $qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
        $qb->method('select')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('from')->willReturnCallback(function (string $from) use ($qb, &$table) {
            $table = $from;
            return $qb;
        });
        $qb->method('innerJoin')->willReturnSelf();
        $qb->method('executeQuery')->willReturnCallback(function () use (&$table) {
            $this->queriedTables[] = $table;
            // A queued result set is used first, so a table can answer
            // successive queries differently.
            $rows = !empty($this->queuedRows[$table])
                ? array_shift($this->queuedRows[$table])
                : ($this->rowsByTable[$table] ?? []);
            $result = $this->createMock(IResult::class);
            $result->method('fetch')->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));
            return $result;
        });

        return $qb;
    }

    public function testUsesRecordedRootForJailedLayout(): void {
        $this->rowsByTable = [
            'group_folders' => [['folder_id' => 5, 'storage_id' => 1, 'root_id' => 900]],
            'filecache' => [['storage' => 1, 'path' => '__groupfolders/5']],
        ];

        $this->assertSame([['storage' => 1, 'path' => '__groupfolders/5']], $this->service()->getRoots(5));
        $this->assertNotContains('storages', $this->queriedTables);
    }

    public function testUsesRecordedRootForSeparateStorageLayout(): void {
        $this->rowsByTable = [
            'group_folders' => [['folder_id' => 6, 'storage_id' => 12, 'root_id' => 950]],
            'filecache' => [['storage' => 12, 'path' => 'files']],
        ];

        $this->assertSame([['storage' => 12, 'path' => 'files']], $this->service()->getRoots(6));
    }

    public function testFallsBackToStorageSuffixWithoutRecordedRoot(): void {
        // groupfolders before 19.1.10: no root_id column at all.
        $this->rowsByTable = [
            'group_folders' => [['folder_id' => 7, 'mount_point' => 'HR']],
            'storages' => [['numeric_id' => 33]],
        ];

        $this->assertSame([['storage' => 33, 'path' => 'files']], $this->service()->getRoots(7));
    }

    public function testFallsBackWhenRecordedRootIsNotInFilecache(): void {
        $this->rowsByTable = [
            'group_folders' => [['folder_id' => 8, 'storage_id' => 1, 'root_id' => 999]],
            'storages' => [['numeric_id' => 44]],
        ];

        $this->assertSame([['storage' => 44, 'path' => 'files']], $this->service()->getRoots(8));
    }

    public function testCachesRootsPerFolder(): void {
        $this->rowsByTable = [
            'group_folders' => [['folder_id' => 5, 'root_id' => 900]],
            'filecache' => [['storage' => 1, 'path' => '__groupfolders/5']],
        ];
        $service = $this->service();

        $service->getRoots(5);
        $queries = count($this->queriedTables);
        $service->getRoots(5);

        $this->assertCount($queries, $this->queriedTables);
    }

    public function testFindsTeamFolderOfJailedLayoutFile(): void {
        $this->queuedRows['filecache'] = [
            [['storage' => 1, 'path' => '__groupfolders/5/Docs/a.pdf', 'id' => 'local::/data/']],
            [['storage' => 1, 'path' => '__groupfolders/5']],
        ];
        $this->rowsByTable['group_folders'] = [['folder_id' => 5, 'root_id' => 900]];

        $this->assertSame(5, $this->service()->getGroupfolderIdForFile(42));
    }

    public function testFindsTeamFolderOfSeparateStorageFile(): void {
        $this->queuedRows['filecache'] = [
            [['storage' => 12, 'path' => 'files/Docs/a.pdf', 'id' => 'local::/data/__groupfolders/6/']],
            [['storage' => 12, 'path' => 'files']],
        ];
        $this->rowsByTable['group_folders'] = [['folder_id' => 6, 'root_id' => 950]];

        $this->assertSame(6, $this->service()->getGroupfolderIdForFile(42));
    }

    public function testTrashAndVersionsAreNotInTheTeamFolder(): void {
        // Separate storage, but under versions/ instead of the files/ root.
        $this->queuedRows['filecache'] = [
            [['storage' => 12, 'path' => 'versions/42/v1', 'id' => 'local::/data/__groupfolders/6/']],
            [['storage' => 12, 'path' => 'files']],
        ];
        $this->rowsByTable['group_folders'] = [['folder_id' => 6, 'root_id' => 950]];

        $this->assertNull($this->service()->getGroupfolderIdForFile(42));
    }

    public function testFileOutsideTeamFoldersHasNone(): void {
        $this->queuedRows['filecache'] = [
            [['storage' => 3, 'path' => 'files/notes.txt', 'id' => 'home::alice']],
        ];

        $this->assertNull($this->service()->getGroupfolderIdForFile(42));
    }

    public function testTheRootsOfManyFoldersInTwoQueries(): void {
        $this->rowsByTable = [
            'group_folders' => [['folder_id' => 5, 'root_id' => 900], ['folder_id' => 6, 'root_id' => 950], ['folder_id' => 7]],
            'filecache' => [['fileid' => 900, 'storage' => 1, 'path' => '__groupfolders/5'], ['fileid' => 950, 'storage' => 12, 'path' => 'files']],
        ];
        $service = $this->service();

        $service->primeRoots([5, 6, 6, 0]);

        $this->assertSame(['group_folders', 'filecache'], $this->queriedTables);
        $this->assertSame([['storage' => 1, 'path' => '__groupfolders/5']], $service->getRoots(5));
        $this->assertSame([['storage' => 12, 'path' => 'files']], $service->getRoots(6));
        $this->assertCount(2, $this->queriedTables);
    }

    public function testAPathInsideTheTeamFolder(): void {
        $this->rowsByTable = [
            'group_folders' => [['folder_id' => 6, 'root_id' => 950]],
            'filecache' => [['storage' => 12, 'path' => 'files']],
        ];
        $service = $this->service();

        $this->assertSame('Docs/a.pdf', $service->pathInFolder(6, 12, 'files/Docs/a.pdf'));
        $this->assertSame('', $service->pathInFolder(6, 12, 'files'));
        // Trash, versions, a look-alike name, another storage: not in the folder.
        $this->assertNull($service->pathInFolder(6, 12, 'trash/a.pdf.d123'));
        $this->assertNull($service->pathInFolder(6, 12, 'files_versions/a.pdf'));
        $this->assertNull($service->pathInFolder(6, 13, 'files/Docs/a.pdf'));
    }

    public function testAPathInsideAJailedTeamFolder(): void {
        $this->rowsByTable = [
            'group_folders' => [['folder_id' => 5, 'root_id' => 900]],
            'filecache' => [['storage' => 1, 'path' => '__groupfolders/5']],
        ];
        $service = $this->service();

        $this->assertSame('a.pdf', $service->pathInFolder(5, 1, '__groupfolders/5/a.pdf'));
        $this->assertNull($service->pathInFolder(5, 1, '__groupfolders/50/a.pdf'));
    }

    public function testConditionIsNullWithoutRoots(): void {
        $this->assertNull($this->service()->buildInRootsCondition($this->createMock(IQueryBuilder::class), []));
    }

    public function testConditionEscapesLikeWildcardsInRootPath(): void {
        $likePatterns = [];
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('like')->willReturnCallback(function ($column, $pattern) use (&$likePatterns) {
            $likePatterns[] = $pattern;
            return 'like';
        });
        $expr->method('andX')->willReturn($this->createMock(ICompositeExpression::class));
        $expr->method('orX')->willReturn($this->createMock(ICompositeExpression::class));

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturnArgument(0);

        $condition = $this->service()->buildInRootsCondition($qb, [['storage' => 1, 'path' => '__groupfolders/5']]);

        $this->assertNotNull($condition);
        // "_" is a LIKE wildcard; unescaped it would also match other folders.
        $this->assertSame(['\_\_groupfolders/5/%'], $likePatterns);
    }
}
