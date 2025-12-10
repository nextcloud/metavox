<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Performance;

/**
 * Performance tests voor FieldService
 *
 * Test scenarios:
 * 1. getAllFields() performance met 10M records
 * 2. getFieldById() met verschillende cache scenarios
 * 3. saveFieldValue() throughput (writes/sec)
 * 4. getBulkFileMetadata() met verschillende batch sizes
 * 5. getFieldMetadata() voor single file
 * 6. Cache warming en cache hit rates
 */
class FieldServiceTest extends PerformanceTestBase {

    private array $testFields = [];
    private array $testFiles = [];

    public function run(): void {
        $this->log("=== FieldService Performance Tests ===");

        $this->loadTestData();
        $this->testGetAllFields();
        $this->testGetFieldById();
        $this->testSaveFieldValue();
        $this->testGetFieldMetadata();
        $this->testBulkFileMetadata();
        $this->testCachePerformance();

        $this->saveMetrics('field_service_' . date('Y-m-d_His') . '.json');
        $this->printResults();
    }

    /**
     * Laad test data (velden en files die al bestaan)
     */
    private function loadTestData(): void {
        $this->log("Loading test data...");

        // Haal alle fields op die we gaan testen
        $allFields = $this->measure(
            fn() => $this->fieldService->getAllFields(),
            'load_all_fields'
        );

        // Filter op performance test fields
        $this->testFields = array_filter(
            $allFields,
            fn($f) => str_starts_with($f['field_name'], 'perf_test_')
        );

        $this->log("Loaded " . count($this->testFields) . " test fields");

        // Haal test files op via de user folder
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \RuntimeException("No user logged in");
        }

        $userFolder = $this->rootFolder->getUserFolder($user->getUID());

        try {
            $testFolder = $userFolder->get('metavox_performance_test');
            $files = $testFolder->getDirectoryListing();

            foreach ($files as $file) {
                $this->testFiles[] = [
                    'id' => $file->getId(),
                    'name' => $file->getName(),
                    'path' => $file->getPath(),
                ];
            }

            $this->log("Loaded " . count($this->testFiles) . " test files");
        } catch (\Exception $e) {
            $this->log("Could not load test files: " . $e->getMessage(), 'warning');
        }
    }

    /**
     * Test getAllFields() performance
     */
    private function testGetAllFields(): void {
        $this->log("\n--- Testing getAllFields() ---");

        // Cold cache (reset cache eerst)
        $stats = $this->benchmark(
            fn() => $this->fieldService->getAllFields(),
            'getAllFields_cold_cache',
            $this->config['test_iterations']
        );

        $this->log(sprintf(
            "getAllFields(): avg=%.2fms, median=%.2fms, p95=%.2fms, p99=%.2fms",
            $stats['duration_avg_ms'],
            $stats['duration_median_ms'],
            $stats['duration_p95_ms'],
            $stats['duration_p99_ms']
        ));

        $this->evaluatePerformance($stats['duration_median_ms'], 100, 500, 'getAllFields()');
    }

    /**
     * Test getFieldById() performance
     */
    private function testGetFieldById(): void {
        $this->log("\n--- Testing getFieldById() ---");

        if (empty($this->testFields)) {
            $this->log("No test fields available", 'warning');
            return;
        }

        $randomField = $this->testFields[array_rand($this->testFields)];

        $stats = $this->benchmark(
            fn() => $this->fieldService->getFieldById((int) $randomField['id']),
            'getFieldById',
            $this->config['test_iterations'],
            ['field_id' => $randomField['id']]
        );

        $this->log(sprintf(
            "getFieldById(): avg=%.2fms, median=%.2fms",
            $stats['duration_avg_ms'],
            $stats['duration_median_ms']
        ));

        $this->evaluatePerformance($stats['duration_median_ms'], 5, 20, 'getFieldById()');
    }

    /**
     * Test saveFieldValue() throughput
     */
    private function testSaveFieldValue(): void {
        $this->log("\n--- Testing saveFieldValue() throughput ---");

        if (empty($this->testFields) || empty($this->testFiles)) {
            $this->log("No test data available", 'warning');
            return;
        }

        $randomField = $this->testFields[array_rand($this->testFields)];
        $randomFile = $this->testFiles[array_rand($this->testFiles)];

        // Test individuele writes
        $writeCount = 100;
        $startTime = microtime(true);

        for ($i = 0; $i < $writeCount; $i++) {
            $this->fieldService->saveFieldValue(
                (int) $randomFile['id'],
                (int) $randomField['id'],
                "test_value_$i"
            );
        }

        $duration = microtime(true) - $startTime;
        $writesPerSecond = $writeCount / $duration;

        $this->metrics[] = [
            'name' => 'saveFieldValue_throughput',
            'writes_per_second' => $writesPerSecond,
            'total_writes' => $writeCount,
            'duration_seconds' => $duration,
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        $this->log(sprintf(
            "saveFieldValue() throughput: %.2f writes/sec (%d writes in %.2fs)",
            $writesPerSecond,
            $writeCount,
            $duration
        ));

        $this->evaluatePerformance($writesPerSecond, 100, 50, 'saveFieldValue() throughput', true);
    }

    /**
     * Test getFieldMetadata() voor single file
     */
    private function testGetFieldMetadata(): void {
        $this->log("\n--- Testing getFieldMetadata() ---");

        if (empty($this->testFiles)) {
            $this->log("No test files available", 'warning');
            return;
        }

        $randomFile = $this->testFiles[array_rand($this->testFiles)];

        $stats = $this->benchmark(
            fn() => $this->fieldService->getFieldMetadata((int) $randomFile['id']),
            'getFieldMetadata',
            $this->config['test_iterations'],
            ['file_id' => $randomFile['id']]
        );

        $this->log(sprintf(
            "getFieldMetadata(): avg=%.2fms, median=%.2fms, p95=%.2fms",
            $stats['duration_avg_ms'],
            $stats['duration_median_ms'],
            $stats['duration_p95_ms']
        ));

        $this->evaluatePerformance($stats['duration_median_ms'], 20, 100, 'getFieldMetadata()');
    }

    /**
     * Test getBulkFileMetadata() met verschillende batch sizes
     */
    private function testBulkFileMetadata(): void {
        $this->log("\n--- Testing getBulkFileMetadata() ---");

        if (empty($this->testFiles)) {
            $this->log("No test files available", 'warning');
            return;
        }

        $batchSizes = [10, 50, 100, 500, 1000];

        foreach ($batchSizes as $batchSize) {
            if ($batchSize > count($this->testFiles)) {
                continue;
            }

            $fileIds = array_slice(
                array_column($this->testFiles, 'id'),
                0,
                $batchSize
            );

            $stats = $this->benchmark(
                fn() => $this->fieldService->getBulkFileMetadata($fileIds),
                "getBulkFileMetadata_batch_$batchSize",
                min(10, $this->config['test_iterations']), // Minder iteraties voor grote batches
                ['batch_size' => $batchSize]
            );

            $this->log(sprintf(
                "getBulkFileMetadata(%d files): avg=%.2fms, median=%.2fms, p95=%.2fms",
                $batchSize,
                $stats['duration_avg_ms'],
                $stats['duration_median_ms'],
                $stats['duration_p95_ms']
            ));

            // Target: <500ms voor 100 files
            if ($batchSize === 100) {
                $this->evaluatePerformance($stats['duration_median_ms'], 500, 2000, 'getBulkFileMetadata(100)');
            }
        }
    }

    /**
     * Test cache performance en hit rates
     */
    private function testCachePerformance(): void {
        $this->log("\n--- Testing cache performance ---");

        // Test 1: Cold cache vs warm cache voor getAllFields()
        // Dit is al getest in testGetAllFields(), maar we kunnen extra metingen doen

        // Test 2: Request-scope caching binnen FieldService
        // De FieldService heeft request-scope caching, dus herhaalde calls binnen 1 request
        // moeten veel sneller zijn

        $iterations = 100;
        $startTime = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $this->fieldService->getAllFields();
        }

        $duration = microtime(true) - $startTime;
        $avgMs = ($duration / $iterations) * 1000;

        $this->metrics[] = [
            'name' => 'cache_hit_rate_test',
            'iterations' => $iterations,
            'avg_duration_ms' => $avgMs,
            'total_duration_ms' => $duration * 1000,
            'description' => 'Repeated getAllFields() calls (should hit cache)',
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        $this->log(sprintf(
            "Cache hit rate test: %.2fms avg over %d calls (should be <1ms if cached)",
            $avgMs,
            $iterations
        ));
    }

    /**
     * Evalueer of performance binnen targets valt
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
     * Print test resultaten
     */
    private function printResults(): void {
        $this->log("\n=== FieldService Performance Test Results ===");

        $summary = $this->calculateSummary();

        $this->log(sprintf("Total tests: %d", $summary['total_tests']));
        $this->log(sprintf("Successful: %d", $summary['successful_tests']));
        $this->log(sprintf("Failed: %d", $summary['failed_tests']));
        $this->log(sprintf("Total duration: %.2f minutes", $summary['total_duration_minutes']));

        $this->log("\nDetailed results saved to: tests/Performance/results/");
    }
}
