<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

/**
 * The rows of a MetaVox backup, read line by line so a large one never sits in
 * memory whole.
 *
 * The writer (BackupController::streamBackupToFile) puts one row per line, but
 * closes a table on the line of its last row and opens the next one there too:
 * `{…}],"metavox_gf_metadata":[{…},`, and the file ends in `{…}]}}`. Older
 * backups put `]` on a line of its own. A quote inside a value is always
 * escaped, so an unescaped `"table":[` after `,` or `{` is where a table starts.
 */
final class BackupReader {

    /**
     * @param iterable<string> $lines the backup's lines, as read
     * @param list<string> $tables the tables to read; others are skipped
     * @return \Generator<int, array{0: string, 1: array<string, mixed>}> [table, row]
     */
    public static function rows(iterable $lines, array $tables): \Generator {
        $current = null;
        foreach ($lines as $line) {
            $rest = trim($line);
            while ($rest !== '') {
                [$pos, $table] = self::tableStart($rest, $tables);
                $head = $pos === null ? $rest : substr($rest, 0, $pos);
                if ($current !== null) {
                    [$row, $closed] = self::row($head);
                    if ($row !== null) {
                        yield [$current, $row];
                    }
                    if ($closed) {
                        $current = null;
                    }
                }
                if ($pos === null) {
                    break;
                }
                $current = $table;
                $rest = trim(substr($rest, $pos + strlen('"' . $table . '":[')));
            }
        }
    }

    /**
     * Where the first table in this text starts, and which one.
     *
     * @return array{0: ?int, 1: ?string}
     */
    private static function tableStart(string $text, array $tables): array {
        $found = [null, null];
        foreach ($tables as $table) {
            $marker = '"' . $table . '":[';
            $offset = 0;
            while (($pos = strpos($text, $marker, $offset)) !== false) {
                // Inside a value the quote would be escaped; a real key follows `,` or `{`.
                if ($pos === 0 || $text[$pos - 1] === ',' || $text[$pos - 1] === '{') {
                    if ($found[0] === null || $pos < $found[0]) {
                        $found = [$pos, $table];
                    }
                    break;
                }
                $offset = $pos + 1;
            }
        }
        return $found;
    }

    /**
     * The row in this piece of a line, if any, and whether its table closes
     * after it: `{…},`, `{…}`, `{…}]`, `{…}],`, `{…}]}}`, or just `]`.
     *
     * @return array{0: ?array<string, mixed>, 1: bool}
     */
    private static function row(string $text): array {
        $text = trim($text);
        if ($text === '') {
            return [null, false];
        }
        if ($text[0] !== '{') {
            return [null, str_starts_with($text, ']')];
        }
        $end = self::objectEnd($text);
        if ($end === null) {
            return [null, false];
        }
        $row = json_decode(substr($text, 0, $end + 1), true);
        $after = ltrim(substr($text, $end + 1), ", \t");
        return [is_array($row) ? $row : null, str_starts_with($after, ']')];
    }

    /** Where the JSON object that opens this text closes, minding strings; null when it does not. */
    private static function objectEnd(string $text): ?int {
        $depth = 0;
        $inString = false;
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            if ($inString) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === '"') {
                    $inString = false;
                }
            } elseif ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}' && --$depth === 0) {
                return $i;
            }
        }
        return null;
    }
}
