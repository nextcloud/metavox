<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\SetupCheck;

use OCA\MetaVox\AppInfo\Application;
use OCA\MetaVox\Service\SearchWordsService;
use OCA\MetaVox\SetupCheck\BackgroundJobsCheck;
use OCA\MetaVox\SetupCheck\LiveUpdatesCheck;
use OCA\MetaVox\SetupCheck\PresenceCheck;
use OCA\MetaVox\SetupCheck\SearchIndexCheck;
use OCA\MetaVox\SetupCheck\SharedCacheCheck;
use OCA\MetaVox\SetupCheck\SqliteCheck;
use OCA\MetaVox\SetupCheck\TeamFoldersCheck;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class SystemChecksTest extends TestCase {

    private IL10N $l;

    protected function setUp(): void {
        $this->l = $this->createMock(IL10N::class);
        $this->l->method('t')->willReturnCallback(fn(string $text, array $params = []) => vsprintf($text, $params));
    }

    public function testSearchIndexShowsHowFarTheFillIs(): void {
        $words = $this->createMock(SearchWordsService::class);
        $words->method('fillProgress')->willReturnOnConsecutiveCalls(null, ['done' => 5000, 'total' => 11051]);
        $check = new SearchIndexCheck($words, $this->l);

        $this->assertSame('success', $check->run()->getSeverity());
        $building = $check->run();
        $this->assertSame('info', $building->getSeverity());
        $this->assertStringContainsString('5000 of 11051 files', (string)$building->getDescription());
    }

    public function testBackgroundJobsWantTheSystemCronThatRuns(): void {
        $this->assertSame('warning', $this->backgroundJobs('ajax', 0)->getSeverity());
        $this->assertStringContainsString('via Webcron', (string)$this->backgroundJobs('webcron', 0)->getDescription());
        $this->assertSame('warning', $this->backgroundJobs('cron', 10000 - BackgroundJobsCheck::LATE - 1)->getSeverity());
        $this->assertSame('success', $this->backgroundJobs('cron', 10000 - 300)->getSeverity());
    }

    public function testSharedCacheIsNotApcu(): void {
        foreach (['' => 'info', '\OC\Memcache\APCu' => 'info', 'OC\Memcache\APCu' => 'info', '\OC\Memcache\Redis' => 'success'] as $cache => $severity) {
            $config = $this->createMock(IConfig::class);
            $config->method('getSystemValue')->with('memcache.distributed', '')->willReturn($cache);
            $this->assertSame($severity, (new SharedCacheCheck($config, $this->l))->run()->getSeverity(), $cache);
        }
    }

    public function testLiveUpdatesNeedClientPushSetUp(): void {
        foreach ([[false, 'https://cloud/push', 'info'], [true, '', 'info'], [true, 'https://cloud/push', 'success']] as [$installed, $endpoint, $severity]) {
            $config = $this->createMock(IAppConfig::class);
            $config->method('getValueString')->with('notify_push', 'base_endpoint', '')->willReturn($endpoint);
            $this->assertSame($severity, (new LiveUpdatesCheck($this->apps(['notify_push' => $installed]), $config, $this->l))->run()->getSeverity());
        }
    }

    public function testPresenceFollowsTheUserStatusApp(): void {
        $this->assertSame('success', (new PresenceCheck($this->apps(['user_status' => true]), $this->l))->run()->getSeverity());
        $this->assertSame('info', (new PresenceCheck($this->apps(['user_status' => false]), $this->l))->run()->getSeverity());
    }

    public function testWithoutTeamFoldersMetaVoxDoesNothing(): void {
        $this->assertSame('error', (new TeamFoldersCheck($this->apps(['groupfolders' => false]), $this->l))->run()->getSeverity());
        $on = (new TeamFoldersCheck($this->apps(['groupfolders' => true]), $this->l))->run();
        $this->assertSame('success', $on->getSeverity());
        $this->assertSame('Team Folders 23.0.1 is on.', $on->getDescription());
    }

    /** As Nextcloud says of itself: SQLite is for trying out. */
    public function testSqliteIsForTestingOnly(): void {
        $this->assertSame('warning', $this->sqlite(IDBConnection::PLATFORM_SQLITE)->getSeverity());
        $this->assertSame('success', $this->sqlite(IDBConnection::PLATFORM_MYSQL)->getSeverity());
        $this->assertSame('success', $this->sqlite(IDBConnection::PLATFORM_POSTGRES)->getSeverity());
    }

    private function sqlite(string $provider): \OCP\SetupCheck\SetupResult {
        $db = $this->createMock(IDBConnection::class);
        $db->method('getDatabaseProvider')->willReturn($provider);
        return (new SqliteCheck($db, $this->l))->run();
    }

    public function testEveryCheckIsInTheList(): void {
        $found = [];
        foreach (glob(dirname(__DIR__, 3) . '/lib/SetupCheck/*Check.php') as $file) {
            $found[] = 'OCA\MetaVox\SetupCheck\\' . basename($file, '.php');
        }
        sort($found);
        $listed = Application::SETUP_CHECKS;
        sort($listed);

        $this->assertSame($found, $listed);
    }

    private function backgroundJobs(string $mode, int $lastCron): \OCP\SetupCheck\SetupResult {
        $config = $this->createMock(IAppConfig::class);
        $config->method('getValueString')->willReturn($mode);
        $config->method('getValueInt')->willReturn($lastCron);
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(10000);
        return (new BackgroundJobsCheck($config, $time, $this->l))->run();
    }

    /** @param array<string, bool> $installed */
    private function apps(array $installed): IAppManager {
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isInstalled')->willReturnCallback(fn(string $app) => $installed[$app] ?? false);
        $apps->method('getAppVersion')->willReturn('23.0.1');
        return $apps;
    }
}
