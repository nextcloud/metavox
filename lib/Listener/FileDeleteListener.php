<?php

declare(strict_types=1);

namespace OCA\MetaVox\Listener;

use OCA\MetaVox\BackgroundJobs\CleanupDeletedMetadata;
use OCA\MetaVox\Service\FieldService;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\FileInfo;
use OCP\Files\Node;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Listens for file/folder deletion events and schedules metadata cleanup
 *
 * @template-implements IEventListener<NodeDeletedEvent>
 */
class FileDeleteListener implements IEventListener {
    /** @var array<int, string>|null Cached groupfolder mappings */
    private ?array $groupfolderCache = null;

    public function __construct(
        private readonly FieldService $fieldService,
        private readonly IDBConnection $db,
        private readonly IJobList $jobList,
        private readonly LoggerInterface $logger
    ) {
    }

    public function handle(Event $event): void {
        if (!($event instanceof NodeDeletedEvent)) {
            return;
        }

        try {
            $node = $event->getNode();
            $this->scheduleBackgroundCleanup($node);
        } catch (\Exception $e) {
            $this->logger->error('MetaVox delete event error', [
                'exception' => $e,
                'app' => 'metavox'
            ]);
        }
    }

    /**
     * Schedule background cleanup job - no immediate database operations
     */
    private function scheduleBackgroundCleanup(Node $node): void {
        try {
            $nodeId = $node->getId();
            $nodePath = $node->getPath();
            $isFile = $node->getType() === FileInfo::TYPE_FILE;

            // Try to detect groupfolder (safe, only reads group_folders table)
            $groupfolderId = $this->getGroupfolderIdFromPath($nodePath);

            // Schedule the background job with all needed info
            $this->jobList->add(CleanupDeletedMetadata::class, [
                'node_id' => $nodeId,
                'node_path' => $nodePath,
                'node_type' => $isFile ? 'file' : 'folder',
                'groupfolder_id' => $groupfolderId,
                'timestamp' => time()
            ]);

            $this->logger->debug('MetaVox: Scheduled cleanup job', [
                'type' => $isFile ? 'file' : 'folder',
                'path' => $nodePath,
                'node_id' => $nodeId,
                'groupfolder_id' => $groupfolderId,
                'app' => 'metavox'
            ]);
        } catch (\Exception $e) {
            $this->logger->error('MetaVox: Failed to schedule cleanup job', [
                'exception' => $e,
                'app' => 'metavox'
            ]);
        }
    }

    /**
     * Get groupfolder ID from path - safe operation, only reads group_folders table
     * Results are cached for the request lifecycle
     */
    private function getGroupfolderIdFromPath(string $path): ?int {
        try {
            // Load and cache groupfolders if not already done
            if ($this->groupfolderCache === null) {
                $this->loadGroupfolderCache();
            }

            // Check if path contains any known groupfolder mount points
            foreach ($this->groupfolderCache as $folderId => $mountPoint) {
                if (str_contains($path, "/$mountPoint/")) {
                    return $folderId;
                }
            }

            // Fallback: Try common patterns
            return $this->detectGroupfolderFromPattern($path);
        } catch (\Exception $e) {
            $this->logger->warning('MetaVox: Groupfolder detection error', [
                'exception' => $e,
                'path' => $path,
                'app' => 'metavox'
            ]);
            return null;
        }
    }

    /**
     * Load groupfolder mappings into cache
     */
    private function loadGroupfolderCache(): void {
        $this->groupfolderCache = [];

        $qb = $this->db->getQueryBuilder();
        $qb->select('folder_id', 'mount_point')
           ->from('group_folders')
           ->orderBy('folder_id');

        $result = $qb->executeQuery();
        while ($row = $result->fetch()) {
            $this->groupfolderCache[(int)$row['folder_id']] = $row['mount_point'];
        }
        $result->closeCursor();
    }

    /**
     * Detect groupfolder ID from path patterns
     */
    private function detectGroupfolderFromPattern(string $path): ?int {
        $patterns = [
            '/\/__groupfolders\/(\d+)\//',
            '/\/appdata_[^\/]+\/group_folders\/(\d+)\//',
            '/\/group_folders\/(\d+)\//',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $path, $matches)) {
                return (int)$matches[1];
            }
        }

        return null;
    }
}