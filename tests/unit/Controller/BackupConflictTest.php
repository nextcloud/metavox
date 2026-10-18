<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Controller;

use OCA\MetaVox\Controller\BackupController;
use OCA\MetaVox\Exception\RequestException;
use OCA\MetaVox\Service\FileLinkPathCleanup;
use OCA\MetaVox\Service\MetadataVersions;
use OCA\MetaVox\Service\SearchWordsService;
use OCP\Files\IAppData;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Starting a second backup or restore is refused, and the one that runs keeps its status. */
class BackupConflictTest extends TestCase {

    private ?array $status = null;
    private string $dataDir;

    protected function setUp(): void {
        $this->dataDir = sys_get_temp_dir() . '/mv-backup-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void {
        exec('rm -rf ' . escapeshellarg($this->dataDir));
    }

    private function controller(array $params = []): BackupController {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => $params[$key] ?? $default);
        $cache = $this->createMock(ICache::class);
        $cache->method('get')->willReturnCallback(fn () => $this->status);
        $cache->method('set')->willReturnCallback(function (string $key, $value) {
            $this->status = $value;
            return true;
        });
        $factory = $this->createMock(ICacheFactory::class);
        $factory->method('createDistributed')->willReturn($cache);
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValue')->willReturnCallback(fn (string $key) => $key === 'datadirectory' ? $this->dataDir : 'oc1');
        return new BackupController('metavox', $request, $this->createMock(IDBConnection::class), $this->createMock(IAppData::class),
            $config, $this->createMock(LoggerInterface::class), $factory, $this->createMock(FileLinkPathCleanup::class),
            $this->createMock(SearchWordsService::class), $this->createMock(MetadataVersions::class));
    }

    private function refusal(callable $start): RequestException {
        try {
            $start();
        } catch (RequestException $e) {
            return $e;
        }
        $this->fail('Not refused');
    }

    public function testASecondStartLeavesTheRunningOneAlone(): void {
        $running = ['status' => 'running', 'operation' => 'export', 'progress' => 40, 'total' => 100];
        $this->status = $running;

        $this->assertSame(409, $this->refusal(fn () => $this->controller()->trigger())->getStatus());
        $this->assertSame(409, $this->refusal(fn () => $this->controller(['filename' => 'metavox_backup_2026-10-15_10-00-00.json.gz'])->restore())->getStatus());
        $this->assertSame($running, $this->status, 'still running, not failed');
    }

    public function testRestoringABackupThatIsGoneLeavesTheStatusAlone(): void {
        $done = ['status' => 'completed', 'operation' => 'export'];
        $this->status = $done;

        $this->assertSame(404, $this->refusal(fn () => $this->controller(['filename' => 'metavox_backup_2026-10-15_10-00-00.json.gz'])->restore())->getStatus());
        $this->assertSame($done, $this->status);
    }
}
