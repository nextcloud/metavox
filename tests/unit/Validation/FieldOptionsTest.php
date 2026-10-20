<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Validation;

use OCA\MetaVox\Validation\FieldOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The cases are shared with tests/js/FieldOptions.test.js: the browser reads options the same way. */
class FieldOptionsTest extends TestCase {

    private static function fixture(string $part): array {
        $cases = json_decode(file_get_contents(__DIR__ . '/../../fixtures/field-options.json'), true, 512, JSON_THROW_ON_ERROR)[$part];
        return array_combine(array_column($cases, 'name'), array_map(fn (array $case) => [$case['options'], $case[$part]], $cases));
    }

    /** Every shape the options were ever stored in reads as the same list. */
    public static function shapes(): array {
        return self::fixture('items');
    }

    #[DataProvider('shapes')]
    public function testEveryShapeReadsTheSame(mixed $stored, array $expected): void {
        $this->assertSame($expected, FieldOptions::items($stored));
        $this->assertSame(array_column($expected, 'value'), FieldOptions::values($stored));
    }

    public static function times(): array {
        return self::fixture('includeTime');
    }

    #[DataProvider('times')]
    public function testIncludeTime(mixed $stored, bool $expected): void {
        $this->assertSame($expected, FieldOptions::includeTime($stored));
    }

    public static function people(): array {
        return self::fixture('accounts');
    }

    #[DataProvider('people')]
    public function testWhatAPeopleFieldTakes(mixed $stored, array $expected): void {
        $this->assertSame($expected, FieldOptions::accounts($stored));
    }
}
