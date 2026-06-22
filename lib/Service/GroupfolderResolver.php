<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCP\Files\Node;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Resolves the groupfolder (Team Folder) id for a file path.
 *
 * Extracted from FileCopyListener so the same logic is shared by all
 * listeners. Mount points are loaded once per request and cached in memory:
 * a bulk upload fires one NodeCreatedEvent per file, and without this cache
 * each event would re-query the group_folders table.
 *
 * The path-matching itself is a pure function (resolveFromPath) so it can be
 * unit-tested without a database.
 */
class GroupfolderResolver {

    /** @var array<array{id: int, mount_point: string}>|null Request-scoped mount point cache */
    private ?array $mountPoints = null;

    public function __construct(
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Resolve the groupfolder id for a node, or null if it is not inside one.
     */
    public function getGroupfolderId(Node $node): ?int {
        try {
            return $this->resolveFromPath($node->getPath(), $this->getMountPoints());
        } catch (\Exception $e) {
            $this->logger->warning('MetaVox: Groupfolder detection error', ['exception' => $e]);
            return null;
        }
    }

    /**
     * Pure path resolution — no I/O. Given a file path and the list of
     * groupfolder mount points, return the matching groupfolder id.
     *
     * Matching is identical to the original FileCopyListener behaviour:
     * a mount-point match on the path, with a fallback to the internal
     * __groupfolders/{id}/ storage pattern.
     *
     * @param array<array{id: int, mount_point: string}> $mountPoints
     */
    public function resolveFromPath(string $path, array $mountPoints): ?int {
        foreach ($mountPoints as $mp) {
            if ($mp['mount_point'] !== '' && str_contains($path, '/' . $mp['mount_point'] . '/')) {
                return (int)$mp['id'];
            }
        }

        // Fallback: internal storage patterns (e.g. .../__groupfolders/42/...)
        if (preg_match('/\/__groupfolders\/(\d+)\//', $path, $matches)) {
            return (int)$matches[1];
        }

        return null;
    }

    /**
     * Load groupfolder mount points, cached for the lifetime of this request.
     *
     * @return array<array{id: int, mount_point: string}>
     */
    private function getMountPoints(): array {
        if ($this->mountPoints !== null) {
            return $this->mountPoints;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('folder_id', 'mount_point')
           ->from('group_folders')
           ->orderBy('folder_id');

        $result = $qb->executeQuery();
        $rows = [];
        while ($row = $result->fetch()) {
            $rows[] = [
                'id' => (int)$row['folder_id'],
                'mount_point' => (string)$row['mount_point'],
            ];
        }
        $result->closeCursor();

        $this->mountPoints = $rows;
        return $rows;
    }
}
