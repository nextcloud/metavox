<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OC\Files\Search\SearchComparison;
use OC\Files\Search\SearchQuery;
use OCA\MetaVox\Access\VisibleFolder;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\Search\ISearchComparison;
use OCP\IUser;

/**
 * What a saved search finds, as the files the user sees in their team
 * folders. The value index finds them; Nextcloud's own search in each team
 * folder then hands them out, the way its WebDAV SEARCH does, with the
 * folder's ACL applied once more.
 */
class SavedSearchResults {

    /** Index pages read at most, when Nextcloud's search leaves files out. */
    private const ROUNDS_MAX = 10;

    public function __construct(
        private readonly UserFieldService $userFields,
        private readonly FileAccessService $fileAccess,
        private readonly SearchIndexService $searchIndex,
        private readonly GroupfolderResolver $resolver,
        private readonly IRootFolder $rootFolder,
        private readonly FieldService $fields,
    ) {
    }

    /**
     * The newest $limit files, newest first, and whether more match.
     *
     * @param array{field: string, value: string, groupfolderId: ?int} $search
     * @return array{nodes: list<Node>, more: bool}
     */
    public function find(IUser $user, array $search, int $limit): array {
        $userId = $user->getUID();
        // The user's own team folders, admins too: a file has to open.
        $folders = $this->userFields->getAccessibleGroupfolders($userId);
        if ($search['groupfolderId'] !== null) {
            $folders = array_values(array_filter($folders, fn (array $folder) => (int)($folder['id'] ?? 0) === $search['groupfolderId']));
        }
        // Only where the field is, before the ACL: each team folder costs a lookup there.
        $withField = array_flip($this->fields->groupfoldersWithField($search['field'], array_map(fn (array $folder) => (int)($folder['id'] ?? 0), $folders)));
        $folders = array_values(array_filter($folders, fn (array $folder) => isset($withField[(int)($folder['id'] ?? 0)])));
        $visible = [];
        foreach ($this->fileAccess->visibleFolders($userId, $folders) as $folder) {
            $visible[$folder->id] = $folder;
        }
        $userFolder = $this->rootFolder->getUserFolder($userId);

        // One more than shown: only a file the user may see says that more
        // follow. Where the query could not apply the ACL, Nextcloud leaves
        // files out; then older ones are read, a few rounds at most.
        $found = [];
        $before = null;
        $rounds = 0;
        do {
            $page = $this->searchIndex->searchByFieldValue($search['field'], $search['value'], array_values($visible), $limit + 1 - \count($found), $before);
            $idsByFolder = [];
            foreach ($page['files'] as $file) {
                $idsByFolder[$file['groupfolder_id']][] = $file['id'];
            }
            $nodes = [];
            foreach ($idsByFolder as $folderId => $ids) {
                if (isset($visible[$folderId])) {
                    $nodes += $this->inTeamFolder($user, $userFolder, $visible[$folderId], $ids);
                }
            }
            foreach ($page['files'] as $file) {
                if (isset($nodes[$file['id']])) {
                    $found[] = $nodes[$file['id']];
                }
            }
            $before = $page['next'];
        } while ($before !== null && \count($found) <= $limit && ++$rounds < self::ROUNDS_MAX);

        return ['nodes' => \array_slice($found, 0, $limit), 'more' => \count($found) > $limit];
    }

    /**
     * These files as the team folder's mount hands them out: one query, and
     * never the copy of a share.
     *
     * @param list<int> $ids
     * @return array<int, Node> by file id
     */
    private function inTeamFolder(IUser $user, Folder $userFolder, VisibleFolder $folder, array $ids): array {
        try {
            $root = $userFolder->get($folder->mountPoint);
        } catch (NotFoundException|NotPermittedException) {
            // Not mounted for them: an admin who is not a member.
            return [];
        }
        if (!$root instanceof Folder || $this->resolver->getGroupfolderId($root) !== $folder->id) {
            return [];
        }
        // The last argument (Nextcloud 35) brings the upload and creation time,
        // as in a folder listing; before 35 PHP ignores it.
        $query = new SearchQuery(
            new SearchComparison(ISearchComparison::COMPARE_IN, 'fileid', array_map('intval', $ids)),
            0, 0, [], $user, false, ['creation_time', 'upload_time'],
        );
        $nodes = [];
        foreach ($root->search($query) as $node) {
            if ($this->resolver->getGroupfolderId($node) === $folder->id) {
                $nodes[(int)$node->getId()] = $node;
            }
        }
        return $nodes;
    }
}
