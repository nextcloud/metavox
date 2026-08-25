<?php

declare(strict_types=1);

namespace OCA\MetaVox\Event;

use OCP\EventDispatcher\Event;

/**
 * Dispatched whenever MetaVox metadata values for a file are written or
 * cleared, so external integrations can react through a Nextcloud event
 * listener (and, from there, a webhook) instead of polling the OCS API for
 * changes (see nextcloud/metavox#86).
 *
 * `metavox_file_gf_meta` is written through upsert SQL (INSERT ... ON
 * DUPLICATE KEY UPDATE / ON CONFLICT ... DO UPDATE), so a field's first write
 * and a later write are indistinguishable without an extra SELECT on every
 * write. Rather than pay that cost on a hot path, both are reported as
 * `updated`; only an explicit clear/delete reports `deleted`.
 */
class FileMetadataChangedEvent extends Event {
    public function __construct(
        private int $fileId,
        private int $groupfolderId,
        private string $operation,
        private array $fieldNames = [],
    ) {
        parent::__construct();
    }

    public function getFileId(): int {
        return $this->fileId;
    }

    public function getGroupfolderId(): int {
        return $this->groupfolderId;
    }

    /**
     * 'updated' (covers both the creation and the update of a value) or
     * 'deleted'.
     */
    public function getOperation(): string {
        return $this->operation;
    }

    /**
     * The field names affected, or an empty list when the whole file's
     * metadata was cleared at once.
     *
     * @return list<string>
     */
    public function getFieldNames(): array {
        return $this->fieldNames;
    }
}
