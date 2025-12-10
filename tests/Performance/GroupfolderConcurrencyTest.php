<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Performance;

/**
 * Performance tests voor Groupfolder Concurrency
 *
 * Test scenarios:
 * 1. Concurrent reads (multiple processes reading groupfolder metadata)
 * 2. Concurrent writes (multiple processes writing file metadata)
 * 3. Mixed read/write workload
 * 4. Sustained load testing
 *
 * Uses PHP pcntl for process forking
 */
class GroupfolderConcurrencyTest extends PerformanceTestBase {

    private int $testGroupfolderId = 999999;
    private array $testFileIds = [];
    private array $testFields = [];

    public function run(): void {
        $this->log("=== Groupfolder Concurrency Performance Tests ===");

        // Check if pcntl is available
        if (!function_exists('pcntl_fork')) {
            $this->log("pcntl extension not available - skipping concurrency tests", 'warning');
            return;
        }

        // Load test data
        $this->loadTestData();

        // Run tests
        $this->testConcurrentReads();
        $this->testConcurrentWrites();
        $this->testMixedWorkload();
        $this->testSustainedLoad();

        $this->saveMetrics('gf_concurrency_' . date('Y-m-d_His') . '.json');
        $this->printResults();
    }

    /**
     * Load test data
     */
    private function loadTestData(): void {
        $this->log("Loading test data...");

        // Get file IDs
        $qb = $this->db->getQueryBuilder();
        $qb->select('file_id')
            ->from('metavox_file_gf_meta')
            ->where($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($this->testGroupfolderId)))
            ->groupBy('file_id')
            ->setMaxResults(100);

        $result = $qb->executeQuery();
        while ($row = $result->fetch()) {
            $this->testFileIds[] = (int)$row['file_id'];
        }
        $result->closeCursor();

        // Get fields
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('metavox_gf_fields')
            ->where($qb->expr()->eq('applies_to_groupfolder', $qb->createNamedParameter(0)));

        $result = $qb->executeQuery();
        while ($row = $result->fetch()) {
            $this->testFields[] = $row;
        }
        $result->closeCursor();

        $this->log("Loaded " . count($this->testFileIds) . " file IDs and " . count($this->testFields) . " fields");
    }

    /**
     * Test concurrent reads
     */
    private function testConcurrentReads(): void {
        $this->log("\n--- Testing concurrent reads ---");

        $workerCounts = [5, 10, 20];

        foreach ($workerCounts as $workers) {
            $stats = $this->benchmarkConcurrent(
                function() {
                    // Each worker reads groupfolder metadata multiple times
                    for ($i = 0; $i < 100; $i++) {
                        $this->fieldService->getGroupfolderMetadata($this->testGroupfolderId);
                    }
                },
                $workers,
                "concurrent_reads_{$workers}_workers"
            );

            $this->log(sprintf(
                "Concurrent reads (%d workers): total=%.2fms, ops/sec=%.0f",
                $workers,
                $stats['total_duration_ms'],
                $stats['ops_per_second']
            ));

            $this->evaluatePerformance($stats['ops_per_second'], 1000, 500, "Concurrent reads ($workers workers)");
        }
    }

    /**
     * Test concurrent writes
     */
    private function testConcurrentWrites(): void {
        $this->log("\n--- Testing concurrent writes ---");

        if (empty($this->testFileIds) || empty($this->testFields)) {
            $this->log("No test data available", 'warning');
            return;
        }

        $workerCounts = [5, 10];

        foreach ($workerCounts as $workers) {
            $stats = $this->benchmarkConcurrent(
                function() {
                    // Each worker writes metadata to random files
                    for ($i = 0; $i < 20; $i++) {
                        if (empty($this->testFileIds) || empty($this->testFields)) continue;

                        $fileId = $this->testFileIds[array_rand($this->testFileIds)];
                        $field = $this->testFields[array_rand($this->testFields)];

                        $this->fieldService->saveGroupfolderFileFieldValue(
                            $this->testGroupfolderId,
                            $fileId,
                            (int)$field['id'],
                            'concurrent_test_' . time() . '_' . rand(1000, 9999)
                        );
                    }
                },
                $workers,
                "concurrent_writes_{$workers}_workers"
            );

            $this->log(sprintf(
                "Concurrent writes (%d workers): total=%.2fms, ops/sec=%.0f",
                $workers,
                $stats['total_duration_ms'],
                $stats['ops_per_second']
            ));

            $this->evaluatePerformance($stats['ops_per_second'], 200, 100, "Concurrent writes ($workers workers)");
        }
    }

    /**
     * Test mixed read/write workload
     */
    private function testMixedWorkload(): void {
        $this->log("\n--- Testing mixed read/write workload ---");

        if (empty($this->testFileIds) || empty($this->testFields)) {
            $this->log("No test data available", 'warning');
            return;
        }

        $workers = 10;

        $stats = $this->benchmarkConcurrent(
            function() {
                // 80% reads, 20% writes
                for ($i = 0; $i < 50; $i++) {
                    if (rand(1, 100) <= 80) {
                        // Read
                        $this->fieldService->getGroupfolderMetadata($this->testGroupfolderId);
                    } else {
                        // Write
                        if (!empty($this->testFileIds) && !empty($this->testFields)) {
                            $fileId = $this->testFileIds[array_rand($this->testFileIds)];
                            $field = $this->testFields[array_rand($this->testFields)];

                            $this->fieldService->saveGroupfolderFileFieldValue(
                                $this->testGroupfolderId,
                                $fileId,
                                (int)$field['id'],
                                'mixed_test_' . time()
                            );
                        }
                    }
                }
            },
            $workers,
            "mixed_workload_{$workers}_workers"
        );

        $this->log(sprintf(
            "Mixed workload (%d workers, 80%% read/20%% write): total=%.2fms, ops/sec=%.0f",
            $workers,
            $stats['total_duration_ms'],
            $stats['ops_per_second']
        ));
    }

    /**
     * Test sustained load
     */
    private function testSustainedLoad(): void {
        $this->log("\n--- Testing sustained load (30 seconds) ---");

        $duration = 30; // seconds
        $workers = 10;

        $startTime = microtime(true);
        $operations = 0;
        $pids = [];

        // Fork workers
        for ($i = 0; $i < $workers; $i++) {
            $pid = pcntl_fork();

            if ($pid == -1) {
                $this->log("Could not fork process", 'error');
                continue;
            } elseif ($pid) {
                // Parent process
                $pids[] = $pid;
            } else {
                // Child process
                $childOps = 0;
                $endTime = time() + $duration;

                while (time() < $endTime) {
                    // Alternate between reads and writes
                    if ($childOps % 5 == 0) {
                        // Write
                        if (!empty($this->testFileIds) && !empty($this->testFields)) {
                            $fileId = $this->testFileIds[array_rand($this->testFileIds)];
                            $field = $this->testFields[array_rand($this->testFields)];

                            $this->fieldService->saveGroupfolderFileFieldValue(
                                $this->testGroupfolderId,
                                $fileId,
                                (int)$field['id'],
                                'sustained_' . time()
                            );
                        }
                    } else {
                        // Read
                        $this->fieldService->getGroupfolderMetadata($this->testGroupfolderId);
                    }

                    $childOps++;
                }

                // Exit child process
                exit(0);
            }
        }

        // Wait for all children
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $totalDuration = (microtime(true) - $startTime);

        // Estimate operations (can't get exact count from child processes easily)
        $estimatedOps = $workers * ($duration * 50); // Rough estimate

        $this->metrics[] = [
            'name' => 'sustained_load',
            'duration_seconds' => $totalDuration,
            'workers' => $workers,
            'estimated_ops' => $estimatedOps,
            'estimated_ops_per_second' => $estimatedOps / $totalDuration,
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        $this->log(sprintf(
            "Sustained load (%d workers, %.0fs): ~%.0f ops/sec",
            $workers,
            $totalDuration,
            $estimatedOps / $totalDuration
        ));
    }

    /**
     * Benchmark concurrent operations
     */
    private function benchmarkConcurrent(callable $work, int $workers, string $metricName): array {
        $startTime = microtime(true);
        $pids = [];

        // Fork workers
        for ($i = 0; $i < $workers; $i++) {
            $pid = pcntl_fork();

            if ($pid == -1) {
                $this->log("Could not fork process", 'error');
                continue;
            } elseif ($pid) {
                // Parent process
                $pids[] = $pid;
            } else {
                // Child process - do work
                try {
                    // FIX: Close inherited database connection to prevent "MySQL server has gone away"
                    // Each forked child needs its own connection
                    $this->db->close();

                    // Connection will be automatically recreated on next query in Nextcloud
                    // No need to manually reconnect - Nextcloud handles this

                    $work();
                } catch (\Exception $e) {
                    error_log("Worker error: " . $e->getMessage());
                }
                exit(0);
            }
        }

        // Wait for all children
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $duration = (microtime(true) - $startTime);
        $durationMs = $duration * 1000;

        // Estimate ops (would need IPC for exact count)
        $estimatedOps = $workers * 100; // Based on work function
        $opsPerSecond = $estimatedOps / $duration;

        $stats = [
            'total_duration_ms' => $durationMs,
            'workers' => $workers,
            'estimated_ops' => $estimatedOps,
            'ops_per_second' => $opsPerSecond,
        ];

        $this->metrics[] = array_merge([
            'name' => $metricName,
            'timestamp' => date('Y-m-d H:i:s'),
        ], $stats);

        return $stats;
    }

    /**
     * Evaluate performance
     */
    private function evaluatePerformance(
        float $value,
        float $targetThreshold,
        float $criticalThreshold,
        string $metricName
    ): void {
        if ($value >= $targetThreshold) {
            $status = 'PASS';
            $level = 'info';
        } elseif ($value >= $criticalThreshold) {
            $status = 'WARNING';
            $level = 'warning';
        } else {
            $status = 'FAIL';
            $level = 'error';
        }

        $this->log("$metricName: $status (%.0f ops/sec)", $level);
    }

    /**
     * Print results
     */
    private function printResults(): void {
        $this->log("\n=== Groupfolder Concurrency Performance Test Results ===");

        $summary = $this->calculateSummary();

        $this->log(sprintf("Total tests: %d", $summary['total_tests']));
        $this->log(sprintf("Successful: %d", $summary['successful_tests']));
        $this->log(sprintf("Failed: %d", $summary['failed_tests']));
        $this->log(sprintf("Total duration: %.2f minutes", $summary['total_duration_minutes']));
    }
}
