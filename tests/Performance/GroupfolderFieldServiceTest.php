<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Performance;

/**
 * Performance tests voor Groupfolder Field Service
 *
 * Test scenarios (via FieldService, niet direct DB!):
 * 1. getGroupfolderMetadata() - groupfolder-level metadata ophalen
 * 2. getGroupfolderFileMetadata() - file metadata binnen groupfolder ophalen
 * 3. Bulk operations - meerdere files tegelijk
 * 4. Cache performance - groupfolder metadata caching
 *
 * Gebruikt ALLEEN FieldService (production service)!
 */
class GroupfolderFieldServiceTest extends PerformanceTestBase {

    private int $testGroupfolderId = 999999;
    private array $testFileIds = [];

    public function run(): void {
        $this->log("=== Groupfolder FieldService Performance Tests ===");

        // Load test data
        $this->loadTestData();

        // Run tests
        $this->testGetGroupfolderMetadata();
        $this->testGetGroupfolderFileMetadata();
        $this->testBulkFileMetadata();
        $this->testGroupfolderMetadataCache();

        // Save results
        $this->saveMetrics('gf_field_service_' . date('Y-m-d_His') . '.json');
        $this->printResults();
    }

    /**
     * Load test file IDs from DB
     */
    private function loadTestData(): void {
        $this->log("Loading test data...");

        // Get file IDs from test groupfolder
        $qb = $this->db->getQueryBuilder();
        $qb->select('file_id')
            ->from('metavox_file_gf_meta')
            ->where($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($this->testGroupfolderId)))
            ->groupBy('file_id')
            ->setMaxResults(1000);

        $result = $qb->executeQuery();
        while ($row = $result->fetch()) {
            $this->testFileIds[] = (int)$row['file_id'];
        }
        $result->closeCursor();

        $this->log("Loaded " . count($this->testFileIds) . " test file IDs");
    }

    /**
     * Test getGroupfolderMetadata() performance
     */
    private function testGetGroupfolderMetadata(): void {
        $this->log("\n--- Testing getGroupfolderMetadata() ---");

        $stats = $this->benchmark(
            fn() => $this->fieldService->getGroupfolderMetadata($this->testGroupfolderId),
            'getGroupfolderMetadata',
            $this->config['test_iterations']
        );

        $this->log(sprintf(
            "getGroupfolderMetadata(): avg=%.2fms, median=%.2fms, p95=%.2fms, p99=%.2fms",
            $stats['duration_avg_ms'],
            $stats['duration_median_ms'],
            $stats['duration_p95_ms'],
            $stats['duration_p99_ms']
        ));

        // Target: <50ms voor groupfolder metadata
        $this->evaluatePerformance($stats['duration_median_ms'], 50, 200, 'getGroupfolderMetadata');
    }

    /**
     * Test getGroupfolderFileMetadata() performance
     */
    private function testGetGroupfolderFileMetadata(): void {
        $this->log("\n--- Testing getGroupfolderFileMetadata() ---");

        if (empty($this->testFileIds)) {
            $this->log("No test files available", 'warning');
            return;
        }

        // Test met random file
        $testFileId = $this->testFileIds[array_rand($this->testFileIds)];

        $stats = $this->benchmark(
            fn() => $this->fieldService->getGroupfolderFileMetadata($this->testGroupfolderId, $testFileId),
            'getGroupfolderFileMetadata',
            $this->config['test_iterations'],
            ['file_id' => $testFileId]
        );

        $this->log(sprintf(
            "getGroupfolderFileMetadata(): avg=%.2fms, median=%.2fms, p95=%.2fms",
            $stats['duration_avg_ms'],
            $stats['duration_median_ms'],
            $stats['duration_p95_ms']
        ));

        // Target: <20ms voor single file metadata
        $this->evaluatePerformance($stats['duration_median_ms'], 20, 100, 'getGroupfolderFileMetadata');
    }

    /**
     * Test bulk file metadata operations
     */
    private function testBulkFileMetadata(): void {
        $this->log("\n--- Testing bulk file metadata operations ---");

        if (count($this->testFileIds) < 100) {
            $this->log("Not enough test files for bulk operations", 'warning');
            return;
        }

        $bulkSizes = [10, 50, 100, 500];

        foreach ($bulkSizes as $size) {
            if ($size > count($this->testFileIds)) {
                continue;
            }

            $fileIds = array_slice($this->testFileIds, 0, $size);

            $stats = $this->benchmark(
                function() use ($fileIds) {
                    $results = [];
                    foreach ($fileIds as $fileId) {
                        $results[] = $this->fieldService->getGroupfolderFileMetadata(
                            $this->testGroupfolderId,
                            $fileId
                        );
                    }
                    return $results;
                },
                "bulk_file_metadata_{$size}",
                min(5, $this->config['test_iterations']),
                ['file_count' => $size]
            );

            $avgPerFile = $stats['duration_median_ms'] / $size;

            $this->log(sprintf(
                "Bulk %d files: total=%.2fms, avg per file=%.2fms",
                $size,
                $stats['duration_median_ms'],
                $avgPerFile
            ));
        }
    }

    /**
     * Test groupfolder metadata caching
     */
    private function testGroupfolderMetadataCache(): void {
        $this->log("\n--- Testing groupfolder metadata cache ---");

        // Cold cache
        $coldResult = $this->measure(
            fn() => $this->fieldService->getGroupfolderMetadata($this->testGroupfolderId),
            'gf_metadata_cold_cache'
        );

        // Warm cache - herhaalde calls
        $startTime = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            $this->fieldService->getGroupfolderMetadata($this->testGroupfolderId);
        }
        $warmDuration = (microtime(true) - $startTime) * 1000;
        $warmAvg = $warmDuration / 100;

        $this->metrics[] = [
            'name' => 'gf_metadata_warm_cache',
            'avg_duration_ms' => $warmAvg,
            'total_duration_ms' => $warmDuration,
            'iterations' => 100,
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        $this->log(sprintf(
            "Cache performance: cold=%.2fms, warm avg=%.2fms (100 calls)",
            $coldResult['duration_ms'] ?? 0,
            $warmAvg
        ));

        if ($warmAvg < 1) {
            $this->log("Groupfolder metadata caching: EXCELLENT", 'info');
        } elseif ($warmAvg < 10) {
            $this->log("Groupfolder metadata caching: GOOD", 'info');
        } else {
            $this->log("Groupfolder metadata caching: WARNING", 'warning');
        }
    }

    /**
     * Evalueer performance
     */
    private function evaluatePerformance(
        float $value,
        float $targetThreshold,
        float $criticalThreshold,
        string $metricName
    ): void {
        if ($value <= $targetThreshold) {
            $status = 'PASS';
        } elseif ($value <= $criticalThreshold) {
            $status = 'WARNING';
        } else {
            $status = 'FAIL';
        }

        $this->log("$metricName: $status", strtolower($status) === 'fail' ? 'error' : 'info');
    }

    /**
     * Print results
     */
    private function printResults(): void {
        $this->log("\n=== Groupfolder FieldService Performance Test Results ===");

        $summary = $this->calculateSummary();

        $this->log(sprintf("Total tests: %d", $summary['total_tests']));
        $this->log(sprintf("Successful: %d", $summary['successful_tests']));
        $this->log(sprintf("Failed: %d", $summary['failed_tests']));
        $this->log(sprintf("Total duration: %.2f minutes", $summary['total_duration_minutes']));
    }
}
