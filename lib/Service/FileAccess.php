<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCP\Files\Node;

/**
 * What a user may do with one file's metadata. No team folder, no access.
 */
final class FileAccess {

    /** $node is null for an admin who isn't a member of the team folder. */
    public function __construct(
        public readonly ?int $groupfolderId,
        public readonly bool $canWrite = false,
        public readonly ?Node $node = null,
    ) {
    }

    public static function none(): self {
        return new self(null);
    }

    public function canRead(): bool {
        return $this->groupfolderId !== null;
    }
}
