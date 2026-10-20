<?php

declare(strict_types=1);

namespace OCA\MetaVox\Validation;

/**
 * A field's options as one list, whatever shape they were stored in: JSON text
 * with one option per line, a list of texts, a list of {value, label}, or the
 * {includeTime} of a date field. Every reader goes through here.
 */
final class FieldOptions {

    /** What a People field can hold, in this order. */
    public const KINDS = ['user', 'group', 'team'];

    /**
     * The options of a select or multiselect field, each with the value that is
     * stored and the label that is shown. An item with only a label uses it as
     * its value; empty and repeated values are left out.
     *
     * @param mixed $options field_options as stored, or as already decoded
     * @return list<array{value: string, label: string}>
     */
    public static function items(mixed $options): array {
        $items = [];
        foreach (self::decode($options) as $option) {
            if (is_array($option)) {
                $value = $option['value'] ?? $option['label'] ?? null;
                $label = $option['label'] ?? $value;
            } else {
                $value = $label = $option;
            }
            if (!is_scalar($value) || is_bool($value)) {
                continue;
            }
            $value = trim((string)$value);
            if ($value === '' || isset($items[$value])) {
                continue;
            }
            $items[$value] = ['value' => $value, 'label' => is_scalar($label) ? trim((string)$label) : $value];
        }
        return array_values($items);
    }

    /** @return list<string> the values an option can be stored as */
    public static function values(mixed $options): array {
        return array_column(self::items($options), 'value');
    }

    /** Whether a date field holds a time as well. */
    public static function includeTime(mixed $options): bool {
        $decoded = is_string($options) ? json_decode($options, true) : $options;
        return is_array($decoded) && !array_is_list($decoded) && !empty($decoded['includeTime']);
    }

    /**
     * What a People field takes: one or several, and of which kinds. A field
     * from before these options takes one account.
     *
     * @return array{multiple: bool, kinds: list<string>}
     */
    public static function accounts(mixed $options): array {
        $decoded = is_string($options) ? json_decode($options, true) : $options;
        $decoded = is_array($decoded) && !array_is_list($decoded) ? $decoded : [];
        $kinds = is_array($decoded['kinds'] ?? null) ? array_values(array_intersect(self::KINDS, array_filter($decoded['kinds'], 'is_string'))) : [];
        return ['multiple' => !empty($decoded['multiple']), 'kinds' => $kinds === [] ? ['user'] : $kinds];
    }

    /** @return list<mixed> the options as a list; an object such as {includeTime} has none */
    private static function decode(mixed $options): array {
        if (is_string($options)) {
            $decoded = json_decode($options, true);
            // A JSON string holds the lines; text that is no JSON at all is the lines itself.
            $options = is_array($decoded) ? $decoded : preg_split('/\r?\n/', is_string($decoded) ? $decoded : $options);
        }
        return is_array($options) && array_is_list($options) ? $options : [];
    }
}
