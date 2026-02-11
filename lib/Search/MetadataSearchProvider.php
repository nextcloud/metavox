<?php

declare(strict_types=1);

namespace OCA\MetaVox\Search;

use OCA\MetaVox\Service\SearchIndexService;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;
use Psr\Log\LoggerInterface;

class MetadataSearchProvider implements IProvider {
    private const MIN_SEARCH_LENGTH = 3;
    private const PREVIEW_SIZE = 32;
    private const MAX_SUBLINE_FIELDS = 3;

    /** @var array<string, string>|null Cached field labels */
    private ?array $fieldLabelsCache = null;

    public function __construct(
        private readonly IL10N $l10n,
        private readonly IURLGenerator $urlGenerator,
        private readonly SearchIndexService $searchIndexService,
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

    public function search(IUser $user, ISearchQuery $query): SearchResult {
        $searchTerm = $query->getTerm();

        if (strlen($searchTerm) < self::MIN_SEARCH_LENGTH) {
            return SearchResult::complete($this->l10n->t('Metadata'), []);
        }

        $results = [];
        $userFolder = $this->rootFolder->getUserFolder($user->getUID());

        try {
            $files = $this->performSearch($searchTerm, $user->getUID());
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
                'search_term' => $searchTerm,
                'app' => 'metavox'
            ]);
        }

        return SearchResult::complete($this->l10n->t('Metadata'), $results);
    }

    /**
     * Perform search based on search term format
     *
     * @return array<array{id: int, name: string, metadata: array}>
     */
    private function performSearch(string $searchTerm, string $userId): array {
        // Check for field-specific search (e.g., "author:john")
        if (preg_match('/^(\w+):\s*(.+)$/', $searchTerm, $matches)) {
            return $this->searchIndexService->searchByFieldValue($matches[1], $matches[2], $userId);
        }

        return $this->searchIndexService->searchFilesByMetadata($searchTerm, $userId);
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