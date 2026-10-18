<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\L10n\Translatable;
use OCA\MetaVox\Service\ApiValidationException;
use OCA\MetaVox\Service\ViewColumnFormat;

/**
 * Request parameters, read strictly: a value of the wrong kind is a 400 that
 * names the parameter. Never a 500 from a type error, and never a value quietly
 * turned into another ("abc" into 0, "false" into true, junk dropped from a list).
 */
trait ReadsParams {

    /** Whether the request carries the parameter at all. */
    protected function hasParam(string $name): bool {
        return array_key_exists($name, $this->request->getParams());
    }

    /**
     * The parameters of these the request carries, as sent: one left out is not
     * there, so a change can send only what changes.
     *
     * @param list<string> $names
     * @return array<string, mixed>
     */
    protected function sentParams(array $names): array {
        return array_intersect_key($this->request->getParams(), array_flip($names));
    }

    /** @throws ApiValidationException when the request leaves it out */
    protected function requireParam(string $name): void {
        if (!$this->hasParam($name)) {
            throw self::refusedParam($name, Translatable::t('This field is required'));
        }
    }

    /**
     * Text without the spaces around it; null when left out.
     *
     * @throws ApiValidationException
     */
    protected function textParam(string $name, bool $required = false, int $max = 255): ?string {
        $value = $this->request->getParam($name);
        if ($value === null && !$required) {
            return null;
        }
        if ($value !== null && (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0"))) {
            // TRANSLATORS Error when a request sends something else than text, such as a list, for a value that must be text
            throw self::refusedParam($name, Translatable::t('Enter text'));
        }
        $value = trim((string)$value);
        if ($required && $value === '') {
            throw self::refusedParam($name, Translatable::t('This field is required'));
        }
        if (mb_strlen($value, 'UTF-8') > $max) {
            // TRANSLATORS Error when a text sent to the server is longer than it may be
            throw self::refusedParam($name, Translatable::t('This text is too long'));
        }
        return $value;
    }

    /** @throws ApiValidationException for anything but true, false, 1, 0 or that as text */
    protected function boolParam(string $name, bool $default): bool {
        $value = $this->request->getParam($name);
        return match (is_string($value) ? strtolower(trim($value)) : $value) {
            null => $default,
            true, 1, '1', 'true' => true,
            false, 0, '0', 'false', '' => false,
            // TRANSLATORS Error when a request sends something else than yes or no; keep true and false as they are
            default => throw self::refusedParam($name, Translatable::t('Use true or false')),
        };
    }

    /** @throws ApiValidationException for anything but a whole number from $min */
    protected function intParam(string $name, bool $required = false, int $min = 0): ?int {
        $value = $this->request->getParam($name);
        if ($value === null) {
            if ($required) {
                throw self::refusedParam($name, Translatable::t('This field is required'));
            }
            return null;
        }
        if (is_string($value) && preg_match('/^\s*[0-9]{1,10}\s*\z/', $value)) {
            $value = (int)$value;
        }
        if (!is_int($value) || $value < $min || $value > 2147483647) {
            // TRANSLATORS Error when a request sends something else than a whole number, such as "12abc"
            throw self::refusedParam($name, Translatable::t('Enter a whole number'));
        }
        return $value;
    }

    /**
     * Unique positive ids, from a list or from "1,2,3"; empty pieces are left out.
     *
     * @return int[]
     * @throws ApiValidationException for an id that is none, none when required, or more than $max
     */
    protected function getIdListParam(string $name, bool $required = false, ?int $max = null): array {
        $ids = [];
        foreach ($this->listItems($name) as $item) {
            if (is_string($item)) {
                $item = trim($item);
                if ($item === '') {
                    continue;
                }
            }
            if (!(is_int($item) || (is_string($item) && ctype_digit($item))) || (int)$item <= 0 || (int)$item > 2147483647) {
                // TRANSLATORS Error when a request sends a list of IDs (the numbers that identify files or fields) with something in it that is no ID
                throw self::refusedParam($name, Translatable::t('Enter a list of IDs'));
            }
            $ids[] = (int)$item;
        }
        $ids = array_values(array_unique($ids));
        if ($required && $ids === []) {
            throw self::refusedParam($name, Translatable::t('This field is required'));
        }
        if ($max !== null && count($ids) > $max) {
            // TRANSLATORS Error when a request sends more IDs (the numbers that identify files) than it may at once
            throw self::refusedParam($name, Translatable::t('Too many IDs in one request'));
        }
        return $ids;
    }

    /**
     * Unique names, from a list or from "a,b,c"; empty pieces are left out.
     *
     * @return string[]
     * @throws ApiValidationException for a name that is no text
     */
    protected function getStringListParam(string $name): array {
        $names = [];
        foreach ($this->listItems($name) as $item) {
            if (!is_string($item) || mb_strlen($item, 'UTF-8') > 255 || !mb_check_encoding($item, 'UTF-8')) {
                // TRANSLATORS Error when a request sends a list of names with something in it that is no name, such as a number or a list
                throw self::refusedParam($name, Translatable::t('Enter a list of names'));
            }
            if (trim($item) !== '') {
                $names[] = trim($item);
            }
        }
        return array_values(array_unique($names));
    }

    /**
     * Field names with their values, as {"name": value}; left out is none.
     *
     * @return array<string, mixed>
     * @throws ApiValidationException for anything else, such as a list or text
     */
    protected function mapParam(string $name): array {
        $value = $this->request->getParam($name);
        if ($value === null || $value === []) {
            return [];
        }
        if (!is_array($value) || array_is_list($value)) {
            // TRANSLATORS Error when a request does not send field names with their values, e.g. {"status": "Open"}
            throw self::refusedParam($name, Translatable::t('Send each field name with its value'));
        }
        return $value;
    }

    /**
     * A list, such as the items of a batch; left out is none.
     *
     * @throws ApiValidationException for anything else, or none when required
     */
    protected function listParam(string $name, bool $required = false): array {
        $value = $this->request->getParam($name);
        if ($value !== null && (!is_array($value) || !array_is_list($value))) {
            throw self::refusedParam($name, ViewColumnFormat::MSG_INVALID);
        }
        if ($required && ($value === null || $value === [])) {
            throw self::refusedParam($name, Translatable::t('This field is required'));
        }
        return $value ?? [];
    }

    /**
     * One of a few words; left out is the default.
     *
     * @param list<string> $allowed
     * @throws ApiValidationException for any other
     */
    protected function choiceParam(string $name, array $allowed, ?string $default = null): ?string {
        $value = $this->request->getParam($name);
        if ($value === null) {
            return $default;
        }
        if (!is_string($value) || !in_array(strtolower($value), $allowed, true)) {
            throw self::refusedParam($name, ViewColumnFormat::MSG_INVALID);
        }
        return strtolower($value);
    }

    private function listItems(string $name): array {
        $value = $this->request->getParam($name);
        if ($value === null) {
            return [];
        }
        if (is_string($value)) {
            return explode(',', $value);
        }
        if (is_int($value)) {
            return [$value];
        }
        if (is_array($value) && array_is_list($value)) {
            return $value;
        }
        throw self::refusedParam($name, ViewColumnFormat::MSG_INVALID);
    }

    private static function refusedParam(string $name, string $message): ApiValidationException {
        return new ApiValidationException([$name => $message]);
    }
}
