<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Performance;

/**
 * Performance tests voor Groupfolder Search
 *
 * Test scenarios:
 * 1. Full-text search in groupfolder file metadata
 * 2. Field-specific search (field_name = value)
 * 3. Search with LIKE patterns
 * 4. Search result pagination
 * 5. Search cache performance
 *
 * Note: Tests search directly on groupfolder metadata tables
 * since SearchIndexService doesn't have groupfolder-specific methods yet
 */
class GroupfolderSearchServiceTest extends PerformanceTestBase {

    private int $testGroupfolderId = 999999;
    private string $userId = 'admin';

    public function run(): void {
        $this->log("=== Groupfolder Search Performance Tests ===");

        // Get actual user
        $user = $this->userSession->getUser();
        $this->userId = $user ? $user->getUID() : 'admin';

        // Run tests
        $this->testFullTextSearch();
        $this->testFieldSpecificSearch();
        $this->testLikePatternSearch();
        $this->testSearchWithLimit();
        $this->testSearchCaching();

        $this->saveMetrics('gf_search_service_' . date('Y-m-d_His') . '.json');
        $this->printResults();
    }

    /**
     * Test full-text search in groupfolder file metadata
     */
    private function testFullTextSearch(): void {
        $this->log("\n--- Testing full-text search ---");

        $searchTerms = [
            'Test',       // Common term - many results
            'value',      // Medium results
            'specific',   // Few results
            'nonexistent' // No results
        ];

        foreach ($searchTerms as $term) {
            $stats = $this->benchmark(
                fn() => $this->searchInGroupfolder($term),
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

            $this->evaluatePerformance($stats['duration_median_ms'], 100, 500, "Full-text search: $term");
        }
    }

    /**
     * Test field-specific search
     */
    private function testFieldSpecificSearch(): void {
        $this->log("\n--- Testing field-specific search ---");

        // Search in specific field
        $fieldSearches = [
            ['field' => 'gf_perf_title', 'value' => 'Test'],
            ['field' => 'gf_perf_status', 'value' => 'draft'],
            ['field' => 'gf_perf_category', 'value' => 'technical']
        ];

        foreach ($fieldSearches as $search) {
            $stats = $this->benchmark(
                fn() => $this->searchByField($search['field'], $search['value']),
                "field_search_" . $search['field'],
                $this->config['test_iterations'],
                $search
            );

            $this->log(sprintf(
                "Field search '%s=%s': avg=%.2fms, median=%.2fms",
                $search['field'],
                $search['value'],
                $stats['duration_avg_ms'],
                $stats['duration_median_ms']
            ));
        }
    }

    /**
     * Test LIKE pattern search
     */
    private function testLikePatternSearch(): void {
        $this->log("\n--- Testing LIKE pattern search ---");

        $patterns = [
            ['type' => 'starts_with', 'pattern' => 'Test%'],
            ['type' => 'ends_with', 'pattern' => '%value'],
            ['type' => 'contains', 'pattern' => '%Test%']
        ];

        foreach ($patterns as $patternInfo) {
            $stats = $this->benchmark(
                fn() => $this->searchWithLike($patternInfo['pattern']),
                "like_search_" . $patternInfo['type'],
                $this->config['test_iterations'],
                $patternInfo
            );

            $this->log(sprintf(
                "LIKE '%s' (%s): avg=%.2fms, median=%.2fms",
                $patternInfo['pattern'],
                $patternInfo['type'],
                $stats['duration_avg_ms'],
                $stats['duration_median_ms']
            ));
        }
    }

    /**
     * Test search with pagination
     */
    private function testSearchWithLimit(): void {
        $this->log("\n--- Testing search with limit/pagination ---");

        $limits = [10, 50, 100, 500];

        foreach ($limits as $limit) {
            $stats = $this->benchmark(
                fn() => $this->searchInGroupfolder('Test', $limit),
                "search_limit_$limit",
                $this->config['test_iterations'],
                ['limit' => $limit]
            );

            $this->log(sprintf(
                "Search with LIMIT %d: avg=%.2fms, median=%.2fms",
                $limit,
                $stats['duration_avg_ms'],
                $stats['duration_median_ms']
            ));
        }
    }

    /**
     * Test search caching
     */
    private function testSearchCaching(): void {
        $this->log("\n--- Testing search caching ---");

        $searchTerm = 'cache_test_term';

        // Cold cache
        $coldResult = $this->measure(
            fn() => $this->searchInGroupfolder($searchTerm),
            'search_cold_cache',
            ['term' => $searchTerm]
        );

        // Warm cache - repeated searches
        $startTime = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            $this->searchInGroupfolder($searchTerm);
        }
        $warmDuration = (microtime(true) - $startTime) * 1000;
        $warmAvg = $warmDuration / 100;

        $this->metrics[] = [
            'name' => 'search_warm_cache',
            'avg_duration_ms' => $warmAvg,
            'total_duration_ms' => $warmDuration,
            'iterations' => 100,
            'description' => 'Repeated searches (cache hits)',
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        $coldMs = $coldResult ? ($coldResult['duration_ms'] ?? 0) : 0;
        $this->log(sprintf(
            "Cache performance: cold=%.2fms, warm avg=%.2fms (100 calls)",
            $coldMs,
            $warmAvg
        ));

        if ($warmAvg < $coldMs * 0.5) {
            $this->log("Search caching: EXCELLENT (cache working)", 'info');
        } elseif ($warmAvg < $coldMs) {
            $this->log("Search caching: GOOD", 'info');
        } else {
            $this->log("Search caching: WARNING (cache might not be effective)", 'warning');
        }
    }

    /**
     * Search in groupfolder file metadata
     */
    private function searchInGroupfolder(string $term, int $limit = 1000): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('file_id', 'field_name', 'field_value')
            ->from('metavox_file_gf_meta')
            ->where($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($this->testGroupfolderId)))
            ->andWhere($qb->expr()->like('field_value', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($term) . '%')))
            ->setMaxResults($limit);

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        return $rows;
    }

    /**
     * Search by specific field
     */
    private function searchByField(string $fieldName, string $value): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('file_id', 'field_value')
            ->from('metavox_file_gf_meta')
            ->where($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($this->testGroupfolderId)))
            ->andWhere($qb->expr()->eq('field_name', $qb->createNamedParameter($fieldName)))
            ->andWhere($qb->expr()->like('field_value', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($value) . '%')))
            ->setMaxResults(1000);

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        return $rows;
    }

    /**
     * Search with LIKE pattern
     */
    private function searchWithLike(string $pattern): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('file_id', 'field_name', 'field_value')
            ->from('metavox_file_gf_meta')
            ->where($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($this->testGroupfolderId)))
            ->andWhere($qb->expr()->like('field_value', $qb->createNamedParameter($pattern)))
            ->setMaxResults(1000);

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        return $rows;
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
        if ($value <= $targetThreshold) {
            $status = 'PASS';
            $level = 'info';
        } elseif ($value <= $criticalThreshold) {
            $status = 'WARNING';
            $level = 'warning';
        } else {
            $status = 'FAIL';
            $level = 'error';
        }

        $this->log("$metricName: $status", $level);
    }

    /**
     * Print results
     */
    private function printResults(): void {
        $this->log("\n=== Groupfolder Search Performance Test Results ===");

        $summary = $this->calculateSummary();

        $this->log(sprintf("Total tests: %d", $summary['total_tests']));
        $this->log(sprintf("Successful: %d", $summary['successful_tests']));
        $this->log(sprintf("Failed: %d", $summary['failed_tests']));
        $this->log(sprintf("Total duration: %.2f minutes", $summary['total_duration_minutes']));
    }
}
