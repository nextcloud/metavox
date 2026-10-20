<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCA\MetaVox\Dav\MetadataPropertyPayload;
use OCP\Files\Node;

/**
 * The nc:metavox values for one folder listing: one access check per node in
 * memory (primeFromNode, no getById) and one query for all values.
 */
class DavMetadataService {

    public function __construct(
        private readonly FileAccessService $fileAccess,
        private readonly GroupfolderResolver $resolver,
        private readonly MetadataValueReader $reader,
        private readonly PresenceService $presence,
        private readonly FieldService $fields,
        private readonly AccountService $accounts,
    ) {
    }

    /**
     * @param Node $folder the listed folder, as the session user sees it
     * @param Node[] $children its listing
     * @return array<int, string> file id => property value; ids left out are not served
     */
    public function buildForListing(string $userId, Node $folder, array $children): array {
        if (!str_starts_with($folder->getPath() . '/', '/' . $userId . '/files/')) {
            return [];
        }
        $groupfolderId = $this->resolver->getGroupfolderId($folder);
        if ($groupfolderId === null) {
            return [];
        }
        // Only the grid asks for these values: its team folder is open.
        $this->presence->register($groupfolderId, $userId);

        $canWrite = [];
        $properties = [];
        foreach ([$folder, ...$children] as $node) {
            $id = (int)$node->getId();
            $access = $this->fileAccess->primeFromNode($userId, $node);
            if ($access !== null && $access->groupfolderId === $groupfolderId) {
                $canWrite[$id] = $access->canWrite;
            } elseif (FileAccessService::isMountRoot($node)) {
                // A nested team folder or other mount: nothing to show here.
                $properties[$id] = MetadataPropertyPayload::NO_VALUES;
            }
            // Anything else is left to the web route and its full check.
        }

        // People fields hold ids; the grid shows names.
        $accountFields = $this->fields->accountFieldNames();

        // Encode per query-sized chunk, so the raw values of a big folder are
        // never all in memory at once.
        foreach (array_chunk($canWrite, MetadataValueReader::CHUNK, true) as $chunk) {
            $values = $this->reader->readValues($groupfolderId, array_keys($chunk), MetadataPropertyPayload::MAX_CHARS + 1);
            foreach ($chunk as $id => $write) {
                $names = $accountFields === [] ? [] : $this->accounts->namesInValues($values[$id] ?? [], $accountFields, $userId);
                $properties[$id] = MetadataPropertyPayload::encode($groupfolderId, $write, $values[$id] ?? [], $names);
            }
        }
        unset($values);

        // The team folder root itself: only which folder it is.
        $folderId = (int)$folder->getId();
        if (!isset($canWrite[$folderId])) {
            $properties[$folderId] = MetadataPropertyPayload::encode($groupfolderId, false, []);
        }

        return $properties;
    }
}
