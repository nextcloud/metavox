<?php

declare(strict_types=1);

namespace OCA\MetaVox\Exception;

/**
 * A request MetaVox refuses or can't serve: the HTTP status, a message that
 * ExceptionMiddleware translates, a fixed reason code for integrations, and
 * optional extra fields for the answer.
 */
abstract class ClientErrorException extends \Exception {

    protected function __construct(string $message, int $status, private readonly string $reason, private readonly array $data = []) {
        parent::__construct($message, $status);
    }

    public function getStatus(): int {
        return $this->getCode();
    }

    public function getReason(): string {
        return $this->reason;
    }

    /** Extra fields for the answer, next to error and reason. */
    public function getData(): array {
        return $this->data;
    }
}
