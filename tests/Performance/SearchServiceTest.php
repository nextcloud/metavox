<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Performance;

/**
 * Performance tests voor SearchIndexService
 *
 * Test scenarios:
 * 1. Full-text search met FULLTEXT index (MySQL)
 * 2. LIKE fallback search (PostgreSQL/SQLite)
 * 3. Field-specific search (field_name:value syntax)
 * 4. Search index rebuild performance
 * 5. Cache hit rates
 * 6. Verschillende zoektermen (kort, lang, veel resultaten, weinig resultaten)
 */
class SearchServiceTest extends PerformanceTestBase {

    private string $userId = 'admin';

    public function run(): void {
        // Get actual userId from session
        $user = $this->userSession->getUser();
        $this->userId = $user ? $user->getUID() : 'admin';

        $this->log("=== SearchIndexService Performance Tests ===");

        $this->testFullTextSearch();
        $this->testFieldSpecificSearch();
        $this->testSearchCaching();
        $this->testSearchIndexUpdate();
        $this->testDifferentQueryPatterns();

        $this->saveMetrics('search_service_' . date('Y-m-d_His') . '.json');
        $this->printResults();
    }

    /**
     * Test full-text search performance
     */
    private function testFullTextSearch(): void {
        $this->log("\n--- Testing full-text search ---");

        $searchTerms = [
            'test',         // Veel matches verwacht
            'document',     // Matig aantal matches
            'specific',     // Weinig matches
            'nonexistent',  // Geen matches
        ];

        foreach ($searchTerms as $term) {
            $stats = $this->benchmark(
                fn() => $this->searchService->searchFilesByMetadata($term, $this->userId),
                "fulltext_search_$term",
                $this->config['test_iterations'],
                ['search_term' => $term]
            );

            $this->log(sprintf(
                "Search '%s': avg=%.2fms, median=%.2fms, p95=%.2fms",
                $term,
                $stats['duration_avg_ms'],
                $stats['duration_median_ms'],
                $stats['duration_p95_ms']
            ));
        }

        // Target: <100ms voor search
        $testStats = array_filter(
            $this->metrics,
            fn($m) => $m['name'] === 'fulltext_search_test'
        );
        if (!empty($testStats)) {
            $stat = reset($testStats);
            $this->evaluatePerformance($stat['duration_median_ms'], 100, 500, 'Full-text search');
        }
    }

    /**
     * Test field-specific search (field_name:value)
     */
    private function testFieldSpecificSearch(): void {
        $this->log("\n--- Testing field-specific search ---");

        $fieldSearches = [
            'perf_test_title:document',
            'perf_test_status:draft',
            'perf_test_category:technical',
        ];

        foreach ($fieldSearches as $query) {
            $stats = $this->benchmark(
                fn() => $this->searchService->searchFilesByMetadata($query, $this->userId),
                "field_specific_search_" . md5($query),
                $this->config['test_iterations'],
                ['query' => $query]
            );

            $this->log(sprintf(
                "Search '%s': avg=%.2fms, median=%.2fms, p95=%.2fms",
                $query,
                $stats['duration_avg_ms'],
                $stats['duration_median_ms'],
                $stats['duration_p95_ms']
            ));
        }
    }

    /**
     * Test search caching (5-minute distributed cache)
     */
    private function testSearchCaching(): void {
        $this->log("\n--- Testing search caching ---");

        $searchTerm = 'test_cache_performance';

        // Cold cache: eerste search
        $coldResult = $this->measure(
            fn() => $this->searchService->searchFilesByMetadata($searchTerm, $this->userId),
            'search_cold_cache',
            ['term' => $searchTerm]
        );

        // Warm cache: direct daarna dezelfde search
        $startTime = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            $this->searchService->searchFilesByMetadata($searchTerm, $this->userId);
        }
        $warmDuration = (microtime(true) - $startTime) * 1000;
        $warmAvg = $warmDuration / 100;

        $this->metrics[] = [
            'name' => 'search_warm_cache',
            'avg_duration_ms' => $warmAvg,
            'total_duration_ms' => $warmDuration,
            'iterations' => 100,
            'description' => 'Repeated searches (should hit cache)',
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        $this->log(sprintf(
            "Cache performance: cold=%.2fms, warm avg=%.2fms (100 calls)",
            $coldResult ? ($coldResult['duration_ms'] ?? 0) : 0,
            $warmAvg
        ));

        // Cache hits zouden <1ms moeten zijn
        if ($warmAvg < 1) {
            $this->log("Search caching: EXCELLENT (cache hits working)", 'info');
        } elseif ($warmAvg < 10) {
            $this->log("Search caching: GOOD", 'info');
        } else {
            $this->log("Search caching: WARNING (cache might not be working)", 'warning');
        }
    }

    /**
     * Test search index update performance
     */
    private function testSearchIndexUpdate(): void {
        $this->log("\n--- Testing search index update ---");

        // Voor deze test hebben we een file ID nodig
        $user = $this->userSession->getUser();
        if (!$user) {
            $this->log("No user logged in", 'warning');
            return;
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($user->getUID());
            $testFolder = $userFolder->get('metavox_performance_test');
            $files = $testFolder->getDirectoryListing();

            if (empty($files)) {
                $this->log("No test files found", 'warning');
                return;
            }

            $testFile = $files[0];
            $fileId = $testFile->getId();

            // Test index update performance
            $stats = $this->benchmark(
                fn() => $this->searchService->updateFileIndex($fileId),
                'search_index_update',
                min(10, $this->config['test_iterations']),
                ['file_id' => $fileId]
            );

            $this->log(sprintf(
                "Index update: avg=%.2fms, median=%.2fms, p95=%.2fms",
                $stats['duration_avg_ms'],
                $stats['duration_median_ms'],
                $stats['duration_p95_ms']
            ));

            // Target: <50ms voor index update
            $this->evaluatePerformance($stats['duration_median_ms'], 50, 200, 'Search index update');

        } catch (\Exception $e) {
            $this->log("Could not test index update: " . $e->getMessage(), 'warning');
        }
    }

    /**
     * Test verschillende zoekpatronen
     */
    private function testDifferentQueryPatterns(): void {
        $this->log("\n--- Testing different query patterns ---");

        $patterns = [
            'short' => 'te',              // Kort (2 karakters - minimum)
            'medium' => 'test document',  // Medium met spatie
            'long' => 'this is a very long search query with many words',
            'special' => 'test-value_123', // Met speciale karakters
            'numeric' => '12345',          // Numeriek
            'wildcard' => 'test*',         // Met wildcard (als ondersteund)
        ];

        foreach ($patterns as $patternName => $query) {
            // Skip als query te kort is (minimum 3 karakters in SearchProvider)
            if (strlen($query) < 3 && $patternName !== 'short') {
                continue;
            }

            $stats = $this->benchmark(
                fn() => $this->searchService->searchFilesByMetadata($query, $this->userId),
                "search_pattern_$patternName",
                $this->config['test_iterations'],
                ['pattern' => $patternName, 'query' => $query]
            );

            $this->log(sprintf(
                "Pattern '%s': avg=%.2fms, median=%.2fms",
                $patternName,
                $stats['duration_avg_ms'],
                $stats['duration_median_ms']
            ));
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
     * Print resultaten
     */
    private function printResults(): void {
        $this->log("\n=== SearchIndexService Performance Test Results ===");

        $summary = $this->calculateSummary();

        $this->log(sprintf("Total tests: %d", $summary['total_tests']));
        $this->log(sprintf("Successful: %d", $summary['successful_tests']));
        $this->log(sprintf("Failed: %d", $summary['failed_tests']));
        $this->log(sprintf("Total duration: %.2f minutes", $summary['total_duration_minutes']));
    }
}
