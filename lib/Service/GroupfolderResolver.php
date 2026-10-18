<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCA\GroupFolders\Mount\GroupMountPoint;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * Which team folder a node is in, read from its mount like groupfolders does
 * itself. Folder names in the path are not reliable.
 */
class GroupfolderResolver {

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Resolve the groupfolder id for a node, or null if it is not inside one.
     */
    public function getGroupfolderId(Node $node): ?int {
        try {
            $mount = $node->getMountPoint();
            if ($mount instanceof GroupMountPoint) {
                return $mount->getFolderId();
            }
            return self::resolveFromRootPath($node->getPath());
        } catch (\Exception $e) {
            $this->logger->warning('MetaVox: Groupfolder detection error', ['exception' => $e]);
            return null;
        }
    }

    /**
     * Old layout reached via the root folder (cron, occ): "/__groupfolders/<id>/...".
     */
    public static function resolveFromRootPath(string $path): ?int {
        if (preg_match('#^/__groupfolders/(\d+)/#', $path, $matches)) {
            return (int)$matches[1];
        }
        return null;
    }
}
