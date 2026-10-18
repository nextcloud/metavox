<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCA\MetaVox\L10n\Translatable;
use OCA\MetaVox\Validation\FieldValue;

/**
 * Highlight rules on the columns of a view (the "format" key), checked and
 * cleaned before a view is stored. The browser decides what a rule colours
 * (src/utils/valueRules.js); this keeps out what it could not read.
 *
 * Whether an operator fits the field type is not checked: after a type change
 * the old rules must not block saving the view. The browser skips them.
 */
final class ViewColumnFormat {

    /** What the value of a rule is, per operator. The same as src/utils/valueOperators.js. */
    public const OPERAND_KINDS = [
        'empty' => 'none',
        '!empty' => 'none',
        'isTrue' => 'none',
        'isFalse' => 'none',
        'nextDays' => 'days',
        'olderThanDays' => 'days',
        'greater' => 'number',
        'less' => 'number',
        'greaterOrEqual' => 'number',
        'lessOrEqual' => 'number',
        'before' => 'date',
        'after' => 'date',
        'is' => 'equal',
        '!is' => 'text',
        'contains' => 'text',
        '!contains' => 'text',
        'oneOf' => 'list',
        'containsAll' => 'list',
    ];
    public const TONES = ['error', 'warning', 'success', 'info'];
    public const PRESETS = ['overdue', 'dueToday', 'dueSoon', 'missing', 'checked', 'unchecked', 'assignedToViewer'];

    public const MAX_RULES = 10;
    public const MAX_LIST = 50;
    public const MAX_TEXT = 200;
    public const MAX_LABEL = 40;
    public const MAX_DAYS = 3650;
    public const MAX_BYTES = 32768;

    public const MSG_INVALID = 'This value is not valid';
    public const MSG_TOO_MANY = 'A column can have at most 10 rules';
    public const MSG_DAYS = 'Enter a whole number from 1 to 3650';
    public const MSG_NUMBER = 'Enter a number';
    public const MSG_DATE = 'Enter a valid date';
    public const MSG_TEXT = 'Enter 1 to 200 characters';
    public const MSG_LIST = 'Choose 1 to 50 options of at most 200 characters';
    public const MSG_LABEL = 'Use at most 40 characters';
    public const MSG_SIZE = 'The rules of this view take up more than 32 KB';

    /**
     * The messages above, written out for the translation extractor:
     * ExceptionMiddleware translates them when it answers, and a constant
     * cannot hold a call. Never called; tests/js/l10n-bot-visibility.test.js
     * fails when a message constant is missing here.
     *
     * @return list<string>
     */
    private static function translatableMessages(): array {
        return [
            // TRANSLATORS Error message under a highlight rule of a column in a view, when its value is not right
            Translatable::t('This value is not valid'),
            // TRANSLATORS Error message when a column of a view has too many highlight rules (rules that colour values)
            Translatable::t('A column can have at most 10 rules'),
            // TRANSLATORS Error message under a highlight rule of a column in a view, when its value is not right; the number is a count of days
            Translatable::t('Enter a whole number from 1 to 3650'),
            // TRANSLATORS Error message under a highlight rule of a column in a view, when its value is not right
            Translatable::t('Enter a number'),
            // TRANSLATORS Error message under a highlight rule of a column in a view, when its value is not right
            Translatable::t('Enter a valid date'),
            // TRANSLATORS Error message under a highlight rule of a column in a view, when its value is not right
            Translatable::t('Enter 1 to 200 characters'),
            // TRANSLATORS Error message under a highlight rule of a column in a view, when its value is not right
            Translatable::t('Choose 1 to 50 options of at most 200 characters'),
            // TRANSLATORS Error message when the label of a highlight rule of a view column is too long
            Translatable::t('Use at most 40 characters'),
            // TRANSLATORS Error message when the highlight rules of a view are too large to save; KB = kilobytes
            Translatable::t('The rules of this view take up more than 32 KB'),
        ];
    }

    private const KIND_MESSAGES = [
        'days' => self::MSG_DAYS,
        'number' => self::MSG_NUMBER,
        'date' => self::MSG_DATE,
        'equal' => self::MSG_TEXT,
        'text' => self::MSG_TEXT,
        'list' => self::MSG_LIST,
    ];

    // The whitespace String.prototype.trim removes in the browser.
    private const SPACE = '\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';
    // UTF-8 bytes with nothing escaped: what the browser measures.
    private const SIZE_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR;
    private const MAX_ID = 2147483647;

    /**
     * Checks and cleans the columns of a view. A column without "format"
     * keeps its stored rules, [] clears them.
     *
     * @param array $columns the columns as sent
     * @param array $existingColumns the columns as stored, on an update
     * @return array the columns to store
     * @throws ApiValidationException
     */
    public static function normalizeColumns(array $columns, array $existingColumns = []): array {
        if (!array_is_list($columns)) {
            self::refuse('columns', self::MSG_INVALID);
        }
        $sent = [];
        foreach ($columns as $i => $column) {
            if (!is_array($column) || array_is_list($column)) {
                self::refuse("columns.$i", self::MSG_INVALID);
            }
            // Both are bound in queries on every read of the team folder's
            // views: a bad one would break them all.
            if (!self::isFieldIdShape($column['field_id'] ?? null)) {
                self::refuse("columns.$i.field_id", self::MSG_INVALID);
            }
            $name = $column['field_name'] ?? null;
            if ($name !== null && (!is_string($name) || str_contains($name, "\0"))) {
                self::refuse("columns.$i.field_name", self::MSG_INVALID);
            }
            if (array_key_exists('format', $column)) {
                $sent[$i] = true;
                $format = self::normalizeFormat($column['format'], "columns.$i.format");
                if ($format === []) {
                    unset($columns[$i]['format']);
                } else {
                    $columns[$i]['format'] = $format;
                }
            }
        }

        foreach (self::claims($columns, $existingColumns, $sent) as $i => $format) {
            $columns[$i]['format'] = $format;
        }

        if (self::formatBytes($columns) > self::MAX_BYTES) {
            self::refuse('columns', self::MSG_SIZE);
        }
        return $columns;
    }

    /** UTF-8 bytes of all rules of a view, measured the way the browser measures them. */
    public static function formatBytes(array $columns): int {
        $formats = [];
        foreach ($columns as $column) {
            if (is_array($column) && isset($column['format'])) {
                $formats[] = $column['format'];
            }
        }
        try {
            return strlen(json_encode($formats, self::SIZE_FLAGS));
        } catch (\JsonException) {
            self::refuse('columns', self::MSG_INVALID);
        }
    }

    /**
     * The stored rules each column without "format" keeps. Like the grid, a
     * stored column is found by field id, else by name. Each stored column
     * goes to one column only, also when that column sent rules of its own:
     * sending a field twice must not copy rules, nor bring cleared ones back.
     *
     * @param array<int,true> $sent the columns that sent "format"
     * @return array<int,array> column index => stored rules
     */
    private static function claims(array $columns, array $existingColumns, array $sent): array {
        $byId = [];
        $byName = [];
        $stored = [];
        foreach (array_values($existingColumns) as $k => $column) {
            $format = is_array($column) ? ($column['format'] ?? null) : null;
            if (!is_array($format) || $format === [] || !array_is_list($format)) {
                continue;
            }
            $stored[$k] = $format;
            $id = self::fieldId($column['field_id'] ?? null);
            if ($id !== null) {
                $byId[$id] ??= $k;
            }
            if (self::fieldName($column) !== null) {
                $byName[self::fieldName($column)] ??= $k;
            }
        }
        if ($stored === []) {
            return [];
        }

        // Ids first, so a field keeps its own rules even if another column
        // has its old name. Columns that sent rules choose first, so a clear
        // wins over a copy of the same field.
        $order = array_keys($sent + $columns);
        $claimed = [];
        $owner = [];
        foreach ($order as $i) {
            $k = $byId[self::fieldId($columns[$i]['field_id'] ?? null) ?? 0] ?? null;
            if ($k !== null && !isset($claimed[$k])) {
                $claimed[$k] = true;
                $owner[$i] = $k;
            }
        }
        foreach ($order as $i) {
            $k = isset($owner[$i]) ? null : ($byName[self::fieldName($columns[$i]) ?? ''] ?? null);
            if ($k !== null && !isset($claimed[$k])) {
                $claimed[$k] = true;
                $owner[$i] = $k;
            }
        }

        $kept = [];
        foreach ($owner as $i => $k) {
            if (!isset($sent[$i])) {
                $kept[$i] = $stored[$k];
            }
        }
        return $kept;
    }

    private static function fieldName(array $column): ?string {
        $name = $column['field_name'] ?? null;
        return is_string($name) && $name !== '' ? $name : null;
    }

    /** No id, or 0 up to the largest database id, as a number or digits. */
    private static function isFieldIdShape(mixed $id): bool {
        if ($id === null || $id === '') {
            return true;
        }
        if (is_string($id) && preg_match('/^[0-9]{1,10}\z/', $id)) {
            $id = (int)$id;
        }
        return is_int($id) && $id >= 0 && $id <= self::MAX_ID;
    }

    /** A field id that can match: 1 up to the largest database id, as a number or digits. */
    private static function fieldId(mixed $value): ?int {
        if (is_string($value) && preg_match('/^[0-9]{1,10}\z/', $value)) {
            $value = (int)$value;
        }
        return is_int($value) && $value >= 1 && $value <= self::MAX_ID ? $value : null;
    }

    private static function normalizeFormat(mixed $format, string $at): array {
        if (!is_array($format) || !array_is_list($format)) {
            self::refuse($at, self::MSG_INVALID);
        }
        if (count($format) > self::MAX_RULES) {
            self::refuse($at, self::MSG_TOO_MANY);
        }
        $rules = [];
        foreach ($format as $j => $rule) {
            $rules[] = self::normalizeRule($rule, "$at.$j");
        }
        return $rules;
    }

    private static function normalizeRule(mixed $rule, string $at): array {
        if (!is_array($rule) || array_is_list($rule)) {
            self::refuse($at, self::MSG_INVALID);
        }

        $op = $rule['op'] ?? null;
        if (!is_string($op) || !array_key_exists($op, self::OPERAND_KINDS)) {
            self::refuse("$at.op", self::MSG_INVALID);
        }
        $out = ['op' => $op];

        $kind = self::OPERAND_KINDS[$op];
        $value = $rule['value'] ?? null;
        if ($kind === 'none') {
            if ($value !== null) {
                self::refuse("$at.value", self::MSG_INVALID);
            }
        } else {
            $out['value'] = self::operand($kind, $value, "$at.value");
        }

        $tone = $rule['tone'] ?? null;
        if (!in_array($tone, self::TONES, true)) {
            self::refuse("$at.tone", self::MSG_INVALID);
        }
        $out['tone'] = $tone;

        $label = $rule['label'] ?? null;
        if ($label !== null) {
            $clean = is_string($label) ? self::cleanLabel($label) : null;
            if ($clean === null) {
                self::refuse("$at.label", self::MSG_INVALID);
            }
            if (mb_strlen($clean, 'UTF-8') > self::MAX_LABEL) {
                self::refuse("$at.label", self::MSG_LABEL);
            }
            if ($clean !== '') {
                $out['label'] = $clean;
            }
        }

        // An unknown preset is only a name; the rule itself still counts.
        $preset = $rule['preset'] ?? null;
        if (is_string($preset) && in_array($preset, self::PRESETS, true)) {
            $out['preset'] = $preset;
        }
        return $out;
    }

    // A value of the wrong JSON type gets MSG_INVALID; a missing one, or one
    // of the right type that is wrong, gets the message of its kind.
    private static function operand(string $kind, mixed $value, string $at): mixed {
        $message = self::KIND_MESSAGES[$kind];
        if ($value === null) {
            self::refuse($at, $message);
        }
        switch ($kind) {
            case 'days':
                if (!is_int($value) && !is_float($value)) {
                    break;
                }
                // Range first: casting a huge float warns on PHP 8.5.
                if ($value < 1 || $value > self::MAX_DAYS || floor($value) != $value) {
                    self::refuse($at, $message);
                }
                return (int)$value;

            case 'number':
                if (is_int($value)) {
                    return (string)$value;
                }
                // JSON 1e400 decodes to INF.
                if (is_float($value)) {
                    if (!is_finite($value)) {
                        self::refuse($at, $message);
                    }
                    return json_encode($value);
                }
                if (!is_string($value)) {
                    break;
                }
                if (!is_numeric($value) || !is_finite((float)$value)) {
                    self::refuse($at, $message);
                }
                // The whitespace is_numeric allows, so the stored text is the number alone.
                return trim($value, " \t\n\r\v\f");

            case 'date':
                if (self::isToday($value)) {
                    return ['today' => 0];
                }
                if (!is_string($value)) {
                    break;
                }
                if (!FieldValue::isDay($value)) {
                    self::refuse($at, $message);
                }
                return $value;

            case 'equal':
                if (self::isToday($value)) {
                    return ['today' => 0];
                }
                if (self::isMe($value)) {
                    return ['me' => true];
                }
                return self::text($value, $at, $message);

            case 'text':
                return self::text($value, $at, $message);

            case 'list':
                if (!is_array($value) || !array_is_list($value)) {
                    break;
                }
                if ($value === [] || count($value) > self::MAX_LIST) {
                    self::refuse($at, $message);
                }
                return array_map(fn ($item) => self::text($item, $at, $message), $value);
        }
        self::refuse($at, self::MSG_INVALID);
    }

    // Text as the browser compares it: trimmed, 1 to 200 characters. A whole
    // number may come as a JSON number; a decimal one must come as text, so
    // it is stored as written.
    private static function text(mixed $value, string $at, string $message): string {
        if (is_int($value)) {
            $value = (string)$value;
        }
        $trimmed = is_string($value) ? self::jsTrim($value) : null;
        if ($trimmed === null) {
            self::refuse($at, self::MSG_INVALID);
        }
        if ($trimmed === '' || mb_strlen($trimmed, 'UTF-8') > self::MAX_TEXT) {
            self::refuse($at, $message);
        }
        return $trimmed;
    }

    /** Trims like String.prototype.trim; null for invalid UTF-8. */
    public static function jsTrim(string $value): ?string {
        // The lookbehind keeps this linear when PCRE runs without JIT.
        return preg_replace('/^[' . self::SPACE . ']+|(?<![' . self::SPACE . '])[' . self::SPACE . ']+\z/u', '', $value);
    }

    /** A label as plainLabel in valueRules.js leaves it: not cut, normalizeRule refuses more than 40; null for invalid UTF-8. */
    public static function cleanLabel(string $label): ?string {
        $plain = preg_replace('/(?!\x{200D})[\p{Cc}\p{Cf}]/u', '', $label);
        return $plain === null ? null : self::jsTrim($plain);
    }

    private static function isToday(mixed $value): bool {
        return is_array($value) && count($value) === 1 && array_key_exists('today', $value)
            && ($value['today'] === 0 || $value['today'] === 0.0);
    }

    private static function isMe(mixed $value): bool {
        return is_array($value) && count($value) === 1 && ($value['me'] ?? null) === true;
    }

    private static function refuse(string $key, string $message): never {
        throw new ApiValidationException([$key => $message]);
    }
}
