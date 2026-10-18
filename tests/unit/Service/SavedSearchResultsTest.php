<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

require_once __DIR__ . '/../../stubs/FilesSearch.php';

use OC\Files\Search\SearchQuery;
use OCA\MetaVox\Access\ReadableScope;
use OCA\MetaVox\Access\VisibleFolder;
use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\FileAccessService;
use OCA\MetaVox\Service\GroupfolderResolver;
use OCA\MetaVox\Service\SavedSearchResults;
use OCA\MetaVox\Service\SearchIndexService;
use OCA\MetaVox\Service\UserFieldService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\Search\ISearchComparison;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

class SavedSearchResultsTest extends TestCase {

    private const SEARCH = ['field' => 'file_gf_status', 'value' => 'Concept', 'groupfolderId' => null];

    /** @var list<array{id: int, mount_point: string}> the user's team folders */
    private array $folders = [
        ['id' => 1, 'mount_point' => 'HR'],
        ['id' => 2, 'mount_point' => 'Sales'],
    ];

    /** @var list<array{id: int, groupfolder_id: int}> what the index finds, newest first */
    private array $hits = [];

    private ?int $next = null;

    /** @var list<array{0: list<array{id: int, groupfolder_id: int}>, 1: ?int}> later pages of the index, in order */
    private array $morePages = [];

    /** @var list<array{0: int, 1: ?int}> count and before of each index read */
    private array $reads = [];

    /** @var array<string, Folder|\Throwable> mount point => the team folder's root */
    private array $roots = [];

    /** @var array<int, int> node id => team folder it is in, for the resolver */
    private array $folderOf = [];

    /** @var list<array{0: string, 1: SearchQuery}> searches run, per mount point */
    private array $searched = [];

    /** @var ?list<VisibleFolder> what the index was asked to search */
    private ?array $searchedFolders = null;

    /** @var ?list<int> the team folders that have the field; null: all of them */
    private ?array $withField = null;

    private function results(): SavedSearchResults {
        $userFields = $this->createMock(UserFieldService::class);
        $userFields->method('getAccessibleGroupfolders')->with('alice')->willReturnCallback(fn () => $this->folders);

        $fileAccess = $this->createMock(FileAccessService::class);
        $fileAccess->method('visibleFolders')->willReturnCallback(fn (string $user, array $folders) => array_map(
            fn (array $folder) => new VisibleFolder($folder['id'], $folder['mount_point'], ReadableScope::exceptPaths([])),
            $folders,
        ));

        $index = $this->createMock(SearchIndexService::class);
        $index->method('searchByFieldValue')->willReturnCallback(function (string $field, string $value, array $folders, int $count, ?int $before) {
            $this->assertSame(['file_gf_status', 'Concept'], [$field, $value]);
            $this->searchedFolders = $folders;
            $this->reads[] = [$count, $before];
            [$hits, $next] = $this->reads === [[$count, $before]] ? [$this->hits, $this->next] : (array_shift($this->morePages) ?? [[], null]);
            return ['files' => array_map(fn (array $hit) => $hit + ['checked' => true], $hits), 'next' => $next];
        });

        $resolver = $this->createMock(GroupfolderResolver::class);
        $resolver->method('getGroupfolderId')->willReturnCallback(fn (Node $node) => $this->folderOf[$node->getId()] ?? null);

        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('get')->willReturnCallback(function (string $path) {
            $root = $this->roots[$path] ?? new NotFoundException();
            if ($root instanceof \Throwable) {
                throw $root;
            }
            return $root;
        });
        $rootFolder = $this->createMock(IRootFolder::class);
        $rootFolder->method('getUserFolder')->with('alice')->willReturn($userFolder);

        $fields = $this->createMock(FieldService::class);
        $fields->method('groupfoldersWithField')->willReturnCallback(function (string $field, array $ids) {
            $this->assertSame('file_gf_status', $field);
            return $this->withField === null ? $ids : array_values(array_intersect($ids, $this->withField));
        });

        return new SavedSearchResults($userFields, $fileAccess, $index, $resolver, $rootFolder, $fields);
    }

    private function user(): IUser {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        return $user;
    }

    /**
     * The team folder's root, whose search hands out these nodes.
     *
     * @param list<Node> $nodes
     */
    private function teamFolder(string $mountPoint, int $folderId, array $nodes): void {
        $root = $this->createMock(Folder::class);
        $root->method('getId')->willReturn(1000 + $folderId);
        $this->folderOf[1000 + $folderId] = $folderId;
        $root->method('search')->willReturnCallback(function (SearchQuery $query) use ($mountPoint, $nodes) {
            $this->searched[] = [$mountPoint, $query];
            $ids = $query->getSearchOperation()->getValue();
            return array_values(array_filter($nodes, fn (Node $node) => in_array($node->getId(), $ids, true)));
        });
        $this->roots[$mountPoint] = $root;
    }

    private function file(int $id, int $folderId): File {
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn($id);
        $this->folderOf[$id] = $folderId;
        return $file;
    }

    /** @param list<Node> $nodes */
    private static function ids(array $nodes): array {
        return array_map(fn (Node $node) => $node->getId(), $nodes);
    }

    public function testNewestFirstAcrossTeamFolders(): void {
        $this->hits = [['id' => 30, 'groupfolder_id' => 2], ['id' => 20, 'groupfolder_id' => 1], ['id' => 10, 'groupfolder_id' => 2]];
        $this->teamFolder('HR', 1, [$this->file(20, 1)]);
        $this->teamFolder('Sales', 2, [$this->file(10, 2), $this->file(30, 2)]);

        $found = $this->results()->find($this->user(), self::SEARCH, 500);

        $this->assertSame([30, 20, 10], self::ids($found['nodes']));
        $this->assertFalse($found['more']);
    }

    public function testOneSearchPerTeamFolderForItsOwnFiles(): void {
        $this->hits = [['id' => 30, 'groupfolder_id' => 2], ['id' => 20, 'groupfolder_id' => 1], ['id' => 10, 'groupfolder_id' => 2]];
        $this->teamFolder('HR', 1, [$this->file(20, 1)]);
        $this->teamFolder('Sales', 2, [$this->file(10, 2), $this->file(30, 2)]);

        $this->results()->find($this->user(), self::SEARCH, 500);

        $this->assertSame(['Sales', 'HR'], array_column($this->searched, 0));
        [, $query] = $this->searched[0];
        $comparison = $query->getSearchOperation();
        $this->assertSame([ISearchComparison::COMPARE_IN, 'fileid', [30, 10]], [$comparison->getType(), $comparison->getField(), $comparison->getValue()]);
        $this->assertSame('alice', $query->getUser()->getUID());
        $this->assertSame([0, 0, false], [$query->getLimit(), $query->getOffset(), $query->limitToHome()]);
        // A listing's upload and creation time, on Nextcloud 35.
        $this->assertSame(['creation_time', 'upload_time'], $query->getSelectFields());
    }

    public function testWhatNextcloudsSearchLeavesOutStaysOut(): void {
        // The ACL hides 20 after all, or the file is gone.
        $this->hits = [['id' => 30, 'groupfolder_id' => 1], ['id' => 20, 'groupfolder_id' => 1]];
        $this->teamFolder('HR', 1, [$this->file(30, 1)]);

        $this->assertSame([30], self::ids($this->results()->find($this->user(), self::SEARCH, 500)['nodes']));
    }

    public function testNeverTheCopyOfAShare(): void {
        // The search hands out a node that is not on the team folder's mount.
        $this->hits = [['id' => 30, 'groupfolder_id' => 1], ['id' => 20, 'groupfolder_id' => 1]];
        $shared = $this->createMock(File::class);
        $shared->method('getId')->willReturn(20);
        $this->teamFolder('HR', 1, [$this->file(30, 1), $shared]);
        unset($this->folderOf[20]);

        $this->assertSame([30], self::ids($this->results()->find($this->user(), self::SEARCH, 500)['nodes']));
    }

    public function testATeamFolderThatIsNotMountedFindsNothing(): void {
        // An admin who is not a member: Nextcloud has no mount for them.
        $this->hits = [['id' => 30, 'groupfolder_id' => 2], ['id' => 20, 'groupfolder_id' => 1]];
        $this->teamFolder('HR', 1, [$this->file(20, 1)]);

        $this->assertSame([20], self::ids($this->results()->find($this->user(), self::SEARCH, 500)['nodes']));
        $this->assertSame(['HR'], array_column($this->searched, 0));
    }

    public function testAMountPointThatIsAnotherFolderFindsNothing(): void {
        // A folder of the user's own named like the team folder, or another one.
        $this->hits = [['id' => 20, 'groupfolder_id' => 1]];
        $this->teamFolder('HR', 2, [$this->file(20, 1)]);

        $this->assertSame([], $this->results()->find($this->user(), self::SEARCH, 500)['nodes']);
        $this->assertSame([], $this->searched);
    }

    public function testAFileIsNoTeamFolder(): void {
        $this->hits = [['id' => 20, 'groupfolder_id' => 1]];
        $this->roots['HR'] = $this->file(1001, 1);

        $this->assertSame([], $this->results()->find($this->user(), self::SEARCH, 500)['nodes']);
    }

    public function testOnlyTheTeamFolderOfTheSearch(): void {
        $this->hits = [['id' => 20, 'groupfolder_id' => 2]];
        $this->teamFolder('Sales', 2, [$this->file(20, 2)]);

        $this->results()->find($this->user(), ['groupfolderId' => 2] + self::SEARCH, 500);

        $this->assertSame([2], array_map(fn (VisibleFolder $folder) => $folder->id, $this->searchedFolders));
    }

    public function testOnlyTeamFoldersThatHaveTheField(): void {
        // Sales has no Status: no ACL lookup there, no part of the index.
        $this->withField = [1];

        $this->results()->find($this->user(), self::SEARCH, 500);

        $this->assertSame([1], array_map(fn (VisibleFolder $folder) => $folder->id, $this->searchedFolders));
    }

    public function testATeamFolderTheyAreNotInFindsNothing(): void {
        $this->results()->find($this->user(), ['groupfolderId' => 7] + self::SEARCH, 500);

        $this->assertSame([], $this->searchedFolders);
    }

    public function testAsksOneMoreThanItShows(): void {
        $this->results()->find($this->user(), self::SEARCH, 3);
        $this->results()->find($this->user(), self::SEARCH, 500);

        $this->assertSame([[4, null], [501, null]], $this->reads);
    }

    public function testSaysMoreWhenAVisibleFileIsLeftOver(): void {
        $this->hits = [['id' => 30, 'groupfolder_id' => 1], ['id' => 20, 'groupfolder_id' => 1], ['id' => 10, 'groupfolder_id' => 1]];
        $this->next = 10;
        $this->teamFolder('HR', 1, [$this->file(30, 1), $this->file(20, 1), $this->file(10, 1)]);

        $found = $this->results()->find($this->user(), self::SEARCH, 2);

        $this->assertSame([30, 20], self::ids($found['nodes']));
        $this->assertTrue($found['more']);
        $this->assertCount(1, $this->reads);
    }

    public function testHiddenFilesNeverSayMore(): void {
        // A folder whose ACL the query could not apply: Nextcloud drops 20 and
        // 10, and nothing older is there. The index said more; the user sees no more.
        $this->hits = [['id' => 30, 'groupfolder_id' => 1], ['id' => 20, 'groupfolder_id' => 1], ['id' => 10, 'groupfolder_id' => 1]];
        $this->next = 10;
        $this->teamFolder('HR', 1, [$this->file(30, 1)]);

        $found = $this->results()->find($this->user(), self::SEARCH, 2);

        $this->assertSame([30], self::ids($found['nodes']));
        $this->assertFalse($found['more']);
        $this->assertSame([[3, null], [2, 10]], $this->reads);
    }

    public function testHiddenFilesDoNotPushOutVisibleOnes(): void {
        // The newest matches are all hidden: older ones the user sees still show.
        $this->hits = [['id' => 50, 'groupfolder_id' => 1], ['id' => 40, 'groupfolder_id' => 1], ['id' => 30, 'groupfolder_id' => 1]];
        $this->next = 30;
        $this->morePages = [[[['id' => 20, 'groupfolder_id' => 1], ['id' => 10, 'groupfolder_id' => 1]], null]];
        $this->teamFolder('HR', 1, [$this->file(20, 1), $this->file(10, 1)]);

        $found = $this->results()->find($this->user(), self::SEARCH, 2);

        $this->assertSame([20, 10], self::ids($found['nodes']));
        $this->assertFalse($found['more']);
        $this->assertSame([[3, null], [3, 30]], $this->reads);
    }

    public function testReadsTenRoundsAtMost(): void {
        // Everything the index finds is hidden, page after page.
        $this->hits = [['id' => 1000, 'groupfolder_id' => 1]];
        $this->next = 1000;
        for ($id = 999; $id > 900; $id--) {
            $this->morePages[] = [[['id' => $id, 'groupfolder_id' => 1]], $id];
        }
        $this->teamFolder('HR', 1, []);

        $found = $this->results()->find($this->user(), self::SEARCH, 500);

        $this->assertSame(['nodes' => [], 'more' => false], $found);
        $this->assertCount(10, $this->reads);
    }

    public function testAnUnreadableTeamFolderRootFindsNothing(): void {
        $this->hits = [['id' => 20, 'groupfolder_id' => 1]];
        $this->roots['HR'] = new NotPermittedException();

        $this->assertSame(['nodes' => [], 'more' => false], $this->results()->find($this->user(), self::SEARCH, 500));
        $this->assertSame([], $this->searched);
    }

    public function testNoHitsNoSearch(): void {
        $found = $this->results()->find($this->user(), self::SEARCH, 500);

        $this->assertSame(['nodes' => [], 'more' => false], $found);
        $this->assertSame([], $this->searched);
    }
}
