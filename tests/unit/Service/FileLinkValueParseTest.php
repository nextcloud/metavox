<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Service\FileReferenceService;
use PHPUnit\Framework\TestCase;

/**
 * Pure-function tests for the "<fileid>:<path>" value contract shared by the
 * File Link field types (issue #73). No DB — all methods under test are static.
 */
class FileLinkValueParseTest extends TestCase {

    public function testParseTokenWithFileId(): void {
        $t = FileReferenceService::parseToken('482:/Team/Docs/spec.pdf');
        $this->assertSame(482, $t['fileId']);
        $this->assertSame('/Team/Docs/spec.pdf', $t['path']);
    }

    public function testParseTokenLegacyBarePathHasNullId(): void {
        $t = FileReferenceService::parseToken('/Team/Docs/spec.pdf');
        $this->assertNull($t['fileId']);
        $this->assertSame('/Team/Docs/spec.pdf', $t['path']);
    }

    public function testParseTokenSplitsOnFirstColonOnly(): void {
        // A path could (defensively) contain a colon; only the leading digits
        // before the FIRST colon count as the id.
        $t = FileReferenceService::parseToken('7:/weird:name.txt');
        $this->assertSame(7, $t['fileId']);
        $this->assertSame('/weird:name.txt', $t['path']);
    }

    public function testParseTokenNonDigitPrefixIsLegacyPath(): void {
        // "C:" style prefix is not a fileid — treat the whole thing as a path.
        $t = FileReferenceService::parseToken('C:/Users/x.txt');
        $this->assertNull($t['fileId']);
        $this->assertSame('C:/Users/x.txt', $t['path']);
    }

    public function testParseValueEmpty(): void {
        $this->assertSame([], FileReferenceService::parseValue(''));
    }

    public function testParseValueMultiRoundTrips(): void {
        $value = '12:/a/x.pdf;#34:/b/y.docx';
        $tokens = FileReferenceService::parseValue($value);
        $this->assertCount(2, $tokens);
        $this->assertSame(12, $tokens[0]['fileId']);
        $this->assertSame(34, $tokens[1]['fileId']);

        $rejoined = implode(
            FileReferenceService::MULTI_DELIM,
            array_map(
                fn($t) => FileReferenceService::formatToken($t['fileId'], $t['path']),
                $tokens
            )
        );
        $this->assertSame($value, $rejoined);
    }

    public function testParseValueSkipsEmptyTokens(): void {
        // A stray trailing delimiter must not produce a blank token.
        $tokens = FileReferenceService::parseValue('5:/a.pdf;#');
        $this->assertCount(1, $tokens);
        $this->assertSame(5, $tokens[0]['fileId']);
    }

    public function testFormatToken(): void {
        $this->assertSame('99:/x/y.txt', FileReferenceService::formatToken(99, '/x/y.txt'));
    }

    public function testDedupeDropsRepeatedFileId(): void {
        // The same file linked three times collapses to one.
        $tokens = FileReferenceService::parseValue('4668:/a.pdf;#4668:/a.pdf;#4668:/a.pdf');
        $this->assertSame('4668:/a.pdf', FileReferenceService::joinTokens(FileReferenceService::dedupeTokens($tokens)));
    }

    public function testDedupeFirstOccurrenceWinsOnSameId(): void {
        // Same id, different cached path: keep the first.
        $tokens = FileReferenceService::parseValue('1:/x;#2:/y;#1:/x-renamed');
        $this->assertSame('1:/x;#2:/y', FileReferenceService::joinTokens(FileReferenceService::dedupeTokens($tokens)));
    }

    public function testDedupeKeepsDistinctFiles(): void {
        $tokens = FileReferenceService::parseValue('1:/x;#2:/y');
        $this->assertSame('1:/x;#2:/y', FileReferenceService::joinTokens(FileReferenceService::dedupeTokens($tokens)));
    }

    public function testDedupeBarePathsByText(): void {
        // Unresolved bare paths still dedup so they don't pile up.
        $tokens = FileReferenceService::parseValue('/bare.pdf;#/bare.pdf');
        $this->assertSame('/bare.pdf', FileReferenceService::joinTokens(FileReferenceService::dedupeTokens($tokens)));
    }

    /** @dataProvider migratedProvider */
    public function testIsMigrated(string $value, bool $expected): void {
        $this->assertSame($expected, FileReferenceService::isMigrated($value));
    }

    public static function migratedProvider(): array {
        return [
            'with id'        => ['482:/x', true],
            'bare path'      => ['/x', false],
            'empty'          => ['', false],
            'multi migrated' => ['1:/a;#2:/b', true],
            'windows-ish'    => ['C:/x', false],
            'leading space'  => [' 5:/a', true],
        ];
    }
}
