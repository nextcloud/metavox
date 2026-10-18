<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Search;

use OCA\MetaVox\Access\ReadableScope;
use OCA\MetaVox\Access\VisibleFolder;
use OCA\MetaVox\Search\MetadataSearchProvider;
use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\FileAccess;
use OCA\MetaVox\Service\FileAccessService;
use OCA\MetaVox\Service\SearchIndexService;
use OCA\MetaVox\Service\SearchSettings;
use OCA\MetaVox\Service\UserFieldService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IPreview;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IFilter;
use OCP\Search\ISearchQuery;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Search results: only the user's own team folders, a page at a time, and
 * built from the query's rows unless the file has to be looked up.
 */
class MetadataSearchResultsTest extends TestCase {

    private SearchIndexService $index;
    private FileAccessService $access;
    /** @var list<array{0: int, 1: ?int}> count and file id before, of each search asked */
    private array $asked = [];

    /** The admin's switch for the filter in the search bar. */
    private bool $filterOn = true;

    /** @var ?list<int> the team folders that have the filtered field; null: all of them */
    private ?array $withField = null;

    protected function setUp(): void {
        $this->index = $this->createMock(SearchIndexService::class);
        $this->access = $this->createMock(FileAccessService::class);
        // As the user sees them: 5 without ACL, 6 where groupfolders can't tell.
        $this->access->method('visibleFolders')->willReturnCallback(fn (string $user, array $folders) => array_map(
            fn (array $f) => new VisibleFolder((int)$f['id'], 'HR', (int)$f['id'] === 6 ? null : ReadableScope::everything()),
            $folders,
        ));
    }

    public function testAFieldSearchLooksInTheUsersTeamFoldersAsTheySeeThem(): void {
        $this->access->expects($this->once())->method('visibleFolders')->with('alice', [['id' => 5], ['id' => 6]]);
        // One more than the page of 10: whether a next page exists.
        $this->index->expects($this->once())->method('searchByFieldValue')
            ->with('status', 'Draft', $this->callback(fn (array $folders) => array_map(fn ($f) => $f->id, $folders) === [5, 6]), 11, null)
            ->willReturn(['files' => [], 'next' => null]);

        $this->search('status:Draft', asFilter: true);
    }

    public function testWithTheFilterOffAFilterIsJustText(): void {
        // The admin turned the filter off: the chip's parameters and the
        // field:value syntax count for nothing, the words are searched.
        $this->filterOn = false;
        // The team folder of the chip counts for nothing either.
        $this->access->expects($this->exactly(2))->method('visibleFolders')->with('alice', [['id' => 5], ['id' => 6]]);
        $this->index->expects($this->never())->method('searchByFieldValue');
        $this->index->expects($this->exactly(2))->method('searchFreeText')
            ->with('status:Draft', $this->anything(), 11, null)->willReturn(['files' => [], 'next' => null]);

        $this->search('status:Draft', 5, true);
        $this->search('status:Draft');
    }

    public function testWithTheFilterOffNextcloudOffersNoFilter(): void {
        $this->filterOn = false;

        $this->assertSame(['term'], $this->provider()->getSupportedFilters());
        $this->assertSame([], $this->provider()->getCustomFilters());

        $this->filterOn = true;
        $this->assertSame(['term', 'metavox_field', 'metavox_groupfolder'], $this->provider()->getSupportedFilters());
        $this->assertCount(2, $this->provider()->getCustomFilters());
    }

    public function testAFieldSearchLooksOnlyWhereTheFieldIs(): void {
        // 6 has no Status: no ACL lookup there, no part of the index.
        $this->withField = [5];
        $this->access->expects($this->once())->method('visibleFolders')->with('alice', [['id' => 5]]);
        $this->index->method('searchByFieldValue')->willReturn(['files' => [], 'next' => null]);

        $this->search('status:Draft', asFilter: true);
    }

    public function testFreeTextLooksInEveryTeamFolder(): void {
        $this->withField = [];
        $this->access->expects($this->once())->method('visibleFolders')->with('alice', [['id' => 5], ['id' => 6]]);
        $this->index->method('searchFreeText')->willReturn(['files' => [], 'next' => null]);

        $this->search('jaarverslag');
    }

    public function testOnlyTheAskedTeamFolder(): void {
        $this->access->expects($this->once())->method('visibleFolders')->with('alice', [['id' => 5]]);
        $this->index->method('searchByFieldValue')->willReturn(['files' => [], 'next' => null]);

        $this->search('status:Draft', 5, true);
    }

    public function testATeamFolderOfSomeoneElseFindsNothing(): void {
        $this->access->expects($this->once())->method('visibleFolders')->with('alice', []);
        $this->index->expects($this->once())->method('searchFreeText')->with('jaarverslag', [], 11, null)->willReturn(['files' => [], 'next' => null]);

        $this->assertSame([], $this->search('jaarverslag', 7)['entries']);
    }

    public function testThePageGoesOnAfterTheLastFileShown(): void {
        $this->index->expects($this->once())->method('searchByFieldValue')
            ->with('status', 'Draft', $this->anything(), 11, 120)
            ->willReturn(['files' => $this->rows(11, 110), 'next' => 100]);

        $result = $this->search('status:Draft', asFilter: true, cursor: 120);

        $this->assertTrue($result['isPaginated']);
        $this->assertCount(10, $result['entries']);
        // File 101 is the tenth shown; 100 only said that more follow.
        $this->assertSame(101, $result['cursor']);
    }

    public function testTheLastPageSaysThereIsNoMore(): void {
        $this->index->method('searchFreeText')->willReturn(['files' => $this->rows(3), 'next' => null]);

        $result = $this->search('jaarverslag');

        $this->assertFalse($result['isPaginated']);
        $this->assertCount(3, $result['entries']);
    }

    public function testTooCommonWordsEndWithAHintThatOpensTheFilter(): void {
        $this->index->method('searchFreeText')->willReturn(['files' => $this->rows(3), 'next' => null, 'incomplete' => true]);

        $result = $this->search('dossier 100');

        $this->assertFalse($result['isPaginated']);
        $this->assertCount(4, $result['entries']);
        $hint = $result['entries'][3]->jsonSerialize();
        $this->assertSame('Too many matches to search them all', $hint['title']);
        $this->assertSame('Add a word, or filter by field', $hint['subline']);
        $this->assertSame(MetadataSearchProvider::NARROW_LINK, $hint['resourceUrl']);
    }

    public function testWithTheFilterOffTheHintOnlySaysAddAWord(): void {
        $this->filterOn = false;
        $this->index->method('searchFreeText')->willReturn(['files' => [], 'next' => null, 'incomplete' => true]);

        $entries = $this->search('dossier 100')['entries'];

        $this->assertCount(1, $entries);
        $this->assertSame('Add a word to narrow the search', $entries[0]->jsonSerialize()['subline']);
    }

    public function testTheHintWaitsForTheLastPage(): void {
        $this->index->method('searchFreeText')->willReturn(['files' => $this->rows(11), 'next' => 900, 'incomplete' => true]);

        $result = $this->search('dossier');

        $this->assertTrue($result['isPaginated']);
        $this->assertCount(10, $result['entries']);
    }

    public function testAFullPageWithNothingAfterItSaysThereIsNoMore(): void {
        $this->index->method('searchFreeText')->willReturn(['files' => $this->rows(10), 'next' => null]);

        $this->assertFalse($this->search('jaarverslag')['isPaginated']);
    }

    public function testNoMoreThanAHundredPerPage(): void {
        $this->index->expects($this->once())->method('searchFreeText')->with('jaarverslag', $this->anything(), 101, null)
            ->willReturn(['files' => [], 'next' => null]);

        $this->search('jaarverslag', limit: 500);
    }

    public function testAResultTheQueryCheckedNeedsNoLookup(): void {
        $this->access->expects($this->never())->method('getFileAccess');
        $this->index->method('searchByFieldValue')->willReturn(['files' => [
            $this->row(10, 'a.pdf', '/HR/Notulen/a.pdf', 'application/pdf'),
            $this->row(11, 'Notulen', '/HR/Notulen', 'httpd/unix-directory'),
        ], 'next' => null]);

        $entries = $this->search('status:Draft', asFilter: true, previews: ['application/pdf'])['entries'];

        $this->assertSame('a.pdf — /HR/Notulen', $entries[0]->jsonSerialize()['title']);
        $this->assertSame('Status: Draft', $entries[0]->jsonSerialize()['subline']);
        $this->assertSame('preview', $entries[0]->jsonSerialize()['thumbnailUrl']);
        $this->assertSame('Notulen — /HR', $entries[1]->jsonSerialize()['title']);
        // No preview: the mark as the icon, which core shows as a glyph and
        // inverts in a dark theme, not as a thumbnail that fills the box.
        $this->assertSame('', $entries[1]->jsonSerialize()['thumbnailUrl']);
        $this->assertSame('/icon.svg', $entries[1]->jsonSerialize()['icon']);
    }

    public function testAResultInAFolderGroupfoldersCantTellAboutIsLookedUp(): void {
        $this->index->method('searchByFieldValue')->willReturn(['files' => [
            $this->row(10, 'a.pdf', '/HR/a.pdf', 'application/pdf', false),
            // A row left from another team folder, e.g. after a move.
            ['groupfolder_id' => 7] + $this->row(11, 'b.pdf', '/HR/b.pdf', 'application/pdf', false),
        ], 'next' => null]);
        $this->access->expects($this->once())->method('prime')->with('alice', [10, 11]);
        $this->access->method('getFileAccess')->willReturn(new FileAccess(6, false, $this->file('a.pdf')));

        $entries = $this->search('status:Draft', asFilter: true)['entries'];

        $this->assertCount(1, $entries);
        $this->assertSame('a.pdf — /HR', $entries[0]->jsonSerialize()['title']);
    }

    /**
     * Files 99 and 98 are hidden from the user. The cursor is the last file
     * shown, so it can't be used to count them (review 09-10).
     */
    public function testTheCursorTellsNothingAboutHiddenFiles(): void {
        $this->index->method('searchFreeText')->willReturnCallback(function (string $term, array $folders, int $count, ?int $before) {
            $this->asked[] = [$count, $before];
            return match ($before) {
                null => ['files' => [$this->row(100, 'a.txt', '/HR/a.txt', 'text/plain'), $this->row(99, 'x.txt', '/HR/x.txt', 'text/plain', false)], 'next' => 99],
                99 => ['files' => [$this->row(98, 'y.txt', '/HR/y.txt', 'text/plain', false)], 'next' => 98],
                98 => ['files' => [$this->row(97, 'b.txt', '/HR/b.txt', 'text/plain')], 'next' => 97],
            };
        });
        $this->access->method('getFileAccess')->willReturn(FileAccess::none());

        $result = $this->search('ontslag', limit: 1);

        $this->assertSame([[2, null], [1, 99], [1, 98]], $this->asked);
        $this->assertCount(1, $result['entries']);
        $this->assertTrue($result['isPaginated']);
        $this->assertSame(100, $result['cursor']);
    }

    /** Only hidden files after the last one shown: no next page either. */
    public function testOnlyHiddenFilesLeftIsTheEnd(): void {
        $this->index->method('searchFreeText')->willReturnOnConsecutiveCalls(
            ['files' => [$this->row(100, 'a.txt', '/HR/a.txt', 'text/plain'), $this->row(99, 'x.txt', '/HR/x.txt', 'text/plain', false)], 'next' => 99],
            ['files' => [$this->row(98, 'y.txt', '/HR/y.txt', 'text/plain', false)], 'next' => null],
        );
        $this->access->method('getFileAccess')->willReturn(FileAccess::none());

        $result = $this->search('ontslag', limit: 1);

        $this->assertFalse($result['isPaginated']);
        $this->assertCount(1, $result['entries']);
    }

    /** One by one costs ~1.5 ms a file: a request stops after a hundred, without a next page. */
    public function testLookupsOneByOneAreBounded(): void {
        $this->index->method('searchByFieldValue')->willReturnCallback(function (string $field, string $value, array $folders, int $count, ?int $before) {
            $from = $before ?? 10000;
            $files = array_map(fn (int $id) => $this->row($id, "x$id.txt", "/HR/x$id.txt", 'text/plain', false), range($from - 1, $from - $count));
            return ['files' => $files, 'next' => $from - $count];
        });
        $this->access->method('getFileAccess')->willReturn(FileAccess::none());

        $result = $this->search('status:Draft', asFilter: true);

        $this->assertSame([], $result['entries']);
        $this->assertFalse($result['isPaginated']);
    }

    /** Rows the service drops (a value changed since it was indexed) cost a round each: at most ten. */
    public function testRoundsThatFindNothingAreBounded(): void {
        $calls = 0;
        $this->index->method('searchByFieldValue')->willReturnCallback(function (string $field, string $value, array $folders, int $count, ?int $before) use (&$calls) {
            $calls++;
            return ['files' => [], 'next' => ($before ?? 10000) - 11];
        });

        $result = $this->search('status:Draft', asFilter: true);

        $this->assertSame(10, $calls);
        $this->assertFalse($result['isPaginated']);
    }

    public function testAFailingSearchEndsWithoutMore(): void {
        $this->index->method('searchFreeText')->willThrowException(new \RuntimeException('database gone'));

        $result = $this->search('jaarverslag');

        $this->assertFalse($result['isPaginated']);
        $this->assertSame([], $result['entries']);
    }

    /** @return list<array<string, mixed>> */
    private function rows(int $n, int $from = 1000): array {
        return array_map(fn (int $i) => $this->row($i, "f$i.txt", "/HR/f$i.txt", 'text/plain'), range($from, $from - $n + 1));
    }

    /** @return array<string, mixed> */
    private function row(int $id, string $name, string $path, string $mimetype, bool $checked = true): array {
        return ['id' => $id, 'name' => $name, 'path' => $path, 'mimetype' => $mimetype, 'groupfolder_id' => $checked ? 5 : 6,
            'metadata' => ['status' => 'Draft'], 'checked' => $checked];
    }

    private function file(string $name): File {
        $file = $this->createMock(File::class);
        $file->method('getName')->willReturn($name);
        $file->method('getPath')->willReturn('/alice/files/HR/' . $name);
        $file->method('getMimetype')->willReturn('application/pdf');
        return $file;
    }

    /**
     * @param list<string> $previews mime types with a preview
     * @return array{isPaginated: bool, entries: list<\OCP\Search\SearchResultEntry>, cursor: mixed}
     */
    private function search(string $term, ?int $groupfolderId = null, bool $asFilter = false, int $cursor = 0, int $limit = 10, array $previews = []): array {
        $folders = $this->createMock(UserFieldService::class);
        $folders->method('getAccessibleGroupfolders')->willReturn([['id' => 5], ['id' => 6]]);
        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('getRelativePath')->willReturnCallback(fn (string $path) => substr($path, strlen('/alice/files')));
        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturn($userFolder);
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnArgument(0);
        $urls = $this->createMock(IURLGenerator::class);
        $urls->method('linkToRouteAbsolute')->willReturnCallback(fn (string $route) => str_starts_with($route, 'core.preview') ? 'preview' : 'https://cloud/f');
        $urls->method('imagePath')->willReturn('/icon.svg');
        $preview = $this->createMock(IPreview::class);
        $preview->method('isMimeSupported')->willReturnCallback(fn (string $mime) => in_array($mime, $previews, true));

        $fields = $this->createMock(FieldService::class);
        $fields->method('getAllFields')->willReturn([['field_name' => 'status', 'field_label' => 'Status']]);
        $fields->method('groupfoldersWithField')->willReturnCallback(fn (string $field, array $ids) => $this->withField === null ? $ids : array_values(array_intersect($ids, $this->withField)));

        $provider = $this->provider($l, $urls, $folders, $root, $fields, $preview);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $query = $this->createMock(ISearchQuery::class);
        $query->method('getTerm')->willReturn($term);
        $query->method('getLimit')->willReturn($limit);
        $query->method('getCursor')->willReturn($cursor === 0 ? null : $cursor);
        // As the picker sends it: the field as a filter chip, the folder beside it.
        $filters = array_filter([
            'metavox_groupfolder' => $groupfolderId !== null ? (string)$groupfolderId : null,
            'metavox_field' => $asFilter ? $term : null,
        ], fn($value) => $value !== null);
        $query->method('getFilter')->willReturnCallback(function (string $name) use ($filters) {
            if (!isset($filters[$name])) {
                return null;
            }
            $filter = $this->createMock(IFilter::class);
            $filter->method('get')->willReturn($filters[$name]);
            return $filter;
        });

        return $provider->search($user, $query)->jsonSerialize();
    }

    private function provider(?IL10N $l = null, ?IURLGenerator $urls = null, ?UserFieldService $folders = null, ?IRootFolder $root = null, ?FieldService $fields = null, ?IPreview $preview = null): MetadataSearchProvider {
        $settings = $this->createMock(SearchSettings::class);
        $settings->method('filterOn')->willReturnCallback(fn () => $this->filterOn);
        return new MetadataSearchProvider($l ?? $this->createMock(IL10N::class), $urls ?? $this->createMock(IURLGenerator::class), $this->index, $this->access,
            $folders ?? $this->createMock(UserFieldService::class), $root ?? $this->createMock(IRootFolder::class), $fields ?? $this->createMock(FieldService::class),
            $preview ?? $this->createMock(IPreview::class), $this->createMock(LoggerInterface::class), $settings);
    }
}
