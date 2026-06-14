<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

/**
 * Per-request counter for the defaults listener fast-path.
 *
 * Registered as a singleton, so a single instance is shared across all
 * NodeCreatedEvent listener invocations within one request. A bulk upload
 * fires many events in one request; once more than the threshold have been
 * seen, the listener stops writing defaults inline and lets the discovery job
 * handle the rest. This keeps a 1M-file bulk upload from forcing 1M synchronous
 * inserts into the request lifecycle.
 */
class DefaultsRequestCounter {

    /** Above this many files in one request, the listener defers to discovery. */
    public const LISTENER_THRESHOLD = 20;

    private int $count = 0;

    /**
     * Record one more handled file and report whether the fast-path should
     * still run. Returns false once the threshold is exceeded.
     */
    public function tryConsume(): bool {
        if ($this->count >= self::LISTENER_THRESHOLD) {
            return false;
        }
        $this->count++;
        return true;
    }

    public function count(): int {
        return $this->count;
    }
}
