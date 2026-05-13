<?php
declare(strict_types=1);

namespace OCA\MetaVox\Service;

/**
 * Thrown when an external API write request fails validation
 * (e.g. wrong date format, mismatched includeTime flag).
 *
 * Carries a per-field error map so the controller can return a
 * structured 400 response.
 */
class ApiValidationException extends \RuntimeException {
    /** @var array<string,string> field_name → error message */
    private array $errors;

    /**
     * @param array<string,string> $errors
     */
    public function __construct(array $errors) {
        $this->errors = $errors;
        parent::__construct('Validation failed: ' . implode('; ', array_map(
            fn($k, $v) => "$k: $v",
            array_keys($errors),
            $errors
        )));
    }

    /** @return array<string,string> */
    public function getErrors(): array {
        return $this->errors;
    }
}
