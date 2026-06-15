<?php

declare(strict_types=1);

namespace OCA\MetaVox\Search;

use OCA\MetaVox\Service\PermissionService;
use OCA\MetaVox\Service\SearchIndexService;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IDBConnection;
use OCP\IL10N;
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
        private readonly IRootFolder $rootFolder,
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getId(): string {
        return 'metavox_metadata';
    }

    public function getName(): string {
        return $this->l10n->t('File Metadata');
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
            return SearchResult::complete($this->l10n->t('Metadata'), []);
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

        try {
            $files = $this->performSearch($searchExpr, $userId, $groupfolderId);
            $fieldLabels = $this->getFieldLabels();

            foreach ($files as $file) {
                $entry = $this->createSearchResultEntry($file, $userFolder, $fieldLabels);
                if ($entry !== null) {
                    $results[] = $entry;
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('MetaVox search error', [
                'exception' => $e,
                'search_term' => $searchExpr,
                'app' => 'metavox'
            ]);
        }

        return SearchResult::complete($this->l10n->t('Metadata'), $results);
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
    private function performSearch(string $searchTerm, string $userId, ?int $groupfolderId = null): array {
        // Field-specific search via the shared "field:value" parser.
        $parsed = self::parseFieldFilter($searchTerm);
        if ($parsed !== null) {
            return $this->searchIndexService->searchByFieldValue(
                $parsed['field'],
                $parsed['value'],
                $userId,
                $groupfolderId
            );
        }

        return $this->searchIndexService->searchFilesByMetadata($searchTerm, $userId);
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
    private function createSearchResultEntry(array $file, $userFolder, array $fieldLabels): ?SearchResultEntry {
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

            return new SearchResultEntry(
                $this->urlGenerator->linkToRouteAbsolute('core.preview.getPreviewByFileId', [
                    'fileId' => $file['id'],
                    'x' => self::PREVIEW_SIZE,
                    'y' => self::PREVIEW_SIZE
                ]),
                $file['name'],
                $this->formatMetadataSubline($file['metadata'], $fieldLabels),
                $this->urlGenerator->linkToRouteAbsolute('files.view.index', [
                    'dir' => $dir,
                    'scrollto' => $node->getName(),
                    'fileid' => $file['id']
                ]),
                'icon-folder', // Use Nextcloud's built-in icon class for fallback
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
     * @param array<string, string> $metadata
     * @param array<string, string> $fieldLabels
     */
    private function formatMetadataSubline(array $metadata, array $fieldLabels): string {
        $parts = [];
        foreach ($metadata as $fieldName => $value) {
            if (!empty($value)) {
                $displayName = $fieldLabels[$fieldName] ?? $fieldName;
                $parts[] = "{$displayName}: {$value}";
            }
        }
        return implode(' • ', array_slice($parts, 0, self::MAX_SUBLINE_FIELDS));
    }
}