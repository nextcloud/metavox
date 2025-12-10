<?php

namespace OCA\MetaVox\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCA\MetaVox\Service\FieldService;
use OCP\IDBConnection;
use OCP\Files\Node;
use OCP\Files\FileInfo;

class FileDeleteListener implements IEventListener {
    
    private FieldService $fieldService;
    private IDBConnection $db;

    public function __construct(FieldService $fieldService, IDBConnection $db) {
        $this->fieldService = $fieldService;
        $this->db = $db;
    }
    
    public function handle(Event $event): void {
        if (!($event instanceof NodeDeletedEvent)) {
            return;
        }
        
        try {
            $node = $event->getNode();
            
            $logMessage = "=== METAVOX DELETE EVENT ===\n";
            $logMessage .= "Time: " . date('Y-m-d H:i:s') . "\n";
            $logMessage .= "Node: " . $node->getPath() . " (ID: " . $node->getId() . ")\n";
            $logMessage .= "Type: " . ($node->getType() === FileInfo::TYPE_FILE ? 'FILE' : 'FOLDER') . "\n";
            
            // Schedule background cleanup - that's it!
            $this->scheduleBackgroundCleanup($node);
            
            $logMessage .= "Background cleanup job scheduled\n";
            $logMessage .= "===============================\n";
            
            // Log to file
            file_put_contents('/var/www/nextcloud/data/metavox_delete.log', $logMessage, FILE_APPEND | LOCK_EX);
            
        } catch (\Exception $e) {
            $errorMsg = "METAVOX DELETE EVENT ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            file_put_contents('/var/www/nextcloud/data/metavox_delete.log', $errorMsg, FILE_APPEND | LOCK_EX);
        }
    }
    
    /**
     * Schedule background cleanup job - no immediate database operations
     */
    private function scheduleBackgroundCleanup(Node $node): void {
        try {
            $nodeId = $node->getId();
            $nodePath = $node->getPath();
            $nodeType = $node->getType();
            $isFile = $nodeType === FileInfo::TYPE_FILE;
            
            // Try to detect groupfolder (this is safe, only reads group_folders table)
            $groupfolderId = $this->getGroupfolderIdFromPath($nodePath);
            
            // Schedule the background job with all needed info
            \OC::$server->getJobList()->add(
                \OCA\MetaVox\BackgroundJobs\CleanupDeletedMetadata::class,
                [
                    'node_id' => $nodeId,
                    'node_path' => $nodePath,
                    'node_type' => $isFile ? 'file' : 'folder',
                    'groupfolder_id' => $groupfolderId,
                    'timestamp' => time()
                ]
            );
            
            $nodeTypeStr = $isFile ? 'File' : 'Folder';
            error_log("MetaVox: Scheduled cleanup job for $nodeTypeStr: $nodePath (ID: $nodeId, GF: $groupfolderId)");
            
        } catch (\Exception $e) {
            error_log("MetaVox: Failed to schedule cleanup job: " . $e->getMessage());
        }
    }
    
    /**
     * Get groupfolder ID from path - safe operation, only reads group_folders table
     */
    private function getGroupfolderIdFromPath(string $path): ?int {
        try {
            // This query is safe - group_folders table is not involved in file delete transactions
            $qb = $this->db->getQueryBuilder();
            $qb->select('folder_id', 'mount_point')
               ->from('group_folders')
               ->orderBy('folder_id');
            
            $result = $qb->execute();
            $knownGroupfolders = [];
            while ($row = $result->fetch()) {
                $knownGroupfolders[(int)$row['folder_id']] = $row['mount_point'];
            }
            $result->closeCursor();
            
            // Check if path contains any known groupfolder mount points
            foreach ($knownGroupfolders as $folderId => $mountPoint) {
                if (strpos($path, "/$mountPoint/") !== false) {
                    return $folderId;
                }
            }
            
            // Fallback: Try common patterns
            $patterns = [
                '/\/__groupfolders\/(\d+)\//',
                '/\/appdata_[^\/]+\/group_folders\/(\d+)\//',
                '/\/group_folders\/(\d+)\//',
                '/\/files\/(\d+)\//',
            ];
            
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $path, $matches)) {
                    return (int)$matches[1];
                }
            }
            
            return null;
            
        } catch (\Exception $e) {
            error_log("MetaVox: Groupfolder detection error: " . $e->getMessage());
            return null;
        }
    }
}