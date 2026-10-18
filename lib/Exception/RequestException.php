<?php

declare(strict_types=1);

namespace OCA\MetaVox\Exception;

use OCA\MetaVox\L10n\Translatable;
use OCP\AppFramework\Http;

/** A request that can't be served as asked: bad input, something missing, or busy. */
final class RequestException extends ClientErrorException {

    public static function invalid(string $message): self {
        return new self($message, Http::STATUS_BAD_REQUEST, 'invalid_input');
    }

    public static function notFound(string $message, array $data = []): self {
        return new self($message, Http::STATUS_NOT_FOUND, 'not_found', $data);
    }

    public static function readOnly(string $message, array $data = []): self {
        return new self($message, Http::STATUS_FORBIDDEN, 'read_only', $data);
    }

    /** Something the admin has turned off. */
    public static function turnedOff(string $message): self {
        return new self($message, Http::STATUS_FORBIDDEN, 'turned_off');
    }

    public static function conflict(string $message, array $data = []): self {
        return new self($message, Http::STATUS_CONFLICT, 'busy', $data);
    }

    public static function notLoggedIn(): self {
        // TRANSLATORS Error message shown to the user when the session has ended and the user must log in again
        return new self(Translatable::t('Not logged in'), Http::STATUS_UNAUTHORIZED, 'not_logged_in');
    }
}
