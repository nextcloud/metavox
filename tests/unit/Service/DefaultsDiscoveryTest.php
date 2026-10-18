<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Service\DefaultsDiscovery;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/** The defaults status comes from where discovery stands, not from a scan of the folder. */
class DefaultsDiscoveryTest extends TestCase {

    private array $config = [];
    /** @var int[] team folders with a queued ApplyDefaultsJob */
    private array $queuedFor = [];

    public function testIdleWhenNothingIsGoingOn(): void {
        $this->assertFalse($this->discovery()->isBusy(2, 1000));
    }

    public function testBusyDuringASweep(): void {
        $discovery = $this->discovery();
        $discovery->save(2, ['high' => 500, 'sweep' => null, 'swept' => 10]);
        $discovery->startSweep(2, 1000);

        $this->assertTrue($discovery->isBusy(2, 1000));
        $this->assertFalse($discovery->isBusy(3, 1000));
    }

    public function testBusyWhileDefaultsWaitInTheQueue(): void {
        $this->queuedFor = [5, 2];

        $this->assertTrue($this->discovery()->isBusy(2, 1000));
        $this->assertFalse($this->discovery()->isBusy(3, 1000));
    }

    public function testAStartedSweepKeepsTheMark(): void {
        $discovery = $this->discovery();
        $discovery->save(2, ['high' => 500, 'sweep' => null, 'swept' => 10]);

        $discovery->startSweep(2, 1000);

        $this->assertSame(['high' => 500, 'sweep' => 0, 'swept' => 10], $discovery->state(2, 1000));
    }

    public function testAFolderNotLookedAtYetNeedsNoSweep(): void {
        $discovery = $this->discovery();

        $discovery->startSweep(2, 1000);

        $this->assertSame(['high' => 0, 'sweep' => null, 'swept' => 1000], $discovery->state(2, 1000));
    }

    private function discovery(): DefaultsDiscovery {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default) => $this->config[$key] ?? $default);
        $appConfig->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
            $this->config[$key] = $value;
            return true;
        });
        $jobs = $this->createMock(IJobList::class);
        $jobs->method('getJobsIterator')->willReturnCallback(fn () => array_map(function (int $gf) {
            $job = $this->createMock(IJob::class);
            $job->method('getArgument')->willReturn(['groupfolderId' => $gf, 'fileIds' => [1]]);
            return $job;
        }, $this->queuedFor));
        return new DefaultsDiscovery($appConfig, $jobs);
    }
}
