<?php

declare(strict_types=1);

namespace OCA\MetaVox\Access;

/**
 * The access rule of a route: the level, and which request parameters hold
 * the team folder id and the file id the level is checked against.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class RequireAccess {

    public function __construct(
        public readonly Access $level,
        public readonly ?string $folder = null,
        public readonly ?string $file = null,
        /** Why a LoggedIn route is safe for everyone; required for Access::LoggedIn. */
        public readonly ?string $reason = null,
    ) {
    }
}
