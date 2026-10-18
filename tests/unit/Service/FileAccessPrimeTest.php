<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Service\FileAccessService;
use OCA\MetaVox\Service\GroupfolderResolver;
use OCA\MetaVox\Service\GroupfolderRootService;
use OCA\MetaVox\Service\TeamFolderAccessService;
use OCA\MetaVox\Service\TeamFolderAclService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Mount\IMountPoint;
use OCP\Files\Node;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/** Many files: a folder that holds enough of them is listed once instead of a lookup per file. */
class FileAccessPrimeTest extends TestCase {

    private const TEAM_FOLDER = 9;
    private const FOLDER = 500;

    /** files in folder 500 by id => updateable; what the listing shows */
    private array $listed = [];
    /** how many files folder 500 has in the file cache */
    private int $folderSize = 100;
    private bool $isAdmin = false;
    /** the folder through a share before the team folder one */
    private bool $alsoShared = false;
    /** @var int[] ids looked up one by one */
    private array $lookups = [];
    private int $listings = 0;
    private int $queries = 0;

    public function testAFolderWithEnoughOfTheFilesIsListedOnce(): void {
        $this->listed = [1 => true, 2 => false, 3 => true, 4 => true, 5 => true, 6 => true];
        $service = $this->service();

        $service->prime('alice', [1, 2, 3, 4, 5, 6]);
        $two = $service->getFileAccess('alice', 2);
        $one = $service->getFileAccess('alice', 1);

        $this->assertSame(1, $this->listings);
        $this->assertSame([self::FOLDER], $this->lookups);
        $this->assertSame([self::TEAM_FOLDER, true], [$one->groupfolderId, $one->canWrite]);
        $this->assertSame([self::TEAM_FOLDER, false], [$two->groupfolderId, $two->canWrite]);
    }

    public function testAFewFilesOfABigFolderAreLookedUpOneByOne(): void {
        // 6 of 1000 is below 1 in 50: listing the folder costs more.
        $this->listed = array_fill_keys(range(1, 6), true);
        $this->folderSize = 1000;
        $service = $this->service();

        $service->prime('alice', range(1, 6));
        $service->getFileAccess('alice', 3);

        $this->assertSame(0, $this->listings);
        $this->assertSame([3], $this->lookups);
    }

    public function testFewFilesAreNotWorthAQuery(): void {
        $this->service()->prime('alice', [1, 2, 3, 4]);

        $this->assertSame(0, $this->queries);
    }

    public function testAFileTheListingLeavesOutIsNotThere(): void {
        // File 6 is hidden by the ACL: not in the listing, so not found.
        $this->listed = array_fill_keys(range(1, 5), true);
        $service = $this->service();

        $service->prime('alice', range(1, 6));

        $this->assertFalse($service->getFileAccess('alice', 6)->canRead());
        $this->assertSame([self::FOLDER], $this->lookups);
    }

    public function testAnAdminStillLooksUpWhatTheListingLeavesOut(): void {
        $this->isAdmin = true;
        $this->listed = array_fill_keys(range(1, 5), true);
        $service = $this->service();

        $service->prime('admin', range(1, 6));
        $service->getFileAccess('admin', 6);

        $this->assertSame([self::FOLDER, 6], $this->lookups);
    }

    public function testTheTeamFolderIsListedNotAShare(): void {
        $this->alsoShared = true;
        $this->listed = array_fill_keys(range(1, 6), false);
        $service = $this->service();

        $service->prime('alice', range(1, 6));

        $this->assertSame(1, $this->listings);
        $this->assertFalse($service->getFileAccess('alice', 1)->canWrite);
    }

    private function service(): FileAccessService {
        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('getById')->willReturnCallback(function (int $id): array {
            $this->lookups[] = $id;
            if ($id !== self::FOLDER) {
                return [$this->node($id, true, self::TEAM_FOLDER)];
            }
            $folders = [$this->folder(self::TEAM_FOLDER)];
            if ($this->alsoShared) {
                array_unshift($folders, $this->folder(null));
            }
            return $folders;
        });
        $rootFolder = $this->createMock(IRootFolder::class);
        $rootFolder->method('getUserFolder')->willReturn($userFolder);

        $resolver = $this->createMock(GroupfolderResolver::class);
        $resolver->method('getGroupfolderId')->willReturnCallback(fn (Node $node) => $this->teamFolderOf[spl_object_id($node)] ?? null);

        $teamFolders = $this->createMock(TeamFolderAccessService::class);
        $teamFolders->method('isAdmin')->willReturnCallback(fn () => $this->isAdmin);

        return new FileAccessService($rootFolder, $resolver, $this->createMock(GroupfolderRootService::class),
            $this->createMock(TeamFolderAclService::class), $teamFolders, $this->db());
    }

    /** @var array<int, ?int> team folder per node object */
    private array $teamFolderOf = [];

    private function folder(?int $teamFolder): Folder {
        $folder = $this->createMock(Folder::class);
        $folder->method('getDirectoryListing')->willReturnCallback(function () use ($teamFolder): array {
            if ($teamFolder === null) {
                $this->fail('the share should not be listed');
            }
            $this->listings++;
            $nodes = [];
            foreach ($this->listed as $id => $updateable) {
                $nodes[] = $this->node($id, $updateable, $teamFolder);
            }
            return $nodes;
        });
        $this->teamFolderOf[spl_object_id($folder)] = $teamFolder;
        return $folder;
    }

    private function node(int $id, bool $updateable, int $teamFolder): File {
        $mount = $this->createMock(IMountPoint::class);
        $mount->method('getMountPoint')->willReturn('/alice/files/HR/');
        $node = $this->createMock(File::class);
        $node->method('getId')->willReturn($id);
        $node->method('getPath')->willReturn('/alice/files/HR/f' . $id);
        $node->method('getMountPoint')->willReturn($mount);
        $node->method('isUpdateable')->willReturn($updateable);
        $this->teamFolderOf[spl_object_id($node)] = $teamFolder;
        return $node;
    }

    /** First query: the folder of each asked file; second: the size of those folders. */
    private function db(): IDBConnection {
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturnCallback(function () {
            $asked = [];
            $qb = $this->createMock(IQueryBuilder::class);
            foreach (['select', 'selectAlias', 'from', 'where', 'groupBy'] as $method) {
                $qb->method($method)->willReturnSelf();
            }
            $qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
            $qb->method('func')->willReturn($this->createMock(IFunctionBuilder::class));
            $qb->method('createNamedParameter')->willReturnCallback(function ($value) use (&$asked) {
                $asked = $value;
                return ':p';
            });
            $qb->method('executeQuery')->willReturnCallback(function () use (&$asked) {
                $rows = $this->queries++ === 0
                    ? array_map(fn (int $id) => ['fileid' => $id, 'parent' => self::FOLDER], $asked)
                    : [['parent' => self::FOLDER, 'files' => $this->folderSize]];
                $result = $this->createMock(IResult::class);
                $result->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows, false]);
                return $result;
            });
            return $qb;
        });
        return $db;
    }
}
