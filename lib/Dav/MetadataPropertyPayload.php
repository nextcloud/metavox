<?php

declare(strict_types=1);

namespace OCA\MetaVox\Dav;

/**
 * The value of the nc:metavox WebDAV property: a small JSON document per file.
 *
 *   v  format version (always)
 *   g  team folder id
 *   e  1 when the user may edit the metadata
 *   f  field name => value (non-empty values only, always strings)
 *   t  fields whose value in f was cut off at MAX_CHARS
 */
final class MetadataPropertyPayload {

    public const FORMAT = 1;

    /** Per value, in characters, like SQL SUBSTR. */
    public const MAX_CHARS = 512;

    /** For mount roots inside a listing: no values, not editable. */
    public const NO_VALUES = '{"v":1}';

    // Without JSON_UNESCAPED_UNICODE the output is plain ASCII, so always valid XML text.
    // JSON_HEX_TAG: no '>' and so never ']]>', which would end the CDATA section.
    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    /**
     * @param array<string, ?string> $values as read, at most MAX_CHARS + 1 characters each
     */
    public static function encode(int $groupfolderId, bool $canWrite, array $values): string {
        $doc = ['v' => self::FORMAT, 'g' => $groupfolderId];
        if ($canWrite) {
            $doc['e'] = 1;
        }

        $fields = [];
        $truncated = [];
        foreach ($values as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $value = (string)$value;
            if (mb_strlen($value, 'UTF-8') > self::MAX_CHARS) {
                $value = self::shorten($value);
                $truncated[] = (string)$name;
            }
            $fields[(string)$name] = $value;
        }
        if ($fields !== []) {
            // An object, also when a field is named '0'.
            $doc['f'] = (object)$fields;
        }
        if ($truncated !== []) {
            $doc['t'] = $truncated;
        }

        return json_encode($doc, self::FLAGS);
    }

    /** Cut at MAX_CHARS, back to the last whole ';#' item; always a prefix of the value. */
    private static function shorten(string $value): string {
        $cut = mb_substr($value, 0, self::MAX_CHARS, 'UTF-8');
        $lastItem = strrpos($cut, ';#');
        return $lastItem !== false && $lastItem > 0 ? substr($cut, 0, $lastItem) : $cut;
    }
}
