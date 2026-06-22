<?php

declare(strict_types=1);

namespace OCA\MetaVox\Search;

use OCA\MetaVox\Service\PermissionService;
use OCA\MetaVox\Service\SearchIndexService;
use OCA\MetaVox\Service\UserFieldService;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IDBConnection;
use OCP\IGroupManager;
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
    // Result caps in SearchIndexService: field search 100, free-text 50.
    private const RESULT_CAP_FIELD = 100;
    private const RESULT_CAP_TEXT = 50;

    /** Unified-search filter names (query-string params and chip ids). */
    private const FILTER_FIELD = 'metavox_field';
    private const FILTER_GROUPFOLDER = 'metavox_groupfolder';

    /** @var array<string, string>|null Cached field labels */
    private ?array $fieldLabelsCache = null;

    public function __construct(
        private readonly IL10N $l10n,
        private readonly IURLGenerator $urlGenerator,
        private readonly SearchIndexService $searchIndexService,
        private readonly PermissionService $permissionService,
        private readonly UserFieldService $userFieldService,
        private readonly IGroupManager $groupManager,
        private readonly IRootFolder $rootFolder,
        private readonly IDBConnection $db,
        private readonly IPreview $preview,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getId(): string {
        return 'metavox_metadata';
    }

    public function getName(): string {
        // App-branded provider name (mirrors IntraVox's "IntraVox pages"). NC
        // renders the app icon next to it automatically in the location list.
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
        return ['term', self::FILTER_FIELD, self::FILTER_GROUPFOLDER];
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
        [$filterExpr, $groupfolderId] = $this->readFilters($query);

        // The effective search expression: the explicit filter if present,
        // otherwise the typed term.
        $searchExpr = $filterExpr ?? $query->getTerm();

        if ($searchExpr === null || strlen($searchExpr) < self::MIN_SEARCH_LENGTH) {
            return SearchResult::complete($this->l10n->t('MetaVox'), []);
        }

        // If a groupfolder scope is requested, the user must be allowed to view
        // metadata in it; otherwise drop the scope to a safe, unscoped search
        // (per-result verifyFileAccess still gates every emitted file).
        if ($groupfolderId !== null
            && !$this->permissionService->hasPermission($userId, PermissionService::PERM_VIEW_METADATA, $groupfolderId)) {
            $groupfolderId = null;
        }

        $results = [];
        $userFolder = $this->rootFolder->getUserFolder($userId);

        // Folder scope for the field-search path: restrict to the user's
        // accessible groupfolders BEFORE the result cap. Admins see everything,
        // so pass null (no SQL IN-filter) rather than a potentially huge list.
        $allowedGfIds = $this->getAllowedGroupfolderIds($userId);

        try {
            $files = $this->performSearch($searchExpr, $userId, $groupfolderId, $allowedGfIds);
            $fieldLabels = $this->getFieldLabels();

            // The "needle" used to surface the matching field first in the
            // subline: for a field filter it's the value, for free text the term.
            $parsed = self::parseFieldFilter($searchExpr);
            $needle = mb_strtolower($parsed['value'] ?? $searchExpr);

            // Resolve the groupfolder per result so per-field view permissions
            // can be applied per folder. The field-search path already carries
            // groupfolder_id per row; the free-text path doesn't, so batch-load
            // it for all result files in one query (no N+1).
            $gfByFile = $this->resolveGroupfolderIds($files);

            foreach ($files as $file) {
                $gfId = $file['groupfolder_id'] ?? ($gfByFile[$file['id']] ?? $groupfolderId);
                $entry = $this->createSearchResultEntry($file, $userFolder, $fieldLabels, $userId, $gfId, $needle);
                if ($entry !== null) {
                    $results[] = $entry;
                }
            }

            // The service caps results (100 field / 50 free-text). When the cap
            // is hit some matches are not shown; surface that for diagnostics.
            // (Kept out of the UI on purpose: NC re-queries paginated providers
            // with a cursor we don't honour, which would risk a load-more loop.)
            $cap = $parsed !== null ? self::RESULT_CAP_FIELD : self::RESULT_CAP_TEXT;
            if (count($files) >= $cap) {
                $this->logger->debug('MetaVox search hit the result cap; some matches are not shown', [
                    'search_term' => $searchExpr,
                    'app' => 'metavox',
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('MetaVox search error', [
                'exception' => $e,
                'search_term' => $searchExpr,
                'app' => 'metavox'
            ]);
        }

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
     * Perform search based on search term format
     *
     * @return array<array{id: int, name: string, metadata: array}>
     */
    private function performSearch(string $searchTerm, string $userId, ?int $groupfolderId = null, ?array $allowedGroupfolderIds = null): array {
        // Field-specific search via the shared "field:value" parser.
        $parsed = self::parseFieldFilter($searchTerm);
        if ($parsed !== null) {
            return $this->searchIndexService->searchByFieldValue(
                $parsed['field'],
                $parsed['value'],
                $userId,
                $groupfolderId,
                $allowedGroupfolderIds
            );
        }

        return $this->searchIndexService->searchFilesByMetadata($searchTerm, $userId);
    }

    /**
     * The groupfolder ids the user may see, for scoping the field search before
     * the result cap. Returns null for admins (no restriction) and on any
     * failure (fall back to the per-result verifyFileAccess gate rather than
     * accidentally filtering everything out).
     *
     * @return int[]|null
     */
    private function getAllowedGroupfolderIds(string $userId): ?array {
        if ($this->groupManager->isAdmin($userId)) {
            return null;
        }
        try {
            $folders = $this->userFieldService->getAccessibleGroupfolders($userId);
            $ids = [];
            foreach ($folders as $folder) {
                $id = (int)($folder['id'] ?? 0);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
            // No accessible folders → no field results (correct: no access).
            // A non-empty list scopes the search. Never return [] as "match all".
            return $ids;
        } catch (\Throwable $e) {
            $this->logger->warning('MetaVox: accessible groupfolder lookup failed; skipping folder scope', [
                'exception' => $e,
                'app' => 'metavox',
            ]);
            return null; // safe fallback: rely on verifyFileAccess per result
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
    private function createSearchResultEntry(array $file, $userFolder, array $fieldLabels, string $userId, ?int $groupfolderId, string $needle): ?SearchResultEntry {
        try {
            $node = $this->verifyFileAccess($file['id'], $userFolder);
            if ($node === null) {
                return null;
            }

            $relativePath = $userFolder->getRelativePath($node->getPath());
            $dir = dirname($relativePath);
            if ($dir === '.') {
                $dir = '/';
            }

            // Disambiguate same-named files by appending the folder to the title.
            $title = $dir === '/'
                ? $file['name']
                : $file['name'] . ' — ' . $dir;

            // Show the real file preview where one exists (photos, PDFs, …);
            // otherwise fall back to the MetaVox brand icon instead of a generic
            // folder/file icon, so results read as MetaVox results (mirrors the
            // IntraVox provider's app-icon branding).
            $metavoxIcon = $this->urlGenerator->imagePath('metavox', 'metadata.svg');
            $hasPreview = $node instanceof File && $this->preview->isAvailable($node);
            $thumbnailUrl = $hasPreview
                ? $this->urlGenerator->linkToRouteAbsolute('core.preview.getPreviewByFileId', [
                    'fileId' => $file['id'],
                    'x' => self::PREVIEW_SIZE,
                    'y' => self::PREVIEW_SIZE
                ])
                : $metavoxIcon;

            return new SearchResultEntry(
                $thumbnailUrl,
                $title,
                $this->formatMetadataSubline($file['metadata'], $fieldLabels, $userId, $groupfolderId, $needle),
                $this->urlGenerator->linkToRouteAbsolute('files.view.index', [
                    'dir' => $dir,
                    'scrollto' => $node->getName(),
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
     * Verify user has access to a file
     */
    private function verifyFileAccess(int $fileId, $userFolder): ?Node {
        $nodes = $userFolder->getById($fileId);
        if (empty($nodes) || !$nodes[0]->isReadable()) {
            return null;
        }
        return $nodes[0];
    }

    /**
     * Map file_id => groupfolder_id for result files that don't already carry
     * it (the free-text path). One batched query for all result ids — no N+1.
     * A file has one groupfolder in practice; if several rows exist we take any.
     *
     * @param array<array{id:int, groupfolder_id?:int}> $files
     * @return array<int, int> file_id => groupfolder_id
     */
    private function resolveGroupfolderIds(array $files): array {
        $missing = [];
        foreach ($files as $file) {
            if (!isset($file['groupfolder_id'])) {
                $missing[] = (int)$file['id'];
            }
        }
        if (empty($missing)) {
            return [];
        }

        $map = [];
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->selectDistinct('file_id')
               ->addSelect('groupfolder_id')
               ->from('metavox_file_gf_meta')
               ->where($qb->expr()->in('file_id', $qb->createNamedParameter($missing, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $map[(int)$row['file_id']] = (int)$row['groupfolder_id'];
            }
            $result->closeCursor();
        } catch (\Exception $e) {
            $this->logger->warning('MetaVox: groupfolder resolution failed', ['exception' => $e]);
        }
        return $map;
    }

    /**
     * Get mapping of field_name => field_label for groupfolder fields
     * Results are cached for the request lifecycle
     *
     * @return array<string, string>
     */
    private function getFieldLabels(): array {
        if ($this->fieldLabelsCache !== null) {
            return $this->fieldLabelsCache;
        }

        $this->fieldLabelsCache = [];

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('field_name', 'field_label')
               ->from('metavox_gf_fields');

            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $this->fieldLabelsCache[$row['field_name']] = $row['field_label'];
            }
            $result->closeCursor();
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
     *
     * Two refinements over a plain first-N dump:
     *  - The field(s) whose value matches the search needle are shown FIRST, so
     *    a search for "Mercedes" surfaces "Make: Mercedes-Benz" rather than an
     *    arbitrary other field.
     *  - Fields the user may not view (per-field view permission, scoped to the
     *    file's groupfolder) are omitted, so a restricted field can't leak here.
     *
     * @param array<string, string> $metadata
     * @param array<string, string> $fieldLabels
     */
    private function formatMetadataSubline(array $metadata, array $fieldLabels, string $userId, ?int $groupfolderId, string $needle): string {
        $matching = [];
        $other = [];
        foreach ($metadata as $fieldName => $value) {
            if ($value === null || (string)$value === '') {
                continue;
            }
            // Per-field view permission: skip fields this user may not see in
            // this folder. When the groupfolder is unknown we can't scope the
            // check, so fall back to the folder-level grant already verified.
            if ($groupfolderId !== null
                && !$this->permissionService->hasPermission($userId, PermissionService::PERM_VIEW_METADATA, $groupfolderId, $fieldName)) {
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