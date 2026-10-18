<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\BackgroundJobs;

use OCA\MetaVox\BackgroundJobs\BuildSearchWords;
use OCA\MetaVox\Service\SearchWordsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BuildSearchWordsTest extends TestCase {

    /** What is on the job list: run after => argument, keyed like NC does, by argument. */
    private array $queued = [];

    public function testWorksForItsBudgetThenWaitsForTheNextCronRun(): void {
        $words = $this->createMock(SearchWordsService::class);
        $words->method('filesAfter')->willReturnOnConsecutiveCalls([1, 2, 3], [4, 5]);
        $words->expects($this->exactly(2))->method('reindexRange')
            ->willReturnCallback(function (int $after, ?int $upTo, array $fileIds): void {
                $this->assertContains([$after, $upTo, $fileIds], [[0, 3, [1, 2, 3]], [3, 5, [4, 5]]]);
            });

        // Start, after the first portion, after the second: over the budget.
        $this->runJob($words, [1000, 1010, 1025], ['after' => 0, 'done' => 10, 'total' => 100]);

        $this->assertSame([[1025 + BuildSearchWords::PAUSE, ['after' => 5, 'done' => 15, 'total' => 100]]], array_values($this->queued));
    }

    public function testEndsWithTheFilesAfterTheLast(): void {
        $words = $this->createMock(SearchWordsService::class);
        $words->method('filesAfter')->willReturn([]);
        $words->expects($this->once())->method('reindexRange')->with(42, null, []);
        // Then the filter may use the index.
        $words->expects($this->once())->method('fillDone')->with('g1');

        $this->runJob($words, [1000], ['after' => 42, 'done' => 7, 'total' => 7, 'generation' => 'g1']);

        $this->assertSame([], $this->queued);
    }

    public function testTheGenerationGoesAlongToTheNextRun(): void {
        $words = $this->createMock(SearchWordsService::class);
        $words->method('filesAfter')->willReturn([1, 2]);
        $words->expects($this->never())->method('fillDone');

        $this->runJob($words, [1000, 1025], ['after' => 0, 'done' => 0, 'total' => 9, 'generation' => 'g1']);

        $this->assertSame([[1025 + BuildSearchWords::PAUSE, ['after' => 2, 'done' => 2, 'total' => 9, 'generation' => 'g1']]], array_values($this->queued));
    }

    /** A fill started again since (a second restore) does it all: the old one stops. */
    public function testAFillStartedAgainSinceStops(): void {
        $words = $this->createMock(SearchWordsService::class);
        $words->method('isCurrentFill')->with('old')->willReturn(false);
        $words->expects($this->never())->method('reindexRange');
        $words->expects($this->never())->method('fillDone');

        $this->runJob($words, [1000], ['after' => 10, 'done' => 10, 'total' => 99, 'generation' => 'old']);

        $this->assertSame([], $this->queued);
    }

    public function testGoesOnFromTheLastFinishedPortionAfterAnError(): void {
        $words = $this->createMock(SearchWordsService::class);
        $words->method('filesAfter')->willReturnOnConsecutiveCalls([1, 2], [3]);
        $words->method('reindexRange')->willReturnCallback(function (int $after): void {
            if ($after === 2) {
                throw new \TypeError('not an exception, still caught');
            }
        });

        $this->runJob($words, [1000, 1001], ['after' => 0, 'done' => 0, 'total' => 3]);

        $this->assertSame([[1001 + BuildSearchWords::PAUSE, ['after' => 2, 'done' => 2, 'total' => 3]]], array_values($this->queued));
    }

    public function testARunThatDiesHalfwayLeavesTheNextOneOnTheList(): void {
        $words = $this->createMock(SearchWordsService::class);
        $words->method('filesAfter')->willReturnOnConsecutiveCalls([1, 2], [3]);
        $onTheList = [];
        $words->method('reindexRange')->willReturnCallback(function (int $after) use (&$onTheList): void {
            // What a kill at this moment would leave behind.
            $onTheList[$after] = array_values($this->queued);
        });

        $this->runJob($words, [1000, 1001, 1002], ['after' => 0, 'done' => 0, 'total' => 3]);

        $this->assertSame([[1000 + BuildSearchWords::PAUSE, ['after' => 0, 'done' => 0, 'total' => 3]]], $onTheList[0]);
        $this->assertSame([[1001 + BuildSearchWords::PAUSE, ['after' => 2, 'done' => 2, 'total' => 3]]], $onTheList[2]);
    }

    /**
     * @param list<int> $times what the clock says, in turn
     */
    private function runJob(SearchWordsService $words, array $times, array $argument): void {
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturnOnConsecutiveCalls(...$times);
        $jobs = $this->createMock(IJobList::class);
        $jobs->method('scheduleAfter')->willReturnCallback(function (string $class, int $runAfter, mixed $argument): void {
            $this->assertSame(BuildSearchWords::class, $class);
            $this->queued[json_encode($argument)] = [$runAfter, $argument];
        });
        $jobs->method('remove')->willReturnCallback(function (string $class, mixed $argument): void {
            $this->assertSame(BuildSearchWords::class, $class);
            $this->assertArrayHasKey(json_encode($argument), $this->queued, 'removes only what it put there');
            unset($this->queued[json_encode($argument)]);
        });
        // The fill being run is the current one, unless the test says otherwise.
        $words->method('isCurrentFill')->willReturn(true);
        $job = new BuildSearchWords($time, $words, $jobs, $this->createMock(LoggerInterface::class));
        (new \ReflectionMethod($job, 'run'))->invoke($job, $argument);
    }
}
