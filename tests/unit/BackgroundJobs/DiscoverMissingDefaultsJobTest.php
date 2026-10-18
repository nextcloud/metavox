<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\BackgroundJobs;

use OCA\MetaVox\BackgroundJobs\ApplyDefaultsJob;
use OCA\MetaVox\BackgroundJobs\DiscoverMissingDefaultsJob;
use OCA\MetaVox\Service\DefaultsDiscovery;
use OCA\MetaVox\Service\DefaultsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DiscoverMissingDefaultsJobTest extends TestCase {

    private const NOW = 1_000_000;

    /** app config of metavox */
    private array $config = [];
    /** per team folder: file ids that miss a default */
    private array $missing = [];
    private int $maxFileId = 100_000;
    private int $pending = 0;
    /** @var list<array{int, int[]}> queued jobs: team folder, files */
    private array $queued = [];
    /** @var list<array{int, int}> ranges read: after, up to */
    private array $ranges = [];
    /** the clock, per call */
    private array $clock = [];

    public function testAFinishedTeamFolderCostsNothing(): void {
        $this->state(2, ['high' => 100_000, 'sweep' => null, 'swept' => self::NOW - 60]);

        $this->runJob([2]);

        $this->assertSame([], $this->ranges);
    }

    public function testOnlyNewFilesAreLookedAt(): void {
        $this->state(2, ['high' => 95_000, 'sweep' => null, 'swept' => self::NOW - 60]);
        $this->missing[2] = [96_000, 99_000];

        $this->runJob([2]);

        $this->assertSame([[95_000, 100_000]], $this->ranges);
        $this->assertSame([[2, [96_000, 99_000]]], $this->queued);
        $this->assertSame(100_000, $this->saved(2)['high']);
    }

    public function testEachQueryReadsOneRangeOfIds(): void {
        $this->state(2, ['high' => 70_000, 'sweep' => null, 'swept' => self::NOW - 60]);

        $this->runJob([2]);

        $this->assertSame([[70_000, 80_000], [80_000, 90_000], [90_000, 100_000]], $this->ranges);
    }

    public function testAFullBatchGoesOnInTheSameRange(): void {
        $this->state(2, ['high' => 90_000, 'sweep' => null, 'swept' => self::NOW - 60]);
        $this->missing[2] = range(90_001, 90_300);

        $this->runJob([2]);

        $this->assertSame([[90_000, 100_000], [90_250, 100_000]], $this->ranges);
        $this->assertSame([250, 50], array_map(fn (array $job) => \count($job[1]), $this->queued));
    }

    public function testADailySweepGoesThroughTheOlderFiles(): void {
        $this->state(2, ['high' => 100_000, 'sweep' => null, 'swept' => self::NOW - DiscoverMissingDefaultsJob::SWEEP_INTERVAL]);
        $this->missing[2] = [500];

        $this->runJob([2]);

        $this->assertSame([[2, [500]]], $this->queued);
        $this->assertSame(['high' => 100_000, 'sweep' => null, 'swept' => self::NOW], $this->saved(2));
    }

    public function testASweepThatRunsOutOfTimeGoesOnNextTime(): void {
        $this->state(2, ['high' => 100_000, 'sweep' => 0, 'swept' => self::NOW - 60]);
        // Start, the turn of the folder, before each range: the third is over the budget.
        $this->clock = [self::NOW, self::NOW, self::NOW, self::NOW + 1, self::NOW + DiscoverMissingDefaultsJob::BUDGET];

        $this->runJob([2]);

        $this->assertSame(20_000, $this->saved(2)['sweep']);
    }

    public function testAFirstLookIsTheSweep(): void {
        $this->maxFileId = 20_000;

        $this->runJob([2]);

        $this->assertSame([[0, 10_000], [10_000, 20_000]], $this->ranges);
        $this->assertSame(['high' => 20_000, 'sweep' => null, 'swept' => self::NOW], $this->saved(2));
    }

    public function testTheQueueNeverGrowsPastItsLimit(): void {
        $this->pending = DiscoverMissingDefaultsJob::MAX_PENDING_JOBS - 1;
        $this->state(2, ['high' => 0, 'sweep' => null, 'swept' => self::NOW - 60]);
        $this->state(3, ['high' => 0, 'sweep' => null, 'swept' => self::NOW - 60]);
        $this->missing[2] = [10, 20_010];
        $this->missing[3] = [30];

        $this->runJob([2, 3]);

        // One place left: one batch; that folder goes on from there next time, the other waits.
        $this->assertCount(1, $this->queued);
        $done = $this->queued[0][0];
        $this->assertSame(10_000, $this->saved($done)['high']);
        $this->assertSame(0, $this->saved($done === 2 ? 3 : 2)['high']);
    }

    public function testAFullQueueSkipsTheTick(): void {
        $this->pending = DiscoverMissingDefaultsJob::MAX_PENDING_JOBS;

        $this->runJob([2]);

        $this->assertSame([], $this->ranges);
    }

    public function testTeamFoldersTakeTurnsAtGoingFirst(): void {
        $first = [];
        foreach ([0, 1, 2] as $tick) {
            $this->config = [];
            $this->ranges = [];
            $this->runJob([2, 3, 4], self::NOW + $tick * DiscoverMissingDefaultsJob::DISCOVERY_INTERVAL);
            $first[] = array_key_first($this->savedFolders());
        }

        $this->assertCount(3, array_unique($first));
    }

    public function testTheOldCursorsGo(): void {
        $this->config = ['defaults_cursor_gf_2' => '500', 'other' => 'x'];

        $this->runJob([]);

        $this->assertSame(['other' => 'x'], $this->config);
    }

    private function state(int $groupfolderId, array $state): void {
        $this->config['defaults_discovery_gf_' . $groupfolderId] = json_encode($state);
    }

    private function saved(int $groupfolderId): array {
        return json_decode($this->config['defaults_discovery_gf_' . $groupfolderId], true);
    }

    /** @return array<int, true> team folders with a saved state, in the order they were saved */
    private function savedFolders(): array {
        $folders = [];
        foreach (array_keys($this->config) as $key) {
            if (str_starts_with($key, 'defaults_discovery_gf_')) {
                $folders[(int)substr($key, \strlen('defaults_discovery_gf_'))] = true;
            }
        }
        return $folders;
    }

    private function runJob(array $groupfolderIds, int $now = self::NOW): void {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default) => $this->config[$key] ?? $default);
        $appConfig->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value, bool $lazy): bool {
            $this->assertTrue($lazy, 'loaded with every request otherwise');
            // In the order saved, for the turns.
            unset($this->config[$key]);
            $this->config[$key] = $value;
            return true;
        });
        $appConfig->method('getKeys')->willReturnCallback(fn () => array_keys($this->config));
        $appConfig->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
            unset($this->config[$key]);
        });

        $jobs = $this->createMock(IJobList::class);
        $jobs->method('countByClass')->willReturnCallback(fn () => [['class' => ApplyDefaultsJob::class, 'count' => $this->pending]]);
        $jobs->method('add')->willReturnCallback(function (string $class, array $argument): void {
            $this->queued[] = [$argument['groupfolderId'], $argument['fileIds']];
        });

        $defaults = $this->createMock(DefaultsService::class);
        $defaults->method('getGroupfoldersWithDefaults')->willReturn($groupfolderIds);
        $defaults->method('getFolderDefaults')->willReturn(['afdeling' => 'Sales']);
        $defaults->method('maxFileId')->willReturnCallback(fn () => $this->maxFileId);
        $defaults->method('findFilesMissingDefaults')->willReturnCallback(function (int $gf, array $fields, int $limit, int $after, ?int $upTo): array {
            $this->ranges[] = [$after, $upTo];
            $found = array_values(array_filter($this->missing[$gf] ?? [], fn (int $id) => $id > $after && $id <= $upTo));
            return \array_slice($found, 0, $limit);
        });

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(fn () => $this->clock === [] ? $now : array_shift($this->clock));

        $job = new DiscoverMissingDefaultsJob($time, $defaults, new DefaultsDiscovery($appConfig, $jobs), $jobs, $this->createMock(LoggerInterface::class));
        (new \ReflectionMethod($job, 'run'))->invoke($job, null);
    }
}
