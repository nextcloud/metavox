<?php
declare(strict_types=1);

namespace OCA\MetaVox\Search;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;
use OCA\MetaVox\Service\SearchIndexService;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;

class MetadataSearchProvider implements IProvider {

    private IL10N $l10n;
    private IURLGenerator $urlGenerator;
    private SearchIndexService $searchIndexService;
    private IRootFolder $rootFolder;
    private IDBConnection $db;

    public function __construct(
        IL10N $l10n, 
        IURLGenerator $urlGenerator, 
        SearchIndexService $searchIndexService,
        IRootFolder $rootFolder,
        IDBConnection $db
    ) {
        $this->l10n = $l10n;
        $this->urlGenerator = $urlGenerator;
        $this->searchIndexService = $searchIndexService;
        $this->rootFolder = $rootFolder;
        $this->db = $db;
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
        
        if (strlen($searchTerm) < 3) {
            return SearchResult::complete($this->l10n->t('Metadata'), []);
        }

        $results = [];
        $userFolder = $this->rootFolder->getUserFolder($user->getUID());

        try {
            // Check for field-specific search (e.g., "author:john")
            if (preg_match('/^(\w+):\s*(.+)$/', $searchTerm, $matches)) {
                $fieldName = $matches[1];
                $fieldValue = $matches[2];
                $files = $this->searchIndexService->searchByFieldValue($fieldName, $fieldValue, $user->getUID());
            } else {
                $files = $this->searchIndexService->searchFilesByMetadata($searchTerm, $user->getUID());
            }

            // Get field labels mapping
            $fieldLabels = $this->getFieldLabels();

            foreach ($files as $file) {
                // Verify user has access to this file
                try {
                    $nodes = $userFolder->getById($file['id']);
                    if (empty($nodes) || !$nodes[0]->isReadable()) {
                        continue;
                    }
                    $node = $nodes[0];
                    
                    // Get the correct path relative to user folder
                    $relativePath = $userFolder->getRelativePath($node->getPath());
                    $dir = dirname($relativePath);
                    
                    // Fix root directory
                    if ($dir === '.') {
                        $dir = '/';
                    }
                    
                    $results[] = new SearchResultEntry(
                        $this->urlGenerator->linkToRouteAbsolute('core.preview.getPreviewByFileId', ['fileId' => $file['id'], 'x' => 32, 'y' => 32]),
                        $file['name'],
                        $this->formatMetadataSubline($file['metadata'], $fieldLabels),
                        $this->urlGenerator->linkToRouteAbsolute('files.view.index', [
                            'dir' => $dir,
                            'scrollto' => $node->getName(),
                            'fileid' => $file['id']
                        ]),
                        $this->getFileIcon($file['name'])
                    );
                } catch (\Exception $e) {
                    // Skip files user can't access
                    continue;
                }
            }

        } catch (\Exception $e) {
            error_log('MetaVox search error: ' . $e->getMessage());
        }

        return SearchResult::complete($this->l10n->t('Metadata'), $results);
    }

    /**
     * Get mapping of field_name => field_label for both global and groupfolder fields
     */
    private function getFieldLabels(): array {
        $labels = [];
        
        try {
            // Get global fields
            $qb = $this->db->getQueryBuilder();
            $qb->select('field_name', 'field_label')
               ->from('metavox_fields');
            
            $result = $qb->execute();
            while ($row = $result->fetch()) {
                $labels[$row['field_name']] = $row['field_label'];
            }
            $result->closeCursor();
            
            // Get groupfolder fields
            $qb = $this->db->getQueryBuilder();
            $qb->select('field_name', 'field_label')
               ->from('metavox_gf_fields');
            
            $result = $qb->execute();
            while ($row = $result->fetch()) {
                $labels[$row['field_name']] = $row['field_label'];
            }
            $result->closeCursor();
            
        } catch (\Exception $e) {
            error_log('MetaVox getFieldLabels error: ' . $e->getMessage());
        }
        
        return $labels;
    }

    private function formatMetadataSubline(array $metadata, array $fieldLabels): string {
        $parts = [];
        foreach ($metadata as $fieldName => $value) {
            if ($value && strlen($value) > 0) {
                // Use field_label if available, otherwise use field_name
                $displayName = $fieldLabels[$fieldName] ?? $fieldName;
                $parts[] = "{$displayName}: {$value}";
            }
        }
        return implode(' • ', array_slice($parts, 0, 3));
    }

    private function getFileIcon(string $filename): string {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        $iconMap = [
            'pdf' => 'icon-filetype-pdf',
            'doc' => 'icon-filetype-document',
            'docx' => 'icon-filetype-document',
            'xls' => 'icon-filetype-spreadsheet',
            'xlsx' => 'icon-filetype-spreadsheet',
            'ppt' => 'icon-filetype-presentation',
            'pptx' => 'icon-filetype-presentation',
            'jpg' => 'icon-filetype-image',
            'jpeg' => 'icon-filetype-image',
            'png' => 'icon-filetype-image',
            'gif' => 'icon-filetype-image',
        ];

        return $iconMap[$extension] ?? 'icon-filetype-file';
    }
}