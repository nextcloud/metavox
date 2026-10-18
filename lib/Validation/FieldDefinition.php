<?php

declare(strict_types=1);

namespace OCA\MetaVox\Validation;

use OCA\MetaVox\L10n\Translatable;
use OCA\MetaVox\Service\ApiValidationException;
use OCA\MetaVox\Service\FieldName;

/**
 * A field definition as it is stored: the type, the label, the options in one
 * form. For a new field from what was sent; for a change from what was sent on
 * top of what is stored, so a change sends only what changes.
 */
final class FieldDefinition {

    /** What a request may send about a field. */
    public const PARAMS = ['field_name', 'field_label', 'field_type', 'field_description', 'field_options', 'is_required', 'sort_order', 'applies_to_groupfolder'];

    public const TYPES = ['text', 'textarea', 'number', 'date', 'select', 'multiselect', 'checkbox', 'url', 'user', 'filelink'];

    /** Other names a type went by; stored as the type itself. */
    private const ALIASES = ['dropdown' => 'select', 'multi_select' => 'multiselect', 'boolean' => 'checkbox', 'usergroup' => 'user'];

    private const OPTION_TYPES = ['select', 'multiselect'];

    /** The type for a name, an old one included; null for one MetaVox does not know. */
    public static function type(mixed $type): ?string {
        if (!is_string($type)) {
            return null;
        }
        $type = strtolower(trim($type));
        $type = self::ALIASES[$type] ?? $type;
        return in_array($type, self::TYPES, true) ? $type : null;
    }

    /**
     * The field to store. A name is checked when it is new or changes kind; an
     * old one without its prefix may stay while nothing else about it changes.
     *
     * @param array<string, mixed> $sent only the parameters the request carries
     * @param ?array<string, mixed> $current the stored field, for a change
     * @return array{field_name: string, field_label: string, field_type: string, field_description: string, field_options: string, is_required: bool, sort_order: int, applies_to_groupfolder: int}
     * @throws ApiValidationException with every refused part at once
     */
    public static function stored(array $sent, ?array $current = null): array {
        $errors = [];
        $has = fn (string $key): bool => array_key_exists($key, $sent);

        $name = $has('field_name') ? $sent['field_name'] : $current['field_name'] ?? null;
        $name = is_string($name) ? trim($name) : null;

        $label = $has('field_label') ? $sent['field_label'] : $current['field_label'] ?? null;
        $label = is_string($label) ? trim($label) : null;
        if ($label === null || $label === '') {
            // TRANSLATORS Error under the label box of a metadata field that was left empty
            $errors['field_label'] = Translatable::t('Enter a label');
        } elseif (mb_strlen($label, 'UTF-8') > 255) {
            $errors['field_label'] = Translatable::t('This text is too long');
        }

        $type = $has('field_type') ? self::type($sent['field_type']) : self::type($current['field_type'] ?? 'text');
        if ($type === null && ($has('field_type') || $current === null)) {
            // TRANSLATORS Error when a metadata field is given a type that does not exist, such as "colour"
            $errors['field_type'] = Translatable::t('Choose one of the field types');
        }
        // A stored type MetaVox no longer knows stays as it is, until it is changed.
        $type ??= (string)($current['field_type'] ?? 'text');

        $description = $has('field_description') ? ($sent['field_description'] ?? '') : ($current['field_description'] ?? '');
        if (!is_string($description) || mb_strlen($description, 'UTF-8') > 2000) {
            $errors['field_description'] = Translatable::t('This text is too long');
        }

        // A checkbox is never required (FieldValue::isRequired); taken as sent
        // and stored off, so 2.x exports and templates that set it still import.
        $required = self::flag($sent, 'is_required', (bool)($current['is_required'] ?? false), $errors)
            && $type !== 'checkbox';
        $folderLevel = self::flag($sent, 'applies_to_groupfolder', FieldName::isFolderLevel($current['applies_to_groupfolder'] ?? 0), $errors);

        if ($name === null || $name === '') {
            $errors['field_name'] = Translatable::t('This field is required');
        } elseif ($current === null || $name !== (string)$current['field_name'] || $folderLevel !== FieldName::isFolderLevel($current['applies_to_groupfolder'] ?? 0)) {
            try {
                FieldName::check($name, $folderLevel);
                if (strlen($name) > 255) {
                    $errors['field_name'] = Translatable::t('This text is too long');
                }
            } catch (ApiValidationException $e) {
                $errors += $e->getErrors();
            }
        }

        $sortOrder = $has('sort_order') ? $sent['sort_order'] : (int)($current['sort_order'] ?? 0);
        if (is_string($sortOrder) && preg_match('/^[0-9]{1,9}\z/', $sortOrder)) {
            $sortOrder = (int)$sortOrder;
        }
        if (!is_int($sortOrder) || $sortOrder < 0) {
            $errors['sort_order'] = Translatable::t('Enter a whole number');
        }

        try {
            $options = self::options($type, $has('field_options'), $sent['field_options'] ?? null, $current);
        } catch (InvalidValue $e) {
            $errors['field_options'] = $e->getMessage();
        }

        if ($errors !== []) {
            throw new ApiValidationException($errors);
        }
        return [
            'field_name' => $name,
            'field_label' => $label,
            'field_type' => $type,
            'field_description' => trim($description),
            'field_options' => $options,
            'is_required' => $required,
            'sort_order' => $sortOrder,
            'applies_to_groupfolder' => $folderLevel ? 1 : 0,
        ];
    }

    /** A yes or no: true, false, 1, 0, or that as text. */
    private static function flag(array $sent, string $key, bool $current, array &$errors): bool {
        if (!array_key_exists($key, $sent)) {
            return $current;
        }
        $value = is_string($sent[$key]) ? strtolower(trim($sent[$key])) : $sent[$key];
        if (in_array($value, [true, 1, '1', 'true'], true)) {
            return true;
        }
        if (in_array($value, [false, 0, '0', 'false', '', null], true)) {
            return false;
        }
        $errors[$key] = Translatable::t('Use true or false');
        return $current;
    }

    /**
     * The options as stored: a list of values for a choice field (with labels
     * only when one differs from its value), {includeTime} for a date, none
     * for the rest. Left out on a change, the stored ones stay.
     *
     * @throws InvalidValue
     */
    private static function options(string $type, bool $sent, mixed $options, ?array $current): string {
        // Not sent: the stored ones, as they are for the same type, and checked
        // for another (a dropdown's options go along to a multi-select).
        if (!$sent && $current !== null) {
            $options = $current['field_options'] ?? null;
            if (self::type($current['field_type'] ?? null) === $type) {
                return is_string($options) ? $options : json_encode($options ?? []);
            }
        }

        if (in_array($type, self::OPTION_TYPES, true)) {
            $items = FieldOptions::items($options);
            foreach ($items as $item) {
                if (str_contains($item['value'], ';#')) {
                    // TRANSLATORS Error under the options of a dropdown field; keep ;# as it is, it joins the values of a multi-select
                    throw new InvalidValue(Translatable::t('An option cannot contain ;#'));
                }
            }
            if ($items === []) {
                // TRANSLATORS Error when a dropdown or multi-select field is saved without any option to choose from
                throw new InvalidValue(Translatable::t('Add at least one option'));
            }
            $labelled = array_filter($items, fn (array $item): bool => $item['label'] !== $item['value']) !== [];
            return json_encode($labelled ? $items : array_column($items, 'value'));
        }

        if ($type === 'date') {
            return json_encode(['includeTime' => self::includeTime($options)]);
        }
        return '[]';
    }

    /** @throws InvalidValue */
    private static function includeTime(mixed $options): bool {
        if (is_string($options)) {
            $options = $options === '' ? [] : json_decode($options, true);
        }
        if ($options === null || $options === []) {
            return false;
        }
        if (!is_array($options) || array_is_list($options) || array_diff(array_keys($options), ['includeTime']) !== []) {
            // TRANSLATORS Error under the options of a date field; keep {"includeTime": true} as it is
            throw new InvalidValue(Translatable::t('For a date field, send {"includeTime": true} or false'));
        }
        $value = $options['includeTime'] ?? false;
        $value = is_string($value) ? strtolower(trim($value)) : $value;
        return match ($value) {
            true, 1, '1', 'true' => true,
            false, 0, '0', 'false', '', null => false,
            default => throw new InvalidValue(Translatable::t('For a date field, send {"includeTime": true} or false')),
        };
    }
}
