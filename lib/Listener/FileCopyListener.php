<?php

namespace OCA\MetaVox\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCA\MetaVox\Service\FieldService;
use OCP\IDBConnection;
use OCP\Files\Node;
use OCP\Files\FileInfo;

class FileCopyListener implements IEventListener {
    
    private FieldService $fieldService;
    private IDBConnection $db;

    public function __construct(FieldService $fieldService, IDBConnection $db) {
        $this->fieldService = $fieldService;
        $this->db = $db;
    }
    
    public function handle(Event $event): void {
        if (!($event instanceof NodeCopiedEvent)) {
            return;
        }
        
        try {
            $source = $event->getSource();
            $target = $event->getTarget();
            
            $logMessage = "=== METAVOX METADATA COPY ===\n";
            $logMessage .= "Time: " . date('Y-m-d H:i:s') . "\n";
            $logMessage .= "Source: " . $source->getPath() . " (ID: " . $source->getId() . ")\n";
            $logMessage .= "Target: " . $target->getPath() . " (ID: " . $target->getId() . ")\n";
            $logMessage .= "Type: " . ($source->getType() === FileInfo::TYPE_FILE ? 'FILE' : 'FOLDER') . "\n";
            
            if ($source->getType() === FileInfo::TYPE_FILE) {
                // Handle single file copy
                $this->handleFileCopy($source, $target, $logMessage);
            } else {
                // Handle folder copy - this will recursively handle all files within
                $this->handleFolderCopy($source, $target, $logMessage);
            }
            
        } catch (\Exception $e) {
            $errorMsg = "METAVOX ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            file_put_contents('/var/www/nextcloud/data/metavox_copy.log', $errorMsg, FILE_APPEND | LOCK_EX);
        }
    }
    
    /**
     * Handle copying metadata for a single file
     */
    private function handleFileCopy(Node $source, Node $target, string &$logMessage): void {
        $sourceFileId = $source->getId();
        $targetFileId = $target->getId();
        
        // Check if files are in groupfolders
        $sourceGroupfolderId = $this->getGroupfolderId($source);
        $targetGroupfolderId = $this->getGroupfolderId($target);
        
        $logMessage .= "Source GroupFolder: " . ($sourceGroupfolderId ?? 'none') . "\n";
        $logMessage .= "Target GroupFolder: " . ($targetGroupfolderId ?? 'none') . "\n";
        
        // Copy global metadata
        $globalCopied = $this->copyGlobalFileMetadata($sourceFileId, $targetFileId);
        $logMessage .= "Copied global metadata fields: $globalCopied\n";
        
        $groupfolderCopied = 0;
        if ($sourceGroupfolderId && $targetGroupfolderId) {
            // Copy groupfolder file metadata
            $groupfolderCopied = $this->copyGroupfolderFileMetadata(
                $sourceGroupfolderId, 
                $targetGroupfolderId, 
                $sourceFileId, 
                $targetFileId
            );
            $logMessage .= "Copied groupfolder metadata fields: $groupfolderCopied\n";
        } else {
            $logMessage .= "Files not in groupfolders, no groupfolder metadata copied\n";
        }
        
        $totalCopied = $globalCopied + $groupfolderCopied;
        $logMessage .= "Total metadata fields copied: $totalCopied\n";
        $logMessage .= "==============================\n";
        
        // Log to file
        file_put_contents('/var/www/nextcloud/data/metavox_copy.log', $logMessage, FILE_APPEND | LOCK_EX);
        
        // Also log to Nextcloud system log
        error_log("MetaVox: File copied - Source ID: $sourceFileId, Target ID: $targetFileId, GroupFolders: $sourceGroupfolderId->$targetGroupfolderId, Total metadata fields copied: $totalCopied");
    }
    
    /**
     * Handle copying metadata for a folder and all its contents recursively
     */
    private function handleFolderCopy(Node $source, Node $target, string &$logMessage): void {
        $logMessage .= "=== FOLDER COPY STARTED ===\n";
        
        $totalFiles = 0;
        $totalFolders = 0;
        $totalMetadataFields = 0;
        
        // First, copy metadata for the root folder itself
        $logMessage .= "\n--- Processing root folder metadata ---\n";
        $folderMetadataCopied = $this->copyFolderMetadata($source, $target, $logMessage);
        $totalMetadataFields += $folderMetadataCopied;
        $totalFolders++;
        $logMessage .= "Root folder metadata fields copied: $folderMetadataCopied\n";
        
        // Then copy metadata for all subfolders
        $subfolders = $this->getAllFoldersRecursively($source);
        $logMessage .= "Found " . count($subfolders) . " subfolders\n";
        
        foreach ($subfolders as $sourceSubfolder) {
            try {
                // Calculate relative path from source folder root
                $relativePath = $this->getRelativePath($source->getPath(), $sourceSubfolder->getPath());
                
                // Find corresponding target subfolder
                $targetSubfolder = $this->findTargetFile($target, $relativePath);
                
                if (!$targetSubfolder || $targetSubfolder->getType() !== FileInfo::TYPE_FOLDER) {
                    $logMessage .= "WARNING: Could not find target subfolder for: $relativePath\n";
                    continue;
                }
                
                $logMessage .= "\n--- Processing subfolder: $relativePath ---\n";
                $subfolderMetadataCopied = $this->copyFolderMetadata($sourceSubfolder, $targetSubfolder, $logMessage);
                $totalMetadataFields += $subfolderMetadataCopied;
                $totalFolders++;
                $logMessage .= "Subfolder metadata fields copied: $subfolderMetadataCopied\n";
                
            } catch (\Exception $e) {
                $logMessage .= "ERROR processing subfolder " . $sourceSubfolder->getPath() . ": " . $e->getMessage() . "\n";
            }
        }
        
        // Get all files recursively from source folder
        $sourceFiles = $this->getAllFilesRecursively($source);
        $logMessage .= "Found " . count($sourceFiles) . " files in source folder\n";
        
        foreach ($sourceFiles as $sourceFile) {
            try {
                // Calculate relative path from source folder root
                $relativePath = $this->getRelativePath($source->getPath(), $sourceFile->getPath());
                
                // Find corresponding target file
                $targetFile = $this->findTargetFile($target, $relativePath);
                
                if (!$targetFile) {
                    $logMessage .= "WARNING: Could not find target file for: $relativePath\n";
                    continue;
                }
                
                $sourceFileId = $sourceFile->getId();
                $targetFileId = $targetFile->getId();
                
                $logMessage .= "\n--- Processing file: $relativePath ---\n";
                $logMessage .= "Source ID: $sourceFileId, Target ID: $targetFileId\n";
                
                // Check groupfolder IDs
                $sourceGroupfolderId = $this->getGroupfolderId($sourceFile);
                $targetGroupfolderId = $this->getGroupfolderId($targetFile);
                
                // Copy global metadata
                $globalCopied = $this->copyGlobalFileMetadata($sourceFileId, $targetFileId);
                $logMessage .= "Global metadata fields: $globalCopied\n";
                
                $groupfolderCopied = 0;
                if ($sourceGroupfolderId && $targetGroupfolderId) {
                    // Copy groupfolder metadata
                    $groupfolderCopied = $this->copyGroupfolderFileMetadata(
                        $sourceGroupfolderId, 
                        $targetGroupfolderId, 
                        $sourceFileId, 
                        $targetFileId
                    );
                    $logMessage .= "Groupfolder metadata fields: $groupfolderCopied\n";
                }
                
                $fileTotalCopied = $globalCopied + $groupfolderCopied;
                $totalMetadataFields += $fileTotalCopied;
                $totalFiles++;
                
                $logMessage .= "File total: $fileTotalCopied fields\n";
                
            } catch (\Exception $e) {
                $logMessage .= "ERROR processing file " . $sourceFile->getPath() . ": " . $e->getMessage() . "\n";
            }
        }
        
        $logMessage .= "\n=== FOLDER COPY SUMMARY ===\n";
        $logMessage .= "Total folders processed: $totalFolders\n";
        $logMessage .= "Total files processed: $totalFiles\n";
        $logMessage .= "Total metadata fields copied: $totalMetadataFields\n";
        $logMessage .= "==============================\n";
        
        // Log to file
        file_put_contents('/var/www/nextcloud/data/metavox_copy.log', $logMessage, FILE_APPEND | LOCK_EX);
        
        // Also log to Nextcloud system log
        error_log("MetaVox: Folder copied - Source: " . $source->getPath() . ", Target: " . $target->getPath() . ", Folders: $totalFolders, Files: $totalFiles, Total metadata fields: $totalMetadataFields");
    }
    
    /**
     * Get all folders recursively from a folder
     */
    private function getAllFoldersRecursively(Node $folder): array {
        $folders = [];
        
        try {
            if ($folder->getType() !== FileInfo::TYPE_FOLDER) {
                return $folders;
            }
            
            /** @var \OCP\Files\Folder $folder */
            $children = $folder->getDirectoryListing();
            
            foreach ($children as $child) {
                if ($child->getType() === FileInfo::TYPE_FOLDER) {
                    $folders[] = $child;
                    // Recursively get subfolders
                    $subFolders = $this->getAllFoldersRecursively($child);
                    $folders = array_merge($folders, $subFolders);
                }
            }
        } catch (\Exception $e) {
            error_log("MetaVox: Error getting folders from folder " . $folder->getPath() . ": " . $e->getMessage());
        }
        
        return $folders;
    }
    
    /**
     * Copy metadata for a folder (both global and groupfolder metadata)
     */
    private function copyFolderMetadata(Node $sourceFolder, Node $targetFolder, string &$logMessage): int {
        $sourceFolderId = $sourceFolder->getId();
        $targetFolderId = $targetFolder->getId();
        
        $logMessage .= "Source Folder ID: $sourceFolderId, Target Folder ID: $targetFolderId\n";
        
        // Check if folders are in groupfolders
        $sourceGroupfolderId = $this->getGroupfolderId($sourceFolder);
        $targetGroupfolderId = $this->getGroupfolderId($targetFolder);
        
        $logMessage .= "Source GroupFolder: " . ($sourceGroupfolderId ?? 'none') . "\n";
        $logMessage .= "Target GroupFolder: " . ($targetGroupfolderId ?? 'none') . "\n";
        
        // Copy global folder metadata
        $globalCopied = $this->copyGlobalFileMetadata($sourceFolderId, $targetFolderId);
        $logMessage .= "Global folder metadata fields: $globalCopied\n";
        
        $groupfolderCopied = 0;
        if ($sourceGroupfolderId && $targetGroupfolderId) {
            // Copy groupfolder folder metadata
            $groupfolderCopied = $this->copyGroupfolderFileMetadata(
                $sourceGroupfolderId, 
                $targetGroupfolderId, 
                $sourceFolderId, 
                $targetFolderId
            );
            $logMessage .= "Groupfolder folder metadata fields: $groupfolderCopied\n";
        } else {
            $logMessage .= "Folders not in groupfolders, no groupfolder metadata copied\n";
        }
        
        return $globalCopied + $groupfolderCopied;
    }
    
    /**
     * Get all files recursively from a folder
     */
    private function getAllFilesRecursively(Node $folder): array {
        $files = [];
        
        try {
            if ($folder->getType() !== FileInfo::TYPE_FOLDER) {
                return $files;
            }
            
            /** @var \OCP\Files\Folder $folder */
            $children = $folder->getDirectoryListing();
            
            foreach ($children as $child) {
                if ($child->getType() === FileInfo::TYPE_FILE) {
                    $files[] = $child;
                } elseif ($child->getType() === FileInfo::TYPE_FOLDER) {
                    // Recursively get files from subfolders
                    $subFiles = $this->getAllFilesRecursively($child);
                    $files = array_merge($files, $subFiles);
                }
            }
        } catch (\Exception $e) {
            error_log("MetaVox: Error getting files from folder " . $folder->getPath() . ": " . $e->getMessage());
        }
        
        return $files;
    }
    
    /**
     * Get relative path from base path
     */
    private function getRelativePath(string $basePath, string $fullPath): string {
        // Remove base path from full path to get relative path
        if (strpos($fullPath, $basePath) === 0) {
            $relativePath = substr($fullPath, strlen($basePath));
            return ltrim($relativePath, '/');
        }
        
        return $fullPath;
    }
    
    /**
     * Find target file using relative path
     */
    private function findTargetFile(Node $targetFolder, string $relativePath): ?Node {
        try {
            if ($targetFolder->getType() !== FileInfo::TYPE_FOLDER) {
                return null;
            }
            
            /** @var \OCP\Files\Folder $targetFolder */
            
            // If relative path is empty, we're looking for the folder itself
            if (empty($relativePath)) {
                return $targetFolder;
            }
            
            // Try to get the file directly
            if ($targetFolder->nodeExists($relativePath)) {
                return $targetFolder->get($relativePath);
            }
            
        } catch (\Exception $e) {
            error_log("MetaVox: Error finding target file '$relativePath': " . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * Copy global file metadata
     */
    private function copyGlobalFileMetadata(int $sourceFileId, int $targetFileId): int {
        try {
            // Get source file's global metadata
            $qb = $this->db->getQueryBuilder();
            $qb->select('field_id', 'value')
               ->from('metavox_metadata')
               ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($sourceFileId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
               ->andWhere($qb->expr()->isNotNull('value'))
               ->andWhere($qb->expr()->neq('value', $qb->createNamedParameter('')));

            $result = $qb->executeQuery();
            $sourceMetadata = [];
            while ($row = $result->fetch()) {
                if (!empty(trim($row['value']))) {
                    $sourceMetadata[] = $row;
                }
            }
            $result->closeCursor();
            
            if (empty($sourceMetadata)) {
                return 0;
            }
            
            // Delete any existing metadata for target file
            $qb = $this->db->getQueryBuilder();
            $qb->delete('metavox_metadata')
               ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($targetFileId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
            $qb->executeStatement();
            
            // Copy metadata to target file
            $copiedCount = 0;
            foreach ($sourceMetadata as $metadata) {
                $success = $this->fieldService->saveFieldValue(
                    $targetFileId,
                    (int)$metadata['field_id'],
                    $metadata['value']
                );
                
                if ($success) {
                    $copiedCount++;
                }
            }
            
            return $copiedCount;
            
        } catch (\Exception $e) {
            error_log("MetaVox: Global metadata copy error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get groupfolder ID from file path - CONSISTENT VERSION
     */
    private function getGroupfolderId(\OCP\Files\Node $node): ?int {
        try {
            $path = $node->getPath();
            
            // Debug logging
            $debugInfo = "=== GROUPFOLDER DEBUG ===\n";
            $debugInfo .= "Node path: $path\n";
            
            // First check the database to see what groupfolders exist and their mount points
            $qb = $this->db->getQueryBuilder();
            $qb->select('folder_id', 'mount_point')
               ->from('group_folders')
               ->orderBy('folder_id');
            
            $result = $qb->executeQuery();
            $knownGroupfolders = [];
            while ($row = $result->fetch()) {
                $knownGroupfolders[(int)$row['folder_id']] = $row['mount_point'];
                $debugInfo .= "Known groupfolder: ID={$row['folder_id']}, mount_point={$row['mount_point']}\n";
            }
            $result->closeCursor();
            
            // Check if path contains any known groupfolder mount points
            foreach ($knownGroupfolders as $folderId => $mountPoint) {
                if (strpos($path, "/$mountPoint/") !== false) {
                    $debugInfo .= "MATCH: Path contains /$mountPoint/ -> GroupFolder ID: $folderId\n";
                    file_put_contents('/var/www/nextcloud/data/metavox_debug.log', $debugInfo . "=========================\n", FILE_APPEND | LOCK_EX);
                    return $folderId;
                }
            }
            
            // Fallback: Try common patterns
            $patterns = [
                '/\/__groupfolders\/(\d+)\//' => '__groupfolders pattern',
                '/\/appdata_[^\/]+\/group_folders\/(\d+)\//' => 'appdata pattern',
                '/\/group_folders\/(\d+)\//' => 'group_folders pattern',
                '/\/files\/(\d+)\//' => 'files pattern',
            ];
            
            foreach ($patterns as $pattern => $description) {
                if (preg_match($pattern, $path, $matches)) {
                    $debugInfo .= "FALLBACK MATCH: $description - GroupFolder ID: " . $matches[1] . "\n";
                    file_put_contents('/var/www/nextcloud/data/metavox_debug.log', $debugInfo . "=========================\n", FILE_APPEND | LOCK_EX);
                    return (int)$matches[1];
                }
            }
            
            $debugInfo .= "No groupfolder pattern matched\n";
            file_put_contents('/var/www/nextcloud/data/metavox_debug.log', $debugInfo . "=========================\n", FILE_APPEND | LOCK_EX);
            
            return null;
            
        } catch (\Exception $e) {
            $errorInfo = "Groupfolder detection error: " . $e->getMessage() . "\n";
            file_put_contents('/var/www/nextcloud/data/metavox_debug.log', $errorInfo, FILE_APPEND | LOCK_EX);
            return null;
        }
    }
    
    /**
     * Copy metadata between files in groupfolders - FIXED VERSION
     */
    private function copyGroupfolderFileMetadata(int $sourceGroupfolderId, int $targetGroupfolderId, int $sourceFileId, int $targetFileId): int {
        try {
            $debugInfo = "=== METADATA COPY DEBUG ===\n";
            $debugInfo .= "Source: GF$sourceGroupfolderId/File$sourceFileId\n";
            $debugInfo .= "Target: GF$targetGroupfolderId/File$targetFileId\n";
            
            // Get ALL metadata for the source file, regardless of groupfolder
            // This handles cases where files moved between groupfolders
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
               ->from('metavox_file_gf_meta')
               ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($sourceFileId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
               ->andWhere($qb->expr()->isNotNull('field_value'))
               ->andWhere($qb->expr()->neq('field_value', $qb->createNamedParameter('')))
               ->andWhere($qb->expr()->neq('field_value', $qb->createNamedParameter('null')));
            
            $result = $qb->executeQuery();
            $sourceMetadata = [];
            while ($row = $result->fetch()) {
                if (!empty(trim($row['field_value'])) && $row['field_value'] !== 'null') {
                    $sourceMetadata[] = $row;
                    $debugInfo .= "Found metadata: gf_id={$row['groupfolder_id']}, field={$row['field_name']}, value={$row['field_value']}\n";
                }
            }
            $result->closeCursor();
            
            if (empty($sourceMetadata)) {
                $debugInfo .= "No metadata found for source file $sourceFileId\n";
                file_put_contents('/var/www/nextcloud/data/metavox_copy_debug.log', $debugInfo, FILE_APPEND | LOCK_EX);
                return 0;
            }
            
            // Get target groupfolder fields to validate what we can copy
            $targetFields = $this->fieldService->getFieldsByScope('groupfolder');
            $debugInfo .= "Target groupfolder fields count: " . count($targetFields) . "\n";
            
            // Create field mapping (field_name -> field_id)
            $fieldMap = [];
            foreach ($targetFields as $field) {
                $fieldMap[$field['field_name']] = $field['id'];
                $debugInfo .= "Available target field: {$field['field_name']} (ID: {$field['id']})\n";
            }
            
            // Delete any existing metadata for the target file first
            $qb = $this->db->getQueryBuilder();
            $qb->delete('metavox_file_gf_meta')
               ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($targetFileId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
               ->andWhere($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($targetGroupfolderId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
            $deleted = $qb->executeStatement();
            if ($deleted > 0) {
                $debugInfo .= "Cleaned up $deleted existing metadata records for target file\n";
            }
            
            $copiedCount = 0;
            foreach ($sourceMetadata as $metadata) {
                $fieldName = $metadata['field_name'];
                $value = $metadata['field_value'];
                
                $debugInfo .= "Processing field: $fieldName = '$value'\n";
                
                // Check if this field exists in the target groupfolder's available fields
                if (!isset($fieldMap[$fieldName])) {
                    $debugInfo .= "  -> Skipped: field '$fieldName' not available in target groupfolder\n";
                    continue;
                }
                
                $fieldId = $fieldMap[$fieldName];
                
                $debugInfo .= "  -> Copying to: GF$targetGroupfolderId/File$targetFileId/Field$fieldId = '$value'\n";
                
                // Use the FieldService method to save
                $success = $this->fieldService->saveGroupfolderFileFieldValue(
                    $targetGroupfolderId,
                    $targetFileId,
                    $fieldId,
                    $value
                );
                
                if ($success) {
                    $copiedCount++;
                    $debugInfo .= "  -> SUCCESS\n";
                } else {
                    $debugInfo .= "  -> FAILED\n";
                }
            }
            
            $debugInfo .= "Final copied count: $copiedCount\n";
            
            // Verification
            $debugInfo .= "\n=== VERIFICATION ===\n";
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
               ->from('metavox_file_gf_meta')
               ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($targetFileId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
               ->andWhere($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($targetGroupfolderId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
            
            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $debugInfo .= "Verified in target: field={$row['field_name']}, value={$row['field_value']}\n";
            }
            $result->closeCursor();
            
            $debugInfo .= "===========================\n";
            
            file_put_contents('/var/www/nextcloud/data/metavox_copy_debug.log', $debugInfo, FILE_APPEND | LOCK_EX);
            
            return $copiedCount;
            
        } catch (\Exception $e) {
            $errorInfo = "Metadata copy error: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            file_put_contents('/var/www/nextcloud/data/metavox_copy_debug.log', $errorInfo, FILE_APPEND | LOCK_EX);
            return 0;
        }
    }
}