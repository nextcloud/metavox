<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Performance;

/**
 * Concurrency performance tests
 *
 * Test scenarios:
 * 1. Meerdere gebruikers lezen tegelijk
 * 2. Meerdere gebruikers schrijven tegelijk
 * 3. Mixed read/write operaties
 * 4. Deadlock detectie
 * 5. Lock contention metingen
 * 6. Throughput onder load
 *
 * BELANGRIJK: Deze tests gebruiken PHP pcntl extension voor process forking
 * of simuleren concurrency via rapid sequential operations als pcntl niet beschikbaar is
 */
class ConcurrencyTest extends PerformanceTestBase {

    private bool $pcntlAvailable;
    private array $testFields = [];
    private array $testFiles = [];

    public function __construct(...$args) {
        parent::__construct(...$args);
        $this->pcntlAvailable = extension_loaded('pcntl');

        if (!$this->pcntlAvailable) {
            $this->log("WARNING: pcntl extension not available. Using simulated concurrency.", 'warning');
        }
    }

    public function run(): void {
        $this->log("=== Concurrency Performance Tests ===");
        $this->log("Concurrency mode: " . ($this->pcntlAvailable ? "Real (pcntl)" : "Simulated"));

        $this->loadTestData();
        $this->testConcurrentReads();
        $this->testConcurrentWrites();
        $this->testMixedReadWrite();
        $this->testDeadlockScenarios();
        $this->testThroughputUnderLoad();

        $this->saveMetrics('concurrency_' . date('Y-m-d_His') . '.json');
        $this->printResults();
    }

    /**
     * Laad test data
     */
    private function loadTestData(): void {
        $this->log("Loading test data...");

        $allFields = $this->fieldService->getAllFields();
        $this->testFields = array_filter(
            $allFields,
            fn($f) => str_starts_with($f['field_name'], 'perf_test_')
        );

        $user = $this->userSession->getUser();
        if ($user) {
            $userFolder = $this->rootFolder->getUserFolder($user->getUID());
            try {
                $testFolder = $userFolder->get('metavox_performance_test');
                $files = array_slice($testFolder->getDirectoryListing(), 0, 100); // Eerste 100 files

                foreach ($files as $file) {
                    $this->testFiles[] = [
                        'id' => $file->getId(),
                        'name' => $file->getName(),
                    ];
                }
            } catch (\Exception $e) {
                $this->log("Could not load test files: " . $e->getMessage(), 'warning');
            }
        }

        $this->log("Loaded " . count($this->testFields) . " fields, " . count($this->testFiles) . " files");
    }

    /**
     * Test concurrent reads (meerdere gebruikers lezen tegelijk)
     */
    private function testConcurrentReads(): void {
        $this->log("\n--- Testing concurrent reads ---");

        $concurrentUsers = $this->config['concurrent_users'];
        $operationsPerUser = 50;

        if ($this->pcntlAvailable) {
            $this->testConcurrentReadsReal($concurrentUsers, $operationsPerUser);
        } else {
            $this->testConcurrentReadsSimulated($concurrentUsers, $operationsPerUser);
        }
    }

    /**
     * Echte concurrent reads met process forking
     */
    private function testConcurrentReadsReal(int $users, int $operations): void {
        $pids = [];
        $startTime = microtime(true);

        for ($i = 0; $i < $users; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->log("Could not fork process", 'error');
                continue;
            } elseif ($pid === 0) {
                // Child process
                for ($j = 0; $j < $operations; $j++) {
                    $this->fieldService->getAllFields();

                    if (!empty($this->testFiles)) {
                        $randomFile = $this->testFiles[array_rand($this->testFiles)];
                        $this->fieldService->getFieldMetadata((int) $randomFile['id']);
                    }
                }
                exit(0);
            } else {
                // Parent process
                $pids[] = $pid;
            }
        }

        // Wait for all children
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $duration = microtime(true) - $startTime;
        $totalOps = $users * $operations * 2; // 2 operaties per iteratie
        $opsPerSecond = $totalOps / $duration;

        $this->metrics[] = [
            'name' => 'concurrent_reads_real',
            'concurrent_users' => $users,
            'operations_per_user' => $operations,
            'total_operations' => $totalOps,
            'duration_seconds' => $duration,
            'ops_per_second' => $opsPerSecond,
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        $this->log(sprintf(
            "Concurrent reads (%d users): %.2f ops/sec (%d ops in %.2fs)",
            $users,
            $opsPerSecond,
            $totalOps,
            $duration
        ));

        // Target: >1000 reads/sec
        $this->evaluatePerformance($opsPerSecond, 1000, 500, 'Concurrent reads throughput', true);
    }

    /**
     * Gesimuleerde concurrent reads (rapid sequential)
     */
    private function testConcurrentReadsSimulated(int $users, int $operations): void {
        $startTime = microtime(true);
        $totalOps = $users * $operations * 2;

        for ($i = 0; $i < $users; $i++) {
            for ($j = 0; $j < $operations; $j++) {
                $this->fieldService->getAllFields();

                if (!empty($this->testFiles)) {
                    $randomFile = $this->testFiles[array_rand($this->testFiles)];
                    $this->fieldService->getFieldMetadata((int) $randomFile['id']);
                }
            }
        }

        $duration = microtime(true) - $startTime;
        $opsPerSecond = $totalOps / $duration;

        $this->metrics[] = [
            'name' => 'concurrent_reads_simulated',
            'concurrent_users' => $users,
            'operations_per_user' => $operations,
            'total_operations' => $totalOps,
            'duration_seconds' => $duration,
            'ops_per_second' => $opsPerSecond,
            'note' => 'Simulated concurrency (sequential)',
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        $this->log(sprintf(
            "Simulated concurrent reads (%d users): %.2f ops/sec (%d ops in %.2fs)",
            $users,
            $opsPerSecond,
            $totalOps,
            $duration
        ));
    }

    /**
     * Test concurrent writes
     */
    private function testConcurrentWrites(): void {
        $this->log("\n--- Testing concurrent writes ---");

        if (empty($this->testFields) || empty($this->testFiles)) {
            $this->log("No test data available", 'warning');
            return;
        }

        $concurrentUsers = min(5, $this->config['concurrent_users']); // Minder users voor writes
        $operationsPerUser = 20;

        if ($this->pcntlAvailable) {
            $this->testConcurrentWritesReal($concurrentUsers, $operationsPerUser);
        } else {
            $this->testConcurrentWritesSimulated($concurrentUsers, $operationsPerUser);
        }
    }

    /**
     * Echte concurrent writes
     */
    private function testConcurrentWritesReal(int $users, int $operations): void {
        $pids = [];
        $startTime = microtime(true);
        $deadlocks = 0;

        for ($i = 0; $i < $users; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                continue;
            } elseif ($pid === 0) {
                // Child process
                for ($j = 0; $j < $operations; $j++) {
                    $randomFile = $this->testFiles[array_rand($this->testFiles)];
                    $randomField = $this->testFields[array_rand($this->testFields)];

                    try {
                        $this->fieldService->saveFieldValue(
                            (int) $randomFile['id'],
                            (int) $randomField['id'],
                            "concurrent_test_value_$i_$j"
                        );
                    } catch (\Exception $e) {
                        if (str_contains($e->getMessage(), 'deadlock')) {
                            $deadlocks++;
                        }
                    }
                }
                exit(0);
            } else {
                $pids[] = $pid;
            }
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $duration = microtime(true) - $startTime;
        $totalOps = $users * $operations;
        $opsPerSecond = $totalOps / $duration;

        $this->metrics[] = [
            'name' => 'concurrent_writes_real',
            'concurrent_users' => $users,
            'operations_per_user' => $operations,
            'total_operations' => $totalOps,
            'duration_seconds' => $duration,
            'ops_per_second' => $opsPerSecond,
            'deadlocks' => $deadlocks,
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        $this->log(sprintf(
            "Concurrent writes (%d users): %.2f writes/sec (%d ops in %.2fs, %d deadlocks)",
            $users,
            $opsPerSecond,
            $totalOps,
            $duration,
            $deadlocks
        ));

        // Target: >100 writes/sec, <10 deadlocks
        $this->evaluatePerformance($opsPerSecond, 100, 50, 'Concurrent writes throughput', true);

        if ($deadlocks > 10) {
            $this->log("WARNING: High deadlock count ($deadlocks)", 'warning');
        }
    }

    /**
     * Gesimuleerde concurrent writes
     */
    private function testConcurrentWritesSimulated(int $users, int $operations): void {
        $startTime = microtime(true);
        $totalOps = $users * $operations;

        for ($i = 0; $i < $users; $i++) {
            for ($j = 0; $j < $operations; $j++) {
                $randomFile = $this->testFiles[array_rand($this->testFiles)];
                $randomField = $this->testFields[array_rand($this->testFields)];

                $this->fieldService->saveFieldValue(
                    (int) $randomFile['id'],
                    (int) $randomField['id'],
                    "concurrent_test_value_$i_$j"
                );
            }
        }

        $duration = microtime(true) - $startTime;
        $opsPerSecond = $totalOps / $duration;

        $this->metrics[] = [
            'name' => 'concurrent_writes_simulated',
            'concurrent_users' => $users,
            'operations_per_user' => $operations,
            'total_operations' => $totalOps,
            'duration_seconds' => $duration,
            'ops_per_second' => $opsPerSecond,
            'note' => 'Simulated concurrency (sequential)',
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        $this->log(sprintf(
            "Simulated concurrent writes (%d users): %.2f writes/sec (%d ops in %.2fs)",
            $users,
            $opsPerSecond,
            $totalOps,
            $duration
        ));
    }

    /**
     * Test mixed read/write operaties
     */
    private function testMixedReadWrite(): void {
        $this->log("\n--- Testing mixed read/write ---");

        $operations = 100;
        $readRatio = 0.7; // 70% reads, 30% writes

        $startTime = microtime(true);

        for ($i = 0; $i < $operations; $i++) {
            if (rand(0, 100) < ($readRatio * 100)) {
                // Read operatie
                $this->fieldService->getAllFields();
            } else {
                // Write operatie
                if (!empty($this->testFields) && !empty($this->testFiles)) {
                    $randomFile = $this->testFiles[array_rand($this->testFiles)];
                    $randomField = $this->testFields[array_rand($this->testFields)];

                    $this->fieldService->saveFieldValue(
                        (int) $randomFile['id'],
                        (int) $randomField['id'],
                        "mixed_test_value_$i"
                    );
                }
            }
        }

        $duration = microtime(true) - $startTime;
        $opsPerSecond = $operations / $duration;

        $this->metrics[] = [
            'name' => 'mixed_read_write',
            'total_operations' => $operations,
            'read_ratio' => $readRatio,
            'duration_seconds' => $duration,
            'ops_per_second' => $opsPerSecond,
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        $this->log(sprintf(
            "Mixed read/write (%.0f%% reads): %.2f ops/sec",
            $readRatio * 100,
            $opsPerSecond
        ));
    }

    /**
     * Test deadlock scenario's
     */
    private function testDeadlockScenarios(): void {
        $this->log("\n--- Testing deadlock scenarios ---");

        // Dit is een complex scenario dat moeilijk te simuleren is zonder echte concurrency
        // We loggen alleen dat deze test zou moeten worden uitgevoerd met echte load

        $this->log("Deadlock testing requires real concurrent load");
        $this->log("Run this test manually with multiple concurrent users");
        $this->log("Monitor database deadlocks with: SHOW ENGINE INNODB STATUS");
    }

    /**
     * Test throughput onder sustained load
     */
    private function testThroughputUnderLoad(): void {
        $this->log("\n--- Testing throughput under sustained load ---");

        $duration = 30; // 30 seconden sustained load
        $startTime = microtime(true);
        $operations = 0;

        $this->log("Running sustained load for $duration seconds...");

        while ((microtime(true) - $startTime) < $duration) {
            // Mix van operaties
            $op = rand(0, 2);

            switch ($op) {
                case 0: // Read all fields
                    $this->fieldService->getAllFields();
                    break;

                case 1: // Read metadata
                    if (!empty($this->testFiles)) {
                        $randomFile = $this->testFiles[array_rand($this->testFiles)];
                        $this->fieldService->getFieldMetadata((int) $randomFile['id']);
                    }
                    break;

                case 2: // Write metadata
                    if (!empty($this->testFields) && !empty($this->testFiles)) {
                        $randomFile = $this->testFiles[array_rand($this->testFiles)];
                        $randomField = $this->testFields[array_rand($this->testFields)];
                        $this->fieldService->saveFieldValue(
                            (int) $randomFile['id'],
                            (int) $randomField['id'],
                            "load_test_value_$operations"
                        );
                    }
                    break;
            }

            $operations++;
        }

        $actualDuration = microtime(true) - $startTime;
        $opsPerSecond = $operations / $actualDuration;

        $this->metrics[] = [
            'name' => 'sustained_load',
            'duration_seconds' => $actualDuration,
            'total_operations' => $operations,
            'ops_per_second' => $opsPerSecond,
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        $this->log(sprintf(
            "Sustained load: %.2f ops/sec (%d ops in %.2fs)",
            $opsPerSecond,
            $operations,
            $actualDuration
        ));
    }

    /**
     * Evalueer performance
     */
    private function evaluatePerformance(
        float $value,
        float $targetThreshold,
        float $criticalThreshold,
        string $metricName,
        bool $higherIsBetter = false
    ): void {
        if ($higherIsBetter) {
            if ($value >= $targetThreshold) {
                $status = 'PASS';
            } elseif ($value >= $criticalThreshold) {
                $status = 'WARNING';
            } else {
                $status = 'FAIL';
            }
        } else {
            if ($value <= $targetThreshold) {
                $status = 'PASS';
            } elseif ($value <= $criticalThreshold) {
                $status = 'WARNING';
            } else {
                $status = 'FAIL';
            }
        }

        $this->log("$metricName: $status", strtolower($status) === 'fail' ? 'error' : 'info');
    }

    /**
     * Print resultaten
     */
    private function printResults(): void {
        $this->log("\n=== Concurrency Performance Test Results ===");

        $summary = $this->calculateSummary();

        $this->log(sprintf("Total tests: %d", $summary['total_tests']));
        $this->log(sprintf("Successful: %d", $summary['successful_tests']));
        $this->log(sprintf("Failed: %d", $summary['failed_tests']));
        $this->log(sprintf("Total duration: %.2f minutes", $summary['total_duration_minutes']));
    }
}
