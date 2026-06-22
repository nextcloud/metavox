<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Search;

use OCA\MetaVox\Search\MetadataSearchProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests the pure field-filter parser — the single source of truth shared by the
 * inline search syntax, the query-string form, and the search-bar filter chip.
 * No DB or NC runtime needed.
 */
class MetadataSearchProviderTest extends TestCase {

    public function testParsesFieldAndValue(): void {
        $this->assertSame(
            ['field' => 'author', 'value' => 'rik'],
            MetadataSearchProvider::parseFieldFilter('author:rik')
        );
    }

    public function testTrimsWhitespaceAfterColon(): void {
        $this->assertSame(
            ['field' => 'author', 'value' => 'rik'],
            MetadataSearchProvider::parseFieldFilter('author: rik')
        );
    }

    public function testValueMayContainColons(): void {
        // Split on the FIRST colon, so URLs survive as a value.
        $this->assertSame(
            ['field' => 'url', 'value' => 'http://example.com'],
            MetadataSearchProvider::parseFieldFilter('url:http://example.com')
        );
    }

    public function testValueMayContainSpaces(): void {
        $this->assertSame(
            ['field' => 'title', 'value' => 'Q3 report final'],
            MetadataSearchProvider::parseFieldFilter('title: Q3 report final')
        );
    }

    public function testFreeTextReturnsNull(): void {
        $this->assertNull(MetadataSearchProvider::parseFieldFilter('rapport'));
    }

    public function testEmptyValueReturnsNull(): void {
        $this->assertNull(MetadataSearchProvider::parseFieldFilter('author:'));
        $this->assertNull(MetadataSearchProvider::parseFieldFilter('author:   '));
    }

    public function testLeadingColonReturnsNull(): void {
        // No field name before the colon → not a field filter.
        $this->assertNull(MetadataSearchProvider::parseFieldFilter(':rik'));
    }

    public function testSnakeCaseFieldName(): void {
        $this->assertSame(
            ['field' => 'file_gf_status', 'value' => 'Concept'],
            MetadataSearchProvider::parseFieldFilter('file_gf_status:Concept')
        );
    }
}
