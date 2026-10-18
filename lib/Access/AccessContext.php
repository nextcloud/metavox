<?php

declare(strict_types=1);

namespace OCA\MetaVox\Access;

/**
 * What the gate checked for this request, so controllers use that instead of
 * resolving the user and the team folder again.
 */
final class AccessContext {

    private ?string $userId = null;
    private ?int $groupfolderId = null;

    public function setUser(string $userId): void {
        $this->userId = $userId;
    }

    public function getUserId(): ?string {
        return $this->userId;
    }

    public function setGroupfolder(int $groupfolderId): void {
        $this->groupfolderId = $groupfolderId;
    }

    /** The team folder of the checked file, for routes that only name the file. */
    public function getGroupfolderId(): ?int {
        return $this->groupfolderId;
    }
}
