<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Access;

use PHPUnit\Framework\TestCase;

/**
 * Every way into MetaVox that is not a route, and how it is protected. Routes
 * have AccessMapTest; these are the DAV plugin, search, Flow, the Files app's
 * start data, listeners, jobs, commands and settings. A new one fails here
 * until it is listed below, so somebody decides how it is protected.
 */
class EntryPointsTest extends TestCase {

    // Gives a user data: uses the access services, itself or through the class named third.
    private const POLICY = 'policy';
    // Nextcloud already allowed what happens (a copy, a new file, an admin page).
    private const NEXTCLOUD = 'nextcloud';
    // Answers no user: background work, the admin's occ, wiring.
    private const SYSTEM = 'system';

    private const ENTRY_POINTS = [
        'lib/AppInfo/Application.php' => [self::POLICY, "the Files app's start data: the user's own team folders, can_manage by the central rule"],
        'lib/Dav/MetadataPropertyPlugin.php' => [self::POLICY, 'grid values in the PROPFIND', 'lib/Service/DavMetadataService.php'],
        'lib/Dav/SavedSearchReportPlugin.php' => [self::POLICY, "a saved search as a folder: the user's own team folders, what the ACL hides left out in the query and by Nextcloud's search", 'lib/Service/SavedSearchResults.php'],
        'lib/Search/MetadataSearchProvider.php' => [self::POLICY, "unified search results: the user's own team folders, what the ACL hides left out in the query"],
        'lib/Flow/MetadataCheck.php' => [self::POLICY, 'admin flows only'],
        'lib/Settings/Personal.php' => [self::POLICY, 'the manager settings'],
        'lib/Settings/PersonalSection.php' => [self::POLICY, 'the menu entry of the manager settings'],
        'lib/Settings/Admin.php' => [self::NEXTCLOUD, 'Nextcloud shows admin settings to admins only'],
        'lib/Settings/AdminSection.php' => [self::NEXTCLOUD, 'Nextcloud shows admin sections to admins only'],
        'lib/Listener/FileCopyListener.php' => [self::NEXTCLOUD, 'Nextcloud allowed the copy; metadata only follows within the same team folder'],
        'lib/Listener/ApplyDefaultsListener.php' => [self::NEXTCLOUD, 'Nextcloud allowed the new file or the move; it gets its team folder\'s defaults'],
        'lib/Listener/PresenceKeepAliveListener.php' => [self::NEXTCLOUD, "Nextcloud's status heartbeat; renews only what a checked listing registered, and pushes carry ids only"],
        'lib/Listener/CacheCleanupListener.php' => [self::SYSTEM, 'removes the metadata of files that are gone'],
        'lib/Listener/RegisterFlowChecksListener.php' => [self::SYSTEM, 'registers the Flow check'],
        'lib/Listener/SabrePluginAddListener.php' => [self::SYSTEM, 'adds the DAV plugins, which are checked themselves'],
        'lib/BackgroundJobs/ApplyDefaultsJob.php' => [self::SYSTEM, 'fills in defaults'],
        'lib/BackgroundJobs/BuildSearchWords.php' => [self::SYSTEM, 'fills the search words'],
        'lib/BackgroundJobs/CleanupDeletedMetadata.php' => [self::SYSTEM, 'removes metadata of deleted files and team folders'],
        'lib/BackgroundJobs/DiscoverMissingDefaultsJob.php' => [self::SYSTEM, 'finds files without their defaults'],
        'lib/BackgroundJobs/LicenseUsageJob.php' => [self::SYSTEM, 'counts usage for the license'],
        'lib/BackgroundJobs/MetadataBackupJob.php' => [self::SYSTEM, 'writes the backup to app data; only admins download it'],
        'lib/BackgroundJobs/TelemetryJob.php' => [self::SYSTEM, 'sends counts, when the admin turned it on'],
        'lib/Command/ApplyDefaults.php' => [self::SYSTEM, "the admin's occ"],
        'lib/Repair/PostgresValueIndex.php' => [self::SYSTEM, 'an install step: sets an index, takes no input'],
        'lib/SetupCheck/BackgroundJobsCheck.php' => [self::NEXTCLOUD, 'a setup check; Nextcloud shows those to admins only'],
        'lib/SetupCheck/LiveUpdatesCheck.php' => [self::NEXTCLOUD, 'a setup check; Nextcloud shows those to admins only'],
        'lib/SetupCheck/PresenceCheck.php' => [self::NEXTCLOUD, 'a setup check; Nextcloud shows those to admins only'],
        'lib/SetupCheck/SearchIndexCheck.php' => [self::NEXTCLOUD, 'a setup check; Nextcloud shows those to admins only'],
        'lib/SetupCheck/SharedCacheCheck.php' => [self::NEXTCLOUD, 'a setup check; Nextcloud shows those to admins only'],
        'lib/SetupCheck/SqliteCheck.php' => [self::NEXTCLOUD, 'a setup check; Nextcloud shows those to admins only'],
        'lib/SetupCheck/TeamFoldersCheck.php' => [self::NEXTCLOUD, 'a setup check; Nextcloud shows those to admins only'],
    ];

    private const ACCESS_SERVICES = ['FileAccessService', 'TeamFolderAccessService'];

    private const PATTERN = '/^(?:final |abstract )?class \w+[^{]*\b(?:extends (?:ServerPlugin|TimedJob|QueuedJob|Job|Command)'
        . '|implements [^{]*\b(?:IEventListener|IProvider|IFilteringProvider|ICheck|ISettings|IIconSection|IDelegatedSettings|IBootstrap|IRepairStep|ISetupCheck))\b/m';

    public function testEveryEntryPointIsListed(): void {
        $found = $this->entryPoints();

        $this->assertSame([], array_values(array_diff($found, array_keys(self::ENTRY_POINTS))),
            'a new way into MetaVox: list it in EntryPointsTest with how it is protected');
        $this->assertSame([], array_values(array_diff(array_keys(self::ENTRY_POINTS), $found)), 'listed, but no longer an entry point');
    }

    public function testEachSaysHowItIsProtected(): void {
        foreach (self::ENTRY_POINTS as $file => [$kind, $why]) {
            $this->assertContains($kind, [self::POLICY, self::NEXTCLOUD, self::SYSTEM], $file);
            $this->assertNotSame('', trim($why), $file);
        }
    }

    public function testTheOnesThatGiveUsersDataUseTheAccessServices(): void {
        foreach (self::ENTRY_POINTS as $file => $entry) {
            if ($entry[0] !== self::POLICY) {
                continue;
            }
            $source = file_get_contents($this->root() . '/' . ($entry[2] ?? $file));
            $uses = array_filter(self::ACCESS_SERVICES, static fn(string $class): bool => str_contains($source, $class));
            $this->assertNotEmpty($uses, ($entry[2] ?? $file) . ' gives users data without the access services');
        }
    }

    public function testThePatternFindsEntryPoints(): void {
        foreach ([
            'class A implements IEventListener {',
            'final class B extends TimedJob {',
            'class C extends App implements IBootstrap {',
            'class D implements ICheck, IFileCheck {',
            'class E implements IProvider, IFilteringProvider {',
            'class F implements ISetupCheck {',
        ] as $declaration) {
            $this->assertMatchesRegularExpression(self::PATTERN, $declaration);
        }
        $this->assertDoesNotMatchRegularExpression(self::PATTERN, 'class F extends SimpleMigrationStep {');
        $this->assertDoesNotMatchRegularExpression(self::PATTERN, 'class G extends BaseController {');
    }

    /** @return list<string> */
    private function entryPoints(): array {
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root() . '/lib'));
        foreach ($files as $file) {
            if (str_ends_with($file->getFilename(), '.php') && preg_match(self::PATTERN, file_get_contents($file->getPathname()))) {
                $found[] = substr($file->getPathname(), strlen($this->root()) + 1);
            }
        }
        sort($found);
        return $found;
    }

    private function root(): string {
        return dirname(__DIR__, 3);
    }
}
