<?php

declare(strict_types=1);

namespace OCA\MetaVox\Listener;

use OCA\MetaVox\Service\FieldService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\FileInfo;
use OCP\Files\Node;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class FileCopyListener implements IEventListener {

    public function __construct(
        private readonly FieldService $fieldService,
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if (!($event instanceof NodeCopiedEvent)) {
            return;
        }

        try {
            $source = $event->getSource();
            $target = $event->getTarget();

            if ($source->getType() === FileInfo::TYPE_FILE) {
                $this->handleFileCopy($source, $target);
            } else {
                $this->handleFolderCopy($source, $target);
            }
        } catch (\Exception $e) {
            $this->logger->error('MetaVox: Error copying metadata', ['exception' => $e]);
        }
    }

    private function handleFileCopy(Node $source, Node $target): void {
        $sourceGroupfolderId = $this->getGroupfolderId($source);
        $targetGroupfolderId = $this->getGroupfolderId($target);

        // Only copy metadata within the same groupfolder to avoid orphaned metadata
        if (!$sourceGroupfolderId || !$targetGroupfolderId || $sourceGroupfolderId !== $targetGroupfolderId) {
            return;
        }

        $copied = $this->copyGroupfolderFileMetadata(
            $sourceGroupfolderId,
            $source->getId(),
            $target->getId(),
        );

        $this->logger->debug('MetaVox: File metadata copied', [
            'sourceFileId' => $source->getId(),
            'targetFileId' => $target->getId(),
            'groupfolderId' => $sourceGroupfolderId,
            'fieldsCopied' => $copied,
        ]);
    }

    private function handleFolderCopy(Node $source, Node $target): void {
        $totalCopied = 0;

        // Copy metadata for the root folder itself
        $totalCopied += $this->copyNodeMetadataIfSameGroupfolder($source, $target);

        // Copy metadata for all subfolders
        foreach ($this->getAllNodesRecursively($source, FileInfo::TYPE_FOLDER) as $sourceSubfolder) {
            $relativePath = $this->getRelativePath($source->getPath(), $sourceSubfolder->getPath());
            $targetSubfolder = $this->findTargetNode($target, $relativePath);

            if ($targetSubfolder && $targetSubfolder->getType() === FileInfo::TYPE_FOLDER) {
                $totalCopied += $this->copyNodeMetadataIfSameGroupfolder($sourceSubfolder, $targetSubfolder);
            }
        }

        // Copy metadata for all files
        foreach ($this->getAllNodesRecursively($source, FileInfo::TYPE_FILE) as $sourceFile) {
            $relativePath = $this->getRelativePath($source->getPath(), $sourceFile->getPath());
            $targetFile = $this->findTargetNode($target, $relativePath);

            if ($targetFile) {
                $totalCopied += $this->copyNodeMetadataIfSameGroupfolder($sourceFile, $targetFile);
            }
        }

        $this->logger->debug('MetaVox: Folder metadata copied', [
            'source' => $source->getPath(),
            'target' => $target->getPath(),
            'fieldsCopied' => $totalCopied,
        ]);
    }

    /**
     * Copy metadata for a node only if source and target are in the same groupfolder.
     */
    private function copyNodeMetadataIfSameGroupfolder(Node $source, Node $target): int {
        $sourceGroupfolderId = $this->getGroupfolderId($source);
        $targetGroupfolderId = $this->getGroupfolderId($target);

        if (!$sourceGroupfolderId || !$targetGroupfolderId || $sourceGroupfolderId !== $targetGroupfolderId) {
            return 0;
        }

        return $this->copyGroupfolderFileMetadata(
            $sourceGroupfolderId,
            $source->getId(),
            $target->getId(),
        );
    }

    /**
     * Get all nodes of a given type recursively from a folder.
     */
    private function getAllNodesRecursively(Node $folder, string $type): array {
        $nodes = [];

        if ($folder->getType() !== FileInfo::TYPE_FOLDER) {
            return $nodes;
        }

        try {
            /** @var \OCP\Files\Folder $folder */
            foreach ($folder->getDirectoryListing() as $child) {
                if ($child->getType() === $type) {
                    $nodes[] = $child;
                }
                if ($child->getType() === FileInfo::TYPE_FOLDER) {
                    $nodes = array_merge($nodes, $this->getAllNodesRecursively($child, $type));
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('MetaVox: Error listing folder ' . $folder->getPath(), ['exception' => $e]);
        }

        return $nodes;
    }

    private function getRelativePath(string $basePath, string $fullPath): string {
        if (str_starts_with($fullPath, $basePath)) {
            return ltrim(substr($fullPath, strlen($basePath)), '/');
        }
        return $fullPath;
    }

    private function findTargetNode(Node $targetFolder, string $relativePath): ?Node {
        try {
            if ($targetFolder->getType() !== FileInfo::TYPE_FOLDER) {
                return null;
            }

            /** @var \OCP\Files\Folder $targetFolder */
            if (empty($relativePath)) {
                return $targetFolder;
            }

            if ($targetFolder->nodeExists($relativePath)) {
                return $targetFolder->get($relativePath);
            }
        } catch (\Exception $e) {
            $this->logger->warning("MetaVox: Error finding target node '$relativePath'", ['exception' => $e]);
        }

        return null;
    }

    private function getGroupfolderId(Node $node): ?int {
        try {
            $path = $node->getPath();

            $qb = $this->db->getQueryBuilder();
            $qb->select('folder_id', 'mount_point')
               ->from('group_folders')
               ->orderBy('folder_id');

            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                if (str_contains($path, '/' . $row['mount_point'] . '/')) {
                    $result->closeCursor();
                    return (int)$row['folder_id'];
                }
            }
            $result->closeCursor();

            // Fallback: internal storage patterns
            if (preg_match('/\/__groupfolders\/(\d+)\//', $path, $matches)) {
                return (int)$matches[1];
            }

            return null;
        } catch (\Exception $e) {
            $this->logger->warning('MetaVox: Groupfolder detection error', ['exception' => $e]);
            return null;
        }
    }

    /**
     * Copy metadata from one file to another within the same groupfolder.
     */
    private function copyGroupfolderFileMetadata(int $groupfolderId, int $sourceFileId, int $targetFileId): int {
        try {
            // Get source metadata for this groupfolder
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
               ->from('metavox_file_gf_meta')
               ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($sourceFileId, IQueryBuilder::PARAM_INT)))
               ->andWhere($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)))
               ->andWhere($qb->expr()->isNotNull('field_value'))
               ->andWhere($qb->expr()->neq('field_value', $qb->createNamedParameter('')))
               ->andWhere($qb->expr()->neq('field_value', $qb->createNamedParameter('null')));

            $result = $qb->executeQuery();
            $sourceMetadata = [];
            while ($row = $result->fetch()) {
                if (!empty(trim($row['field_value'])) && $row['field_value'] !== 'null') {
                    $sourceMetadata[] = $row;
                }
            }
            $result->closeCursor();

            if (empty($sourceMetadata)) {
                return 0;
            }

            // Build field name -> id mapping
            $targetFields = $this->fieldService->getFieldsByScope('groupfolder');
            $fieldMap = [];
            foreach ($targetFields as $field) {
                $fieldMap[$field['field_name']] = $field['id'];
            }

            // Clear existing target metadata
            $qb = $this->db->getQueryBuilder();
            $qb->delete('metavox_file_gf_meta')
               ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($targetFileId, IQueryBuilder::PARAM_INT)))
               ->andWhere($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)));
            $qb->executeStatement();

            $copiedCount = 0;
            foreach ($sourceMetadata as $metadata) {
                $fieldName = $metadata['field_name'];
                if (!isset($fieldMap[$fieldName])) {
                    continue;
                }

                $success = $this->fieldService->saveGroupfolderFileFieldValue(
                    $groupfolderId,
                    $targetFileId,
                    $fieldMap[$fieldName],
                    $metadata['field_value'],
                );

                if ($success) {
                    $copiedCount++;
                }
            }

            return $copiedCount;
        } catch (\Exception $e) {
            $this->logger->error('MetaVox: Error copying metadata', [
                'sourceFileId' => $sourceFileId,
                'targetFileId' => $targetFileId,
                'groupfolderId' => $groupfolderId,
                'exception' => $e,
            ]);
            return 0;
        }
    }
}
