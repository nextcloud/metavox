<?php

declare(strict_types=1);

namespace OCA\MetaVox\Search;

use OCA\MetaVox\Access\VisibleFolder;
use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\FileAccessService;
use OCA\MetaVox\Service\SearchIndexService;
use OCA\MetaVox\Service\SearchSettings;
use OCA\MetaVox\Service\UserFieldService;
use OCP\Files\FileInfo;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IPreview;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\FilterDefinition;
use OCP\Search\IFilteringProvider;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;
use Psr\Log\LoggerInterface;

/**
 * IFilteringProvider (and FilterDefinition / IFilter) are @since NC 28, so they
 * exist across the whole supported range (NC 31-34) — safe to implement at the
 * class level. NC renders the native filter chip and forwards the typed filter
 * value to search() via getFilters().
 */
class MetadataSearchProvider implements IProvider, IFilteringProvider {
    private const MIN_SEARCH_LENGTH = 3;
    private const PREVIEW_SIZE = 32;
    private const MAX_SUBLINE_FIELDS = 3;
    /** The most results per page, whatever is asked. */
    private const PAGE_MAX = 100;
    /** Files looked up one by one per request at most, where groupfolders can't tell what the ACL hides. */
    private const LOOKUPS_MAX = 100;
    /** Searches per request at most, when results keep dropping out. */
    private const ROUNDS_MAX = 10;

    /** Unified-search filter names (query-string params and chip ids). */
    private const FILTER_FIELD = 'metavox_field';
    private const FILTER_GROUPFOLDER = 'metavox_groupfolder';
    /** The hint's link: a hash loads no page, and the search bar's script opens the filter (search-main.js). */
    public const NARROW_LINK = '#metavox-filter-by-field';

    /** @var array<string, string>|null Cached field labels */
    private ?array $fieldLabelsCache = null;

    public function __construct(
        private readonly IL10N $l10n,
        private readonly IURLGenerator $urlGenerator,
        private readonly SearchIndexService $searchIndexService,
        private readonly FileAccessService $fileAccess,
        private readonly UserFieldService $userFieldService,
        private readonly IRootFolder $rootFolder,
        private readonly FieldService $fields,
        private readonly IPreview $preview,
        private readonly LoggerInterface $logger,
        private readonly SearchSettings $settings,
    ) {
    }

    public function getId(): string {
        return 'metavox_metadata';
    }

    public function getName(): string {
        // App-branded provider name (mirrors IntraVox's "IntraVox pages"). NC
        // renders the app icon next to it automatically in the location list.
        // TRANSLATORS Name of the search result group in the unified search; MetaVox is the app name, do not translate it
        return $this->l10n->t('MetaVox');
    }

    public function getOrder(string $route, array $routeParameters): int {
        return 60;
    }

    /**
     * Filters this provider understands. 'term' is the built-in free-text
     * filter; the metavox_* ones carry the structured metadata filter.
     */
    public function getSupportedFilters(): array {
        return $this->settings->filterOn() ? ['term', self::FILTER_FIELD, self::FILTER_GROUPFOLDER] : ['term'];
    }

    public function getAlternateIds(): array {
        return [];
    }

    /**
     * Custom filter definitions. The name is what NC uses both as the query
     * param and the advanced inline "name:value" syntax. metavox_field carries
     * the "field:value" expression; metavox_groupfolder scopes it.
     */
    public function getCustomFilters(): array {
        if (!$this->settings->filterOn()) {
            return [];
        }
        return [
            new FilterDefinition(self::FILTER_FIELD, FilterDefinition::TYPE_STRING, false),
            new FilterDefinition(self::FILTER_GROUPFOLDER, FilterDefinition::TYPE_INT, false),
        ];
    }

    public function search(IUser $user, ISearchQuery $query): SearchResult {
        $userId = $user->getUID();

        // A structured filter (chip / query-string) takes precedence over the
        // free-text term. The filter value carries a "field:value" string and,
        // optionally, a groupfolder scope.
        // With the filter off (the admin's choice) every search is free text.
        $filterOn = $this->settings->filterOn();
        [$filterExpr, $groupfolderId] = $filterOn ? $this->readFilters($query) : [null, null];

        // The effective search expression: the explicit filter if present,
        // otherwise the typed term.
        $searchExpr = $filterExpr ?? $query->getTerm();

        if ($searchExpr === null || strlen($searchExpr) < self::MIN_SEARCH_LENGTH) {
            // TRANSLATORS Name of the search result group in the unified search; MetaVox is the app name, do not translate it
            return SearchResult::complete($this->l10n->t('MetaVox'), []);
        }

        $parsed = $filterOn ? self::parseFieldFilter($searchExpr) : null;

        // The user's own team folders only, admins too: a result has to open.
        // A field filter looks only where the field is.
        $folders = $this->searchFolders($userId, $groupfolderId, $parsed['field'] ?? null);

        // A page at a time, as Nextcloud asks, newest file first. The cursor is
        // the last file shown: never a count, which would tell how many the
        // user can't see.
        $limit = max(1, min($query->getLimit(), self::PAGE_MAX));
        $cursor = $query->getCursor();
        $before = is_numeric($cursor) && (int)$cursor > 0 ? (int)$cursor : null;
        $results = [];
        $lastShown = null;
        $more = false;
        $incomplete = false;

        try {
            $fieldLabels = $this->getFieldLabels();

            // The "needle" used to surface the matching field first in the
            // subline: for a field filter it's the value, for free text the term.
            $needle = mb_strtolower($parsed['value'] ?? $searchExpr);

            // One more than the page: only a result the user may see says that a
            // next page exists. Files in a folder groupfolders can't tell about are
            // looked up one by one and may drop out; then more rows are read.
            $after = $before;
            $lookups = 0;
            $rounds = 0;
            do {
                $page = $parsed !== null
                    ? $this->searchIndexService->searchByFieldValue($parsed['field'], $parsed['value'], $folders, $limit + 1 - count($results), $after)
                    : $this->searchIndexService->searchFreeText($searchExpr, $folders, $limit + 1 - count($results), $after);
                $incomplete = $page['incomplete'] ?? false;

                $unchecked = array_column(array_filter($page['files'], fn (array $file) => !$file['checked']), 'id');
                if ($unchecked !== []) {
                    $this->fileAccess->prime($userId, $unchecked);
                    $lookups += count($unchecked);
                }
                foreach ($page['files'] as $file) {
                    $entry = $this->createSearchResultEntry($file, $fieldLabels, $userId, $needle);
                    if ($entry === null) {
                        continue;
                    }
                    if (count($results) === $limit) {
                        $more = true;
                        break 2;
                    }
                    $results[] = $entry;
                    $lastShown = (int)$file['id'];
                }
                $after = $page['next'];
            } while ($after !== null && $lookups < self::LOOKUPS_MAX && ++$rounds < self::ROUNDS_MAX);
        } catch (\Exception $e) {
            $more = false;
            $incomplete = false;
            $this->logger->error('MetaVox search error', [
                'exception' => $e,
                'search_term' => $searchExpr,
                'app' => 'metavox'
            ]);
        }

        if ($more) {
            // TRANSLATORS Name of the search result group in the unified search; MetaVox is the app name, do not translate it
            return SearchResult::paginated($this->l10n->t('MetaVox'), $results, $lastShown);
        }
        // Every word too common to read all its files: say so, rather than end
        // as if this were all.
        if ($incomplete) {
            $hint = $filterOn
                // TRANSLATORS Second line under "Too many matches to search them all", where the search can also filter by a metadata field
                ? $this->l10n->t('Add a word, or filter by field')
                // TRANSLATORS Second line under "Too many matches to search them all"
                : $this->l10n->t('Add a word to narrow the search');
            $results[] = new SearchResultEntry(
                '',
                // TRANSLATORS Last entry of the search results when a word is in too many files to search them all
                $this->l10n->t('Too many matches to search them all'),
                $hint,
                self::NARROW_LINK,
                'icon-info',
                false,
            );
        }
        // TRANSLATORS Name of the search result group in the unified search; MetaVox is the app name, do not translate it
        return SearchResult::complete($this->l10n->t('MetaVox'), $results);
    }

    /**
     * Read the structured metadata filters. Now that we implement
     * IFilteringProvider, NC forwards our registered filters here. Wrapped in
     * try/catch so a malformed filter can never break search — it just falls
     * back to the plain term path. Returns [fieldExpr|null, groupfolderId|null].
     *
     * @return array{0: ?string, 1: ?int}
     */
    private function readFilters(ISearchQuery $query): array {
        $fieldExpr = null;
        $groupfolderId = null;

        try {
            $fieldFilter = $query->getFilter(self::FILTER_FIELD);
            if ($fieldFilter !== null) {
                $val = $fieldFilter->get();
                if (is_string($val) && $val !== '') {
                    $fieldExpr = $val;
                }
            }

            $gfFilter = $query->getFilter(self::FILTER_GROUPFOLDER);
            if ($gfFilter !== null) {
                $gfVal = $gfFilter->get();
                if (is_numeric($gfVal)) {
                    $groupfolderId = (int)$gfVal;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug('MetaVox: filter read skipped', ['exception' => $e]);
        }

        return [$fieldExpr, $groupfolderId];
    }

    /**
     * The user's team folders as they see them, or only the one asked for (none
     * when they are not in it); for a field, only those that have it. None when
     * they can't be read.
     *
     * @return list<VisibleFolder>
     */
    private function searchFolders(string $userId, ?int $groupfolderId, ?string $fieldName = null): array {
        try {
            $folders = $this->userFieldService->getAccessibleGroupfolders($userId);
            if ($groupfolderId !== null) {
                $folders = array_values(array_filter($folders, fn (array $folder) => (int)($folder['id'] ?? 0) === $groupfolderId));
            }
            if ($fieldName !== null) {
                // Before the ACL: each team folder costs a lookup there.
                $withField = array_flip($this->fields->groupfoldersWithField($fieldName, array_map(fn (array $folder) => (int)($folder['id'] ?? 0), $folders)));
                $folders = array_values(array_filter($folders, fn (array $folder) => isset($withField[(int)($folder['id'] ?? 0)])));
            }
            return $this->fileAccess->visibleFolders($userId, $folders);
        } catch (\Throwable $e) {
            $this->logger->warning('MetaVox: accessible groupfolder lookup failed', ['exception' => $e, 'app' => 'metavox']);
            return [];
        }
    }

    /**
     * Parse a "field:value" filter expression. Single source of truth for the
     * inline search syntax, the query-string form (?metavox_field=author:rik)
     * and the search-bar filter chip — they all funnel through here.
     *
     * Pure and static so it can be unit-tested without a DB or NC runtime.
     *
     * Field names are snake_case (metavox_gf_fields.field_name), so \w suffices.
     * The colon splits on the FIRST occurrence, so values may contain colons
     * (e.g. "url:http://example.com" → field "url", value "http://example.com").
     *
     * @return array{field: string, value: string}|null null when not a field filter
     */
    public static function parseFieldFilter(string $raw): ?array {
        if (preg_match('/^(\w+):\s*(.+)$/s', $raw, $matches) !== 1) {
            return null;
        }
        $value = trim($matches[2]);
        if ($value === '') {
            return null;
        }
        return ['field' => $matches[1], 'value' => $value];
    }

    /**
     * Create a search result entry for a file
     */
    private function createSearchResultEntry(array $file, array $fieldLabels, string $userId, string $needle): ?SearchResultEntry {
        try {
            $name = $file['name'];
            $path = $file['path'];
            $mimetype = $file['mimetype'];
            if (!$file['checked']) {
                // The query could not leave out what the ACL hides here: only
                // files the user can open in this team folder are results.
                $access = $this->fileAccess->getFileAccess($userId, (int)$file['id']);
                $node = $access->node;
                if ($node === null || $file['groupfolder_id'] !== $access->groupfolderId) {
                    return null;
                }
                $name = $node->getName();
                $path = $this->rootFolder->getUserFolder($userId)->getRelativePath($node->getPath());
                $mimetype = $node->getMimetype();
            }

            $dir = dirname($path);
            if ($dir === '.') {
                $dir = '/';
            }

            // Disambiguate same-named files by appending the folder to the title.
            $title = $dir === '/'
                ? $name
                : $name . ' — ' . $dir;

            // Show the real file preview where one exists (photos, PDFs, …);
            // otherwise the MetaVox mark, so results read as MetaVox results. The
            // mark goes in as the icon, not the thumbnail: core shows an icon URL
            // as a 20px glyph and inverts the dark one in a dark theme, where a
            // thumbnail would fill the box and keep its colour.
            $metavoxIcon = $this->urlGenerator->imagePath('metavox', 'app-dark.svg');
            $hasPreview = $mimetype !== FileInfo::MIMETYPE_FOLDER && $this->preview->isMimeSupported($mimetype);
            $thumbnailUrl = $hasPreview
                ? $this->urlGenerator->linkToRouteAbsolute('core.preview.getPreviewByFileId', [
                    'fileId' => $file['id'],
                    'x' => self::PREVIEW_SIZE,
                    'y' => self::PREVIEW_SIZE
                ])
                : '';

            return new SearchResultEntry(
                $thumbnailUrl,
                $title,
                $this->formatMetadataSubline($file['metadata'], $fieldLabels, $needle),
                $this->urlGenerator->linkToRouteAbsolute('files.view.index', [
                    'dir' => $dir,
                    'scrollto' => $name,
                    'fileid' => $file['id']
                ]),
                $metavoxIcon, // MetaVox brand icon as the fallback icon
                false // Not rounded
            );
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * field_name => field_label of every field, from the field definitions
     * the search reads anyway.
     *
     * @return array<string, string>
     */
    private function getFieldLabels(): array {
        if ($this->fieldLabelsCache !== null) {
            return $this->fieldLabelsCache;
        }

        $this->fieldLabelsCache = [];

        try {
            foreach ($this->fields->getAllFields() as $field) {
                $this->fieldLabelsCache[(string)$field['field_name']] = (string)$field['field_label'];
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to load field labels', [
                'exception' => $e,
                'app' => 'metavox'
            ]);
        }

        return $this->fieldLabelsCache;
    }

    /**
     * Build the result subline: up to MAX_SUBLINE_FIELDS "Label: value" parts.
     * Fields that match the search come first.
     *
     * @param array<string, string> $metadata
     * @param array<string, string> $fieldLabels
     */
    private function formatMetadataSubline(array $metadata, array $fieldLabels, string $needle): string {
        $matching = [];
        $other = [];
        foreach ($metadata as $fieldName => $value) {
            if ($value === null || (string)$value === '') {
                continue;
            }
            $displayName = $fieldLabels[$fieldName] ?? $fieldName;
            $part = "{$displayName}: {$value}";
            if ($needle !== '' && mb_strpos(mb_strtolower((string)$value), $needle) !== false) {
                $matching[] = $part;
            } else {
                $other[] = $part;
            }
        }
        $parts = array_merge($matching, $other);
        return implode(' • ', array_slice($parts, 0, self::MAX_SUBLINE_FIELDS));
    }
}