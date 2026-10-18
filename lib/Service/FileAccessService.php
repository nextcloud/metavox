<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCA\MetaVox\Access\ReadableScope;
use OCA\MetaVox\Access\VisibleFolder;
use OCA\MetaVox\Exception\MetadataAccessException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IDBConnection;

/**
 * May this user read or change this file's metadata? The file's Nextcloud
 * permissions inside its team folder decide (group permissions and ACL
 * included); shares give no access, admins always may. Team folder rights
 * are TeamFolderAccessService.
 *
 * Only call this for the session user: groupfolders applies ACLs differently
 * for other users' mounts.
 */
class FileAccessService {

    /**
     * prime() lists a folder when at least 1 in LIST_SHARE of its files is asked,
     * and LIST_MIN or more: measured, a listing costs ~0.03 ms per file in the
     * folder, a lookup ~1.5 ms per file asked.
     */
    private const LIST_SHARE = 50;
    private const LIST_MIN = 5;
    private const ID_CHUNK = 1000;

    /** @var array<string, FileAccess> keyed by "uid:fileId" */
    private array $accessCache = [];

    /** @var array<string, ReadableScope> keyed by "uid:groupfolderId" */
    private array $scopeCache = [];

    public function __construct(
        private readonly IRootFolder $rootFolder,
        private readonly GroupfolderResolver $groupfolderResolver,
        private readonly GroupfolderRootService $groupfolderRoots,
        private readonly TeamFolderAclService $teamFolderAcl,
        private readonly TeamFolderAccessService $teamFolders,
        private readonly IDBConnection $db,
    ) {
    }

    /**
     * Before checking many files: a folder that holds enough of them is listed
     * once, and Nextcloud applies its permissions and ACL to the listing. A few
     * queries instead of four per file; what is not decided here is looked up
     * per file as always.
     *
     * @param int[] $fileIds
     */
    public function prime(string $userId, array $fileIds): void {
        $ids = [];
        foreach ($fileIds as $fileId) {
            if (!isset($this->accessCache[$userId . ':' . (int)$fileId])) {
                $ids[(int)$fileId] = true;
            }
        }
        if (\count($ids) < self::LIST_MIN) {
            return;
        }
        foreach ($this->foldersToList(array_keys($ids)) as $folderId => $asked) {
            $this->primeFolder($userId, $folderId, $asked);
        }
    }

    /**
     * The folders worth listing, with the asked files in them.
     *
     * @param int[] $fileIds
     * @return array<int, int[]> folder id => file ids
     */
    private function foldersToList(array $fileIds): array {
        $byFolder = [];
        foreach (array_chunk($fileIds, self::ID_CHUNK) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('fileid', 'parent')
               ->from('filecache')
               ->where($qb->expr()->in('fileid', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $byFolder[(int)$row['parent']][] = (int)$row['fileid'];
            }
            $result->closeCursor();
        }
        $byFolder = array_filter($byFolder, fn (array $ids) => \count($ids) >= self::LIST_MIN);

        $worth = [];
        foreach (array_chunk(array_keys($byFolder), self::ID_CHUNK) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('parent')
               ->selectAlias($qb->func()->count('fileid'), 'files')
               ->from('filecache')
               ->where($qb->expr()->in('parent', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
               ->groupBy('parent');
            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $folderId = (int)$row['parent'];
                if (\count($byFolder[$folderId]) * self::LIST_SHARE >= (int)$row['files']) {
                    $worth[$folderId] = $byFolder[$folderId];
                }
            }
            $result->closeCursor();
        }
        return $worth;
    }

    /** @param int[] $asked */
    private function primeFolder(string $userId, int $folderId, array $asked): void {
        try {
            foreach ($this->rootFolder->getUserFolder($userId)->getById($folderId) as $folder) {
                // The folder as the team folder shows it, not through a share.
                if (!($folder instanceof Folder) || $this->groupfolderResolver->getGroupfolderId($folder) === null) {
                    continue;
                }
                $listed = [];
                foreach ($folder->getDirectoryListing() as $child) {
                    $listed[$child->getId()] = true;
                    $this->primeFromNode($userId, $child);
                }
                // Not in the listing: the user can't see it in the team folder. Admins
                // may still reach it, through the lookup per file.
                if (!$this->teamFolders->isAdmin($userId)) {
                    foreach ($asked as $fileId) {
                        if (!isset($listed[$fileId])) {
                            $this->accessCache[$userId . ':' . $fileId] ??= FileAccess::none();
                        }
                    }
                }
                return;
            }
        } catch (\Exception) {
            // Then each file is looked up on its own.
        }
    }

    public function getFileAccess(string $userId, int $fileId): FileAccess {
        $key = $userId . ':' . $fileId;
        if (!isset($this->accessCache[$key])) {
            $this->accessCache[$key] = $this->resolveFileAccess($userId, $fileId);
        }
        return $this->accessCache[$key];
    }

    /**
     * Cache and return the verdict for a node we already have (e.g. from a
     * listing), so no getById is needed. Null for mount roots, nodes outside
     * a team folder and errors.
     */
    public function primeFromNode(string $userId, Node $node): ?FileAccess {
        try {
            $key = $userId . ':' . $node->getId();
            if (isset($this->accessCache[$key])) {
                return $this->accessCache[$key];
            }
            // Listings show other permissions for mount roots.
            if (self::isMountRoot($node)) {
                return null;
            }
            $groupfolderId = $this->groupfolderResolver->getGroupfolderId($node);
            if ($groupfolderId === null) {
                return null;
            }
            return $this->accessCache[$key] = new FileAccess($groupfolderId, $node->isUpdateable() || $this->teamFolders->isAdmin($userId), $node);
        } catch (\Exception) {
            return null;
        }
    }

    public static function isMountRoot(Node $node): bool {
        return rtrim($node->getMountPoint()->getMountPoint(), '/') === rtrim($node->getPath(), '/');
    }

    /**
     * @return int the team folder the file's metadata belongs to
     * @throws MetadataAccessException
     */
    public function requireReadableFile(string $userId, int $fileId, ?int $expectedGroupfolderId = null): int {
        return $this->check($this->getFileAccess($userId, $fileId), $expectedGroupfolderId, false);
    }

    /**
     * @return int the team folder the file's metadata belongs to
     * @throws MetadataAccessException
     */
    public function requireWritableFile(string $userId, int $fileId, ?int $expectedGroupfolderId = null): int {
        return $this->check($this->getFileAccess($userId, $fileId), $expectedGroupfolderId, true);
    }

    /**
     * @param int[] $fileIds
     * @return array{allowed: array<int, int>, denied: array<int, MetadataAccessException>} allowed: file id => team folder id
     */
    public function filterWritableFiles(string $userId, array $fileIds, ?int $expectedGroupfolderId = null): array {
        $this->prime($userId, $fileIds);
        $allowed = [];
        $denied = [];
        foreach ($fileIds as $fileId) {
            try {
                $allowed[(int)$fileId] = $this->check($this->getFileAccess($userId, (int)$fileId), $expectedGroupfolderId, true);
            } catch (MetadataAccessException $e) {
                $denied[(int)$fileId] = $e;
            }
        }
        return ['allowed' => $allowed, 'denied' => $denied];
    }

    /**
     * Batch items ({file_id, groupfolder_id?, ...}): allowed ones get the file's
     * real team folder, refused ones come back as batch results.
     *
     * @param array<int, mixed> $items
     * @return array{allowed: list<array<string, mixed>>, denied: list<array{file_id: ?int, success: false, error: string}>}
     */
    public function authorizeBatch(string $userId, array $items, bool $write): array {
        $this->prime($userId, array_filter(array_map(fn ($item) => \is_array($item) ? self::itemId($item['file_id'] ?? null) : null, $items)));
        $allowed = [];
        $denied = [];
        foreach ($items as $item) {
            $fileId = is_array($item) ? self::itemId($item['file_id'] ?? null) : null;
            if ($fileId === null) {
                $denied[] = ['file_id' => null, 'success' => false, 'error' => is_array($item) && isset($item['file_id']) ? 'Invalid file_id' : 'Missing file_id', 'reason' => 'invalid_id'];
                continue;
            }
            $expected = isset($item['groupfolder_id']) ? self::itemId($item['groupfolder_id']) : null;
            if (isset($item['groupfolder_id']) && $expected === null) {
                $denied[] = ['file_id' => $fileId, 'success' => false, 'error' => 'Invalid groupfolder_id', 'reason' => 'invalid_id'];
                continue;
            }
            try {
                $access = $this->getFileAccess($userId, $fileId);
                $item['groupfolder_id'] = $this->check($access, $expected, $write);
                $allowed[] = $item;
            } catch (MetadataAccessException $e) {
                $denied[] = ['file_id' => $fileId, 'success' => false, 'error' => $e->getMessage(), 'reason' => $e->getReason()];
            }
        }
        return ['allowed' => $allowed, 'denied' => $denied];
    }

    /** An id in a batch item: a positive whole number, as a number or as text; null for anything else. */
    private static function itemId(mixed $value): ?int {
        if (is_string($value) && preg_match('/^[0-9]{1,10}\z/', $value)) {
            $value = (int)$value;
        }
        return is_int($value) && $value > 0 && $value <= 2147483647 ? $value : null;
    }

    /**
     * The files of the team folder the user may read, for overviews of the
     * whole folder. What the ACL hides is not in the user's view of the
     * folder, and Nextcloud's own search leaves it out the same way.
     */
    public function readableScope(string $userId, int $groupfolderId): ReadableScope {
        return $this->scopeCache[$userId . ':' . $groupfolderId] ??= $this->resolveReadableScope($userId, $groupfolderId);
    }

    /**
     * The user's team folders as they show in Files, for searching: what the
     * ACL hides is left out, for admins too, as a result has to open. The ACL
     * settings in the folder list save asking groupfolders per folder.
     *
     * @param list<array{id: int|string, mount_point: string, acl?: mixed, acl_default_no_permission?: mixed}> $folders
     * @return list<VisibleFolder>
     */
    public function visibleFolders(string $userId, array $folders): array {
        // Without groupfolders the folder list still names them, but no file opens.
        if (!$this->teamFolderAcl->available()) {
            return [];
        }
        $this->groupfolderRoots->primeRoots(array_map(fn (array $folder) => (int)($folder['id'] ?? 0), $folders));
        $visible = [];
        foreach ($folders as $folder) {
            $id = (int)($folder['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $acl = isset($folder['acl']) ? (bool)$folder['acl'] : $this->teamFolderAcl->isEnabled($id);
            $forbidden = $acl === false ? [] : $this->teamFolderAcl->forbiddenPaths($userId, $id,
                isset($folder['acl_default_no_permission']) ? (bool)$folder['acl_default_no_permission'] : null);
            $visible[] = new VisibleFolder($id, trim((string)($folder['mount_point'] ?? ''), '/'),
                $forbidden === null ? null : ReadableScope::exceptPaths($forbidden));
        }
        return $visible;
    }

    private function resolveReadableScope(string $userId, int $groupfolderId): ReadableScope {
        if ($this->teamFolders->isAdmin($userId) || $this->teamFolderAcl->isEnabled($groupfolderId) === false) {
            return ReadableScope::everything();
        }
        $forbidden = $this->teamFolderAcl->forbiddenPaths($userId, $groupfolderId);
        if ($forbidden !== null) {
            return ReadableScope::exceptPaths($forbidden);
        }
        // groupfolders can't tell: ask Nextcloud's search what the user sees.
        $root = $this->teamFolders->teamFolderRoot($userId, $groupfolderId);
        $readable = $root === null ? [] : array_map(fn (Node $node) => $node->getId(), $root->search(''));
        return ReadableScope::exceptFiles(array_diff($this->groupfolderRoots->getFileIds($groupfolderId), $readable));
    }

    private function resolveFileAccess(string $userId, int $fileId): FileAccess {
        try {
            $nodes = $this->rootFolder->getUserFolder($userId)->getById($fileId);
        } catch (\Exception) {
            // No user folder (NoUserException, NotPermittedException) or file.
            $nodes = [];
        }

        // A file can also show up through a share; only the team folder node counts.
        foreach ($nodes as $node) {
            $groupfolderId = $this->groupfolderResolver->getGroupfolderId($node);
            if ($groupfolderId !== null) {
                return new FileAccess($groupfolderId, $node->isUpdateable() || $this->teamFolders->isAdmin($userId), $node);
            }
        }

        if ($this->teamFolders->isAdmin($userId)) {
            $groupfolderId = $this->groupfolderRoots->getGroupfolderIdForFile($fileId);
            return $groupfolderId === null ? FileAccess::none() : new FileAccess($groupfolderId, true);
        }

        return FileAccess::none();
    }

    /**
     * @throws MetadataAccessException
     */
    private function check(FileAccess $access, ?int $expectedGroupfolderId, bool $write): int {
        if (!$access->canRead()) {
            throw MetadataAccessException::notFound();
        }
        if ($expectedGroupfolderId !== null && $access->groupfolderId !== $expectedGroupfolderId) {
            throw MetadataAccessException::wrongGroupfolder();
        }
        if ($write && !$access->canWrite) {
            throw MetadataAccessException::readOnly();
        }
        return $access->groupfolderId;
    }
}
