<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Service\BackupReader;
use PHPUnit\Framework\TestCase;

/** Every row of a backup comes back, the last of each table too. */
class BackupReaderTest extends TestCase {

    private const TABLES = ['metavox_gf_fields', 'metavox_gf_metadata', 'metavox_file_gf_meta'];

    /** A backup as BackupController::streamBackupToFile writes it, split into lines as gzgets reads them. */
    private static function written(array $tables): array {
        $text = '{"version":"3.0.0","created_at":"2026-10-18T11:28:22+00:00","counts":{},"tables":{';
        $first = true;
        foreach ($tables as $table => $rows) {
            $text .= ($first ? '' : ',') . json_encode($table) . ':[';
            $first = false;
            $text .= implode(",\n", array_map(fn ($row) => json_encode($row, JSON_UNESCAPED_UNICODE), $rows));
            $text .= ']';
        }
        $text .= '}}';
        return preg_split('/(?<=\n)/', $text);
    }

    private static function read(array $lines): array {
        $out = [];
        foreach (BackupReader::rows($lines, self::TABLES) as [$table, $row]) {
            $out[$table][] = $row;
        }
        return $out;
    }

    public function testTheLastRowOfEveryTableComesBack(): void {
        $tables = [
            'metavox_gf_fields' => [['id' => 1, 'field_name' => 'a'], ['id' => 2, 'field_name' => 'b'], ['id' => 23, 'field_name' => 'oude_status']],
            'metavox_gf_metadata' => [['id' => 1, 'field_value' => 'x'], ['id' => 9, 'field_value' => 'laatste']],
            'metavox_file_gf_meta' => [['id' => 1, 'field_value' => 'één'], ['id' => 1310, 'field_value' => 'Open']],
        ];

        $this->assertSame($tables, self::read(self::written($tables)));
    }

    public function testAOneRowTableAndAnEmptyOne(): void {
        $tables = [
            'metavox_gf_fields' => [['id' => 1, 'field_name' => 'only']],
            'metavox_gf_metadata' => [],
            'metavox_file_gf_meta' => [['id' => 5, 'field_value' => 'v']],
        ];

        $this->assertSame(['metavox_gf_fields' => $tables['metavox_gf_fields'], 'metavox_file_gf_meta' => $tables['metavox_file_gf_meta']],
            self::read(self::written($tables)));
    }

    public function testValuesThatLookLikeTheFileLayout(): void {
        // A value can hold anything a person typed, the backup's own markers included.
        $tables = [
            'metavox_gf_fields' => [['id' => 1, 'field_name' => 'f', 'field_options' => '["Open","Dicht"]']],
            'metavox_gf_metadata' => [['id' => 1, 'field_value' => 'ends in a bracket]'], ['id' => 2, 'field_value' => 'x],"metavox_file_gf_meta":[{"id":99}']],
            'metavox_file_gf_meta' => [['id' => 1, 'field_value' => "line\nbreak and \"quotes\" and ]}}"], ['id' => 2, 'field_value' => ']']],
        ];

        $this->assertSame($tables, self::read(self::written($tables)));
    }

    public function testAnOlderLayoutWithTheBracketOnItsOwnLine(): void {
        $lines = [
            "{\"version\":\"2.0.0\",\"tables\":{\n",
            "\"metavox_gf_fields\":[\n",
            "{\"id\":1,\"field_name\":\"a\"},\n",
            "{\"id\":2,\"field_name\":\"b\"}\n",
            "],\n",
            "\"metavox_file_gf_meta\":[\n",
            "{\"id\":7,\"field_value\":\"v\"}\n",
            "]\n",
            "}}\n",
        ];

        $this->assertSame([
            'metavox_gf_fields' => [['id' => 1, 'field_name' => 'a'], ['id' => 2, 'field_name' => 'b']],
            'metavox_file_gf_meta' => [['id' => 7, 'field_value' => 'v']],
        ], self::read($lines));
    }

    public function testATableItDoesNotKnowIsSkipped(): void {
        $tables = [
            'metavox_gf_fields' => [['id' => 1, 'field_name' => 'a']],
            'metavox_gf_views' => [['id' => 3, 'name' => 'v']],
            'metavox_file_gf_meta' => [['id' => 2, 'field_value' => 'b']],
        ];

        $this->assertSame(['metavox_gf_fields' => $tables['metavox_gf_fields'], 'metavox_file_gf_meta' => $tables['metavox_file_gf_meta']],
            self::read(self::written($tables)));
    }
}
