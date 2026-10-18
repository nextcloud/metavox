<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests;

/** The msgids translators get: the committed POT, not a language bundle. */
final class Pot {

    /** @return array<string, true> */
    public static function msgids(): array {
        $pot = file_get_contents(__DIR__ . '/../../translationfiles/templates/metavox.pot');
        preg_match_all('/^msgid "((?:[^"\\\\]|\\\\.)*)"$/m', $pot, $matches);
        return array_fill_keys(array_map('stripcslashes', $matches[1]), true);
    }
}
