<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\AppInfo;

use PHPUnit\Framework\TestCase;

/**
 * The grid gets its values from the PROPFIND, and linked-file names from
 * Nextcloud's WebDAV SEARCH. Page loads must not list folders at boot again.
 */
class BootGuardTest extends TestCase {

    public function testBootDoesNotListTheFolder(): void {
        $source = file_get_contents(__DIR__ . '/../../../lib/AppInfo/Application.php');

        foreach (['getDirectoryListing', 'directory_metadata', 'filelink_resolved'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, "Application.php uses $forbidden again");
        }
    }

    public function testFilesPageLoadsTheInitScript(): void {
        $source = file_get_contents(__DIR__ . '/../../../lib/AppInfo/Application.php');

        // NC31/32 read file actions once, when the Files app loads: register before it.
        $this->assertStringContainsString("addInitScript('metavox', 'metavox-init')", $source);
    }

    /**
     * can_manage comes from the rule the server checks when saving: views
     * need write permission on the team folder; the personal page's folder
     * list is for assigning fields and defaults, which need the grant.
     */
    private const CAN_MANAGE_RULE = [
        'Application.php' => 'canUpdateTeamFolder(',
        'ViewController.php' => 'canUpdateTeamFolder(',
        'ApiViewController.php' => 'canUpdateTeamFolder(',
        'UserFieldController.php' => 'canManageGroupfolder(',
    ];

    public function testCanManageComesFromTheCentralRule(): void {
        $root = dirname(__DIR__, 3) . '/lib';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        $seen = [];
        foreach ($files as $file) {
            if (!str_ends_with($file->getFilename(), '.php')) {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            if (!str_contains($source, 'can_manage')) {
                continue;
            }
            $name = $file->getFilename();
            $seen[] = $name;
            $this->assertArrayHasKey($name, self::CAN_MANAGE_RULE, $name . ' sets can_manage: list it with its rule');
            $this->assertStringContainsString(self::CAN_MANAGE_RULE[$name], $source, $name . ' sets can_manage without the central rule');
            $this->assertStringNotContainsString('PERM_MANAGE_FIELDS', $source, $name . ' checks the grant itself');
        }
        sort($seen);
        $listed = array_keys(self::CAN_MANAGE_RULE);
        sort($listed);
        $this->assertSame($listed, $seen);
    }
}
