<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Listener;

use OCA\MetaVox\Listener\CacheCleanupListener;
use OCA\MetaVox\Service\MetadataVersions;
use OCA\MetaVox\Service\SearchWordsService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Cache\CacheEntryRemovedEvent;
use OCP\Files\Storage\IStorage;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Only removals in team folder storage, cleaned together at the end of the
 * request. NC 34 sends chunk indexes instead of file ids; those must not
 * delete anything.
 */
class CacheCleanupListenerTest extends TestCase {

    private const TEAM_FOLDER_STORAGE = 7;
    private const HOME_STORAGE = 3;
    private const ROOT_STORAGE = 1;

    /** filecache rows that still exist, by file id */
    private array $stillThere = [];
    /** @var string[] tables of the queries, in turn */
    private array $queries = [];
    /** @var list<int[]> file ids of each metadata DELETE */
    private array $deleted = [];
    /** @var list<int[]> files whose search words were removed */
    private array $removedWords = [];

    public function testRemovalsOutsideTeamFoldersCostNothing(): void {
        $listener = $this->listener();

        $this->remove($listener, 42, self::HOME_STORAGE, 'files/Photos/a.jpg');
        $this->remove($listener, 43, self::ROOT_STORAGE, 'appdata_x/preview/1/2/43.png');
        $listener->flush();

        // Only the one lookup of which storages hold team folders.
        $this->assertSame(['storages'], $this->queries);
        $this->assertSame([], $this->deleted);
    }

    public function testRemovalsAreCleanedTogetherAtTheEnd(): void {
        $listener = $this->listener();

        foreach ([42, 43, 44] as $fileId) {
            $this->remove($listener, $fileId, self::TEAM_FOLDER_STORAGE, "files/Team/$fileId.pdf");
        }
        $this->assertSame([], $this->deleted);
        $listener->flush();

        $this->assertSame([[42, 43, 44]], $this->deleted);
        $this->assertSame([[42, 43, 44]], $this->removedWords);
        $this->assertSame(['storages', 'filecache', 'metavox_file_gf_meta'], $this->queries);
    }

    public function testTheOldLayoutCountsByItsPath(): void {
        $listener = $this->listener();

        $this->remove($listener, 42, self::ROOT_STORAGE, '__groupfolders/5/Team/report.pdf');
        $listener->flush();

        $this->assertSame([[42]], $this->deleted);
    }

    public function testManyRemovalsGoInBlocks(): void {
        $listener = $this->listener();

        foreach (range(1, 1200) as $fileId) {
            $this->remove($listener, $fileId, self::TEAM_FOLDER_STORAGE, "files/Team/$fileId.txt");
        }
        $listener->flush();

        $this->assertSame([500, 500, 200], array_map('count', $this->deleted));
    }

    public function testDeletesMetadataWhenFileMovedToAnotherStorage(): void {
        // A cross-storage move keeps the file id and name but changes storage.
        $this->stillThere[42] = ['fileid' => 42, 'storage' => 9, 'name' => 'report.pdf'];

        $this->removeOne(42, 'files/Team/report.pdf');

        $this->assertSame([[42]], $this->deleted);
    }

    public function testIgnoresBogusIdOfFileStillOnTheSameStorage(): void {
        // NC34: the id belongs to another, still existing file on the storage
        // the event claims it was removed from.
        $this->stillThere[42] = ['fileid' => 42, 'storage' => self::TEAM_FOLDER_STORAGE, 'name' => 'report.pdf'];

        $this->removeOne(42, 'files/Team/report.pdf');

        $this->assertSame([], $this->deleted);
        $this->assertSame([], $this->removedWords);
    }

    public function testIgnoresBogusIdOfUnrelatedFileOnAnotherStorage(): void {
        $this->stillThere[42] = ['fileid' => 42, 'storage' => self::HOME_STORAGE, 'name' => 'budget.xlsx'];

        $this->removeOne(42, 'files/Team/report.pdf');

        $this->assertSame([], $this->deleted);
    }

    private function removeOne(int $fileId, string $path): void {
        $listener = $this->listener();
        $this->remove($listener, $fileId, self::TEAM_FOLDER_STORAGE, $path);
        $listener->flush();
    }

    private function remove(CacheCleanupListener $listener, int $fileId, int $storage, string $path): void {
        $listener->handle(new CacheEntryRemovedEvent($this->createMock(IStorage::class), $path, $fileId, $storage));
    }

    private function listener(): CacheCleanupListener {
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturnCallback(fn () => $this->queryBuilder());

        // A swallowed exception would also mean "nothing deleted"; fail loudly
        // instead so the ignore-cases cannot pass by accident.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');

        $words = $this->createMock(SearchWordsService::class);
        $words->method('removeFiles')->willReturnCallback(function (array $fileIds): void {
            $this->removedWords[] = $fileIds;
        });

        $cache = $this->createMock(ICache::class);
        $cache->method('get')->willReturn(null);
        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($cache);

        return new CacheCleanupListener($db, $words, $this->createMock(MetadataVersions::class), $cacheFactory, $logger);
    }

    private function queryBuilder(): IQueryBuilder {
        $qb = $this->createMock(IQueryBuilder::class);
        $table = null;
        $ids = [];

        $qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
        foreach (['select', 'where'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('createNamedParameter')->willReturnCallback(function ($value) use (&$ids) {
            if (\is_array($value)) {
                $ids = $value;
            }
            return ':p';
        });
        $qb->method('from')->willReturnCallback(function (string $from) use ($qb, &$table) {
            $table = $from;
            return $qb;
        });
        $qb->method('delete')->willReturnCallback(function (string $delete) use ($qb, &$table) {
            $table = $delete;
            return $qb;
        });
        $qb->method('executeQuery')->willReturnCallback(function () use (&$table, &$ids) {
            $this->queries[] = $table;
            $result = $this->createMock(IResult::class);
            if ($table === 'storages') {
                $result->method('fetchOne')->willReturnOnConsecutiveCalls(self::TEAM_FOLDER_STORAGE, false);
            } else {
                $rows = array_values(array_intersect_key($this->stillThere, array_flip($ids)));
                $result->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows, false]);
            }
            return $result;
        });
        $qb->method('executeStatement')->willReturnCallback(function () use (&$table, &$ids) {
            $this->queries[] = $table;
            $this->deleted[] = $ids;
            return \count($ids);
        });

        return $qb;
    }
}
