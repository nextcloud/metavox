<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use PHPUnit\Framework\TestCase;

/**
 * Values are only written through FieldService, which checks each value
 * against its field and stores file links without paths. A new direct write
 * would skip that, so it fails here.
 */
class ValueWritesGuardTest extends TestCase {

    // Where a direct write is fine, and why.
    private const ALLOWED = [
        'lib/Service/FieldService.php' => 'saveValues and saveGroupfolderValues themselves',
        'lib/Service/DefaultsService.php' => 'copies defaults, which setDefault normalized',
        'lib/Service/FileLinkPathCleanup.php' => 'removes paths',
    ];

    private const WRITE = '/(insert|update)\(\s*\'metavox_(file_gf_meta|gf_metadata)\'|(INTO|UPDATE)\s+\*PREFIX\*metavox_(file_gf_meta|gf_metadata)\b|->set\(\s*\'default_value\'/';

    public function testOnlyTheSaveMethodsWriteValues(): void {
        $root = dirname(__DIR__, 3);
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/lib'));
        foreach ($files as $file) {
            $path = substr($file->getPathname(), strlen($root) + 1);
            if (!str_ends_with($path, '.php') || str_starts_with($path, 'lib/Migration/') || isset(self::ALLOWED[$path])) {
                continue;
            }
            if (preg_match(self::WRITE, file_get_contents($file->getPathname()))) {
                $found[] = $path;
            }
        }
        $this->assertSame([], $found, 'write values through FieldService::saveValues or saveGroupfolderValues');
    }

    public function testThePatternFindsAWrite(): void {
        $this->assertMatchesRegularExpression(self::WRITE, "\$qb->insert('metavox_file_gf_meta')");
        $this->assertMatchesRegularExpression(self::WRITE, 'INSERT INTO *PREFIX*metavox_gf_metadata (a) VALUES');
        $this->assertMatchesRegularExpression(self::WRITE, "->set('default_value', \$x)");
    }
}
