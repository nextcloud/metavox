<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Where a team folder's files live in filecache: on their own storage (under
 * "files/") or, in older installs, under "__groupfolders/<id>/" in the root
 * storage. groupfolders 19.1.10+ records the root in group_folders.root_id.
 */
class GroupfolderRootService {

    /** @var array<int, list<array{storage: int, path: string}>> */
    private array $rootsCache = [];

    public function __construct(
        private readonly IDBConnection $db,
    ) {
    }

    /**
     * @return list<array{storage: int, path: string}> storage id + path of the folder root
     */
    public function getRoots(int $groupfolderId): array {
        if (!isset($this->rootsCache[$groupfolderId])) {
            $this->rootsCache[$groupfolderId] = $this->getRecordedRoot($groupfolderId)
                ?? $this->getSeparateStorageRoots($groupfolderId);
        }
        return $this->rootsCache[$groupfolderId];
    }

    /**
     * getRoots() for many team folders at once: two queries, not two per
     * folder. Folders without a recorded root are left to getRoots().
     *
     * @param int[] $groupfolderIds
     */
    public function primeRoots(array $groupfolderIds): void {
        $ids = array_values(array_diff(array_unique(array_filter(array_map('intval', $groupfolderIds))), array_keys($this->rootsCache)));
        foreach (array_chunk($ids, 1000) as $chunk) {
            // SELECT *: older versions lack root_id.
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
               ->from('group_folders')
               ->where($qb->expr()->in('folder_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
            $result = $qb->executeQuery();
            $rootIds = [];
            while ($folder = $result->fetch()) {
                if ((int)($folder['root_id'] ?? 0) > 0) {
                    $rootIds[(int)$folder['root_id']] = (int)$folder['folder_id'];
                }
            }
            $result->closeCursor();
            if ($rootIds === []) {
                continue;
            }

            $qb = $this->db->getQueryBuilder();
            $qb->select('fileid', 'storage', 'path')
               ->from('filecache')
               ->where($qb->expr()->in('fileid', $qb->createNamedParameter(array_keys($rootIds), IQueryBuilder::PARAM_INT_ARRAY)));
            $result = $qb->executeQuery();
            while ($root = $result->fetch()) {
                $this->rootsCache[$rootIds[(int)$root['fileid']]] = [['storage' => (int)$root['storage'], 'path' => (string)$root['path']]];
            }
            $result->closeCursor();
        }
    }

    /**
     * Team folder of a file by its place in filecache, for when there is no
     * user mount (e.g. an admin who isn't a member).
     */
    public function getGroupfolderIdForFile(int $fileId): ?int {
        $qb = $this->db->getQueryBuilder();
        $qb->select('f.storage', 'f.path', 's.id')
           ->from('filecache', 'f')
           ->innerJoin('f', 'storages', 's', $qb->expr()->eq('s.numeric_id', 'f.storage'))
           ->where($qb->expr()->eq('f.fileid', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $file = $result->fetch();
        $result->closeCursor();

        if ($file === false) {
            return null;
        }

        // Jailed layout: "__groupfolders/<id>/..." in the root storage.
        // Separate storage: the storage id ends in "/__groupfolders/<id>/".
        if (preg_match('#^__groupfolders/(\d+)/#', (string)$file['path'], $matches) !== 1
            && preg_match('#/__groupfolders/(\d+)/$#', (string)$file['id'], $matches) !== 1) {
            return null;
        }
        $groupfolderId = (int)$matches[1];

        // Check against the real root; this also skips trash and versions.
        foreach ($this->getRoots($groupfolderId) as $root) {
            if ((int)$file['storage'] === $root['storage'] && str_starts_with((string)$file['path'], $root['path'] . '/')) {
                return $groupfolderId;
            }
        }
        return null;
    }

    /**
     * A file's path inside its team folder ('' for the folder itself), from
     * its place in filecache. Null outside it: trash, versions, another folder.
     */
    public function pathInFolder(int $groupfolderId, int $storage, string $path): ?string {
        foreach ($this->getRoots($groupfolderId) as $root) {
            if ($storage !== $root['storage']) {
                continue;
            }
            if ($path === $root['path']) {
                return '';
            }
            if (str_starts_with($path, $root['path'] . '/')) {
                return substr($path, \strlen($root['path']) + 1);
            }
        }
        return null;
    }

    /**
     * Every file and folder id in the team folder, for when the readable ones
     * have to be subtracted.
     *
     * @return list<int>
     */
    public function getFileIds(int $groupfolderId): array {
        $qb = $this->db->getQueryBuilder();
        $inRoots = $this->buildInRootsCondition($qb, $this->getRoots($groupfolderId));
        if ($inRoots === null) {
            return [];
        }
        $qb->select('fileid')->from('filecache')->where($inRoots);
        $result = $qb->executeQuery();
        $ids = [];
        while (($id = $result->fetchOne()) !== false) {
            $ids[] = (int)$id;
        }
        $result->closeCursor();
        return $ids;
    }

    /**
     * Null when there are no roots, so callers can stop instead of matching
     * everything.
     *
     * @param list<array{storage: int, path: string}> $roots
     */
    public function buildInRootsCondition(IQueryBuilder $qb, array $roots, string $storageColumn = 'storage', string $pathColumn = 'path'): ?ICompositeExpression {
        if (empty($roots)) {
            return null;
        }
        $conditions = [];
        foreach ($roots as $root) {
            $conditions[] = $qb->expr()->andX(
                $qb->expr()->eq($storageColumn, $qb->createNamedParameter($root['storage'], IQueryBuilder::PARAM_INT)),
                $qb->expr()->like($pathColumn, $qb->createNamedParameter($this->db->escapeLikeParameter($root['path']) . '/%')),
            );
        }
        return $qb->expr()->orX(...$conditions);
    }

    /**
     * Null before groupfolders 19.1.10, which has no root_id.
     *
     * @return list<array{storage: int, path: string}>|null
     */
    private function getRecordedRoot(int $groupfolderId): ?array {
        // SELECT *: older versions lack root_id, and on PostgreSQL a failed
        // query aborts the surrounding transaction.
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('group_folders')
           ->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $folder = $result->fetch();
        $result->closeCursor();

        $rootId = $folder['root_id'] ?? null;
        if ($rootId === null || (int)$rootId <= 0) {
            return null;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('storage', 'path')
           ->from('filecache')
           ->where($qb->expr()->eq('fileid', $qb->createNamedParameter((int)$rootId, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $root = $result->fetch();
        $result->closeCursor();

        if ($root === false) {
            return null;
        }
        return [['storage' => (int)$root['storage'], 'path' => (string)$root['path']]];
    }

    /**
     * Fallback without root_id: only separate storages can be found, by name.
     *
     * @return list<array{storage: int, path: string}>
     */
    private function getSeparateStorageRoots(int $groupfolderId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('numeric_id')
           ->from('storages')
           ->where($qb->expr()->like('id', $qb->createNamedParameter('%/__groupfolders/' . $groupfolderId . '/')));
        $result = $qb->executeQuery();
        $roots = [];
        while ($row = $result->fetch()) {
            $roots[] = ['storage' => (int)$row['numeric_id'], 'path' => 'files'];
        }
        $result->closeCursor();
        return $roots;
    }
}
