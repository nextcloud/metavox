<?php

declare(strict_types=1);

namespace OCA\MetaVox\Listener;

use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\GroupfolderResolver;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * A copy gets the metadata of the original, when both are in the same team
 * folder. A copied folder's tree is read once on each side and matched on the
 * path inside it; the values are read and saved per block, not per file.
 */
class FileCopyListener implements IEventListener {

    private const CHUNK = 500;

    public function __construct(
        private readonly FieldService $fieldService,
        private readonly IDBConnection $db,
        private readonly GroupfolderResolver $groupfolderResolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if (!($event instanceof NodeCopiedEvent)) {
            return;
        }

        try {
            $target = $this->tree($event->getTarget());
            $pairs = [];
            foreach ($this->tree($event->getSource()) as $path => [$sourceId, $groupfolderId]) {
                // Only within the same team folder: other folders have other fields.
                if ($groupfolderId !== null && ($target[$path][1] ?? null) === $groupfolderId) {
                    $pairs[$groupfolderId][$sourceId] = $target[$path][0];
                }
            }
            foreach ($pairs as $groupfolderId => $byId) {
                $copied = $this->copyValues($groupfolderId, $byId);
                $this->logger->debug('MetaVox: metadata copied', ['groupfolderId' => $groupfolderId, 'files' => \count($byId), 'values' => $copied]);
            }
        } catch (\Exception $e) {
            $this->logger->error('MetaVox: Error copying metadata', ['exception' => $e]);
        }
    }

    /**
     * The node and everything under it by path inside it ('' is the node
     * itself), each with its id and team folder.
     *
     * @return array<string, array{int, ?int}>
     */
    private function tree(Node $node): array {
        $nodes = ['' => [$node->getId(), $this->groupfolderResolver->getGroupfolderId($node)]];
        $folders = $node instanceof Folder ? [['', $node]] : [];
        while ($folders !== []) {
            [$path, $folder] = array_pop($folders);
            try {
                $children = $folder->getDirectoryListing();
            } catch (\Exception $e) {
                $this->logger->warning('MetaVox: Error listing folder ' . $folder->getPath(), ['exception' => $e]);
                continue;
            }
            foreach ($children as $child) {
                $childPath = $path === '' ? $child->getName() : $path . '/' . $child->getName();
                $nodes[$childPath] = [$child->getId(), $this->groupfolderResolver->getGroupfolderId($child)];
                if ($child instanceof Folder) {
                    $folders[] = [$childPath, $child];
                }
            }
        }
        return $nodes;
    }

    /**
     * @param array<int, int> $pairs source file id => target file id
     * @return int how many values were copied
     */
    private function copyValues(int $groupfolderId, array $pairs): int {
        $values = [];
        foreach (array_chunk(array_keys($pairs), self::CHUNK) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('file_id', 'field_name', 'field_value')
               ->from('metavox_file_gf_meta')
               ->where($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)))
               ->andWhere($qb->expr()->in('file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
               ->andWhere($qb->expr()->isNotNull('field_value'))
               ->andWhere($qb->expr()->neq('field_value', $qb->createNamedParameter('')))
               ->andWhere($qb->expr()->neq('field_value', $qb->createNamedParameter('null')));
            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $values[$pairs[(int)$row['file_id']]][(string)$row['field_name']] = (string)$row['field_value'];
            }
            $result->closeCursor();
        }
        if ($values === []) {
            return 0;
        }

        // The copy has exactly the original's values.
        $this->fieldService->clearFilesMetadata($groupfolderId, array_keys($values));
        // Copied as they were stored: an old value is copied too, not checked again.
        return $this->fieldService->saveValues($groupfolderId, $values, asStored: true);
    }
}
