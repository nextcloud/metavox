<?php

declare(strict_types=1);

namespace OCA\MetaVox\L10n;

/**
 * Marks an English message for the translation extractor without translating it.
 *
 * Exceptions that reach the UI carry their message in English and
 * ExceptionMiddleware translates it in the user's language when it answers
 * by passing the exception's message to IL10N::t(). That call has no literal, so neither
 * Nextcloud's Transifex bot (xgettext --keyword=t) nor scripts/extract-en-json.js
 * would ever see the msgid. Wrap the English literal in Translatable::t() where
 * the exception is thrown, and both pick it up.
 *
 * Only for messages translated later by the middleware; anything shown
 * directly uses IL10N::t().
 */
final class Translatable {

    /**
     * @param string $text an English literal; never a variable or a concatenation
     * @return string the same text, untranslated
     */
    public static function t(string $text): string {
        return $text;
    }
}
