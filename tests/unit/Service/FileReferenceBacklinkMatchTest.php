<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Service\FileReferenceService;
use PHPUnit\Framework\TestCase;

/**
 * The backlink LIKE query is deliberately fuzzy (a stored "<id>:path" can't be
 * indexed for an exact-id lookup), so every candidate row is re-verified in PHP
 * by valueReferencesFileId(). These tests pin that exact-match guard — the part
 * that prevents "48" being reported as a backlink of "482". No DB needed.
 */
class FileReferenceBacklinkMatchTest extends TestCase {

    public function testMatchesExactSingle(): void {
        $this->assertTrue(FileReferenceService::valueReferencesFileId('482:/a/spec.pdf', 482));
    }

    public function testDoesNotMatchPrefixCollision(): void {
        // The classic false positive: target 48 must NOT match a value for 482.
        $this->assertFalse(FileReferenceService::valueReferencesFileId('482:/a/spec.pdf', 48));
    }

    public function testMatchesAnyTokenInMulti(): void {
        $value = '12:/a.pdf;#482:/b.pdf;#7:/c.pdf';
        $this->assertTrue(FileReferenceService::valueReferencesFileId($value, 482));
        $this->assertTrue(FileReferenceService::valueReferencesFileId($value, 12));
        $this->assertTrue(FileReferenceService::valueReferencesFileId($value, 7));
    }

    public function testDoesNotMatchAbsentIdInMulti(): void {
        $value = '12:/a.pdf;#34:/b.pdf';
        $this->assertFalse(FileReferenceService::valueReferencesFileId($value, 99));
    }

    public function testLegacyBarePathNeverMatches(): void {
        // A not-yet-migrated value has no fileid token, so it can't be a backlink.
        $this->assertFalse(FileReferenceService::valueReferencesFileId('/a/spec.pdf', 482));
    }

    public function testEmptyValueNeverMatches(): void {
        $this->assertFalse(FileReferenceService::valueReferencesFileId('', 1));
    }
}
