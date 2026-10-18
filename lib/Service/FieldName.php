<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCA\MetaVox\L10n\Translatable;

/**
 * What a field may be called: the prefix of its kind, then lowercase letters,
 * numbers and underscores, beginning with a letter. Values, views and Flow
 * rules hang on the name, so it is checked when it is given, not later.
 */
final class FieldName {

    public const FILE_PREFIX = 'file_gf_';
    public const FOLDER_PREFIX = 'gf_';

    public const FILE_MESSAGE = 'A file field name starts with file_gf_, followed by lowercase letters, numbers and underscores, beginning with a letter (for example file_gf_department).';
    public const FOLDER_MESSAGE = 'A Team folder field name starts with gf_, followed by lowercase letters, numbers and underscores, beginning with a letter (for example gf_classification).';

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
            // TRANSLATORS Error message when an administrator gives a file field an invalid internal name; keep file_gf_ and the example file_gf_department exactly as they are
            Translatable::t('A file field name starts with file_gf_, followed by lowercase letters, numbers and underscores, beginning with a letter (for example file_gf_department).'),
            // TRANSLATORS Error message when an administrator gives a Team folder field an invalid internal name; keep gf_ and the example gf_classification exactly as they are
            Translatable::t('A Team folder field name starts with gf_, followed by lowercase letters, numbers and underscores, beginning with a letter (for example gf_classification).'),
        ];
    }

    /**
     * @param mixed $name the field_name as sent
     * @param bool $folderLevel true for a field on the team folder itself (applies_to_groupfolder)
     * @throws ApiValidationException
     */
    public static function check(mixed $name, bool $folderLevel): void {
        $prefix = $folderLevel ? self::FOLDER_PREFIX : self::FILE_PREFIX;
        if (!is_string($name) || preg_match('/^' . preg_quote($prefix, '/') . '[a-z][a-z0-9_]*\z/', $name) !== 1) {
            throw new ApiValidationException(['field_name' => $folderLevel ? self::FOLDER_MESSAGE : self::FILE_MESSAGE]);
        }
    }

    /** The kind as stored: applies_to_groupfolder goes into the database as an integer. */
    public static function isFolderLevel(mixed $appliesToGroupfolder): bool {
        return (int)$appliesToGroupfolder !== 0;
    }
}
