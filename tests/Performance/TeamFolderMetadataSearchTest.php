<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Performance;

/**
 * Team Folder Metadata Search Performance Test
 *
 * Tests SEARCH performance for:
 * 1. Search through team folder metadata
 * 2. Filter files by metadata criteria
 * 3. Full-text search in metadata values
 *
 * Automatically creates test data and cleans up afterwards.
 * Configurable via fileCount parameter from License Server.
 *
 * Extends TeamFolderMetadataReadTest to reuse setup/cleanup code.
 */
class TeamFolderMetadataSearchTest extends TeamFolderMetadataReadTest {

    private array $searchableFields = [];

    public function run(): void {
        $this->log("=== Team Folder Metadata Search Performance Test ===");
        $this->log("File count: " . $this->fileCount);

        try {
            // Initialize test data (creates files and fields if needed)
            $this->initializeTestData();

            if (empty($this->testFileIds)) {
                $this->log("ERROR: Could not initialize test data", 'error');
                return;
            }

            // Load searchable fields for search tests
            $this->loadSearchableFields();

            // Run search performance tests
            $this->testSimpleFilter();
            $this->testComplexFilter();
            $this->testFieldValueSearch();
            $this->testMultiFieldSearch();
            $this->testSearchIndexPerformance();

            // Save results
            $this->saveMetrics('team_folder_search_' . $this->fileCount . 'files_' . date('Y-m-d_His') . '.json');
            $this->printSearchResults();

        } finally {
            // Always clean up test data
            $this->cleanupTestData();
        }
    }

    /**
     * Load searchable fields after initialization
     */
    private function loadSearchableFields(): void {
        // Get searchable fields (file metadata fields)
        $availableFields = $this->filterService->getAvailableFilterFields($this->testGroupfolderId);
        $this->searchableFields = $availableFields;

        $this->log("Searchable fields: " . count($this->searchableFields));
    }

    /**
     * Test simple single-field filter
     */
    private function testSimpleFilter(): void {
        $this->log("\n--- Testing Simple Single-Field Filter ---");

        if (empty($this->searchableFields)) {
            $this->log("No searchable fields available", 'warning');
            return;
        }

        // Get a field and a real value to search for
        $field = $this->searchableFields[0];
        $testValue = $this->getSampleValueForField($field['field_name']);

        if (!$testValue) {
            $this->log("No sample value found for field: " . $field['field_name'], 'warning');
            return;
        }

        $filters = [
            [
                'field_name' => $field['field_name'],
                'operator' => 'equals',
                'value' => $testValue,
                'field_type' => $field['field_type']
            ]
        ];

        $user = $this->userSession->getUser();
        $userId = $user ? $user->getUID() : 'unknown';

        $stats = $this->benchmark(
            fn() => $this->filterService->filterFilesByMetadata(
                $this->testGroupfolderId,
                $filters,
                $userId
            ),
            'simple_filter',
            $this->config['test_iterations'],
            ['field' => $field['field_name'], 'operator' => 'equals']
        );

        $this->log(sprintf(
            "Simple filter: avg=%.2fms, median=%.2fms, p95=%.2fms, p99=%.2fms",
            $stats['duration_avg_ms'],
            $stats['duration_median_ms'],
            $stats['duration_p95_ms'],
            $stats['duration_p99_ms']
        ));

        // Target: <100ms for simple filter
        $this->evaluatePerformance($stats['duration_median_ms'], 100, 500, 'Simple Filter');
    }

    /**
     * Test complex multi-condition filter
     */
    private function testComplexFilter(): void {
        $this->log("\n--- Testing Complex Multi-Condition Filter ---");

        if (count($this->searchableFields) < 2) {
            $this->log("Not enough searchable fields for complex filter", 'warning');
            return;
        }

        // Build complex filter with 2-3 conditions
        $filters = [];
        $fieldsToUse = array_slice($this->searchableFields, 0, min(3, count($this->searchableFields)));

        foreach ($fieldsToUse as $field) {
            $testValue = $this->getSampleValueForField($field['field_name']);
            if ($testValue) {
                $filters[] = [
                    'field_name' => $field['field_name'],
                    'operator' => 'contains',
                    'value' => substr($testValue, 0, 5),  // Partial match for wider results
                    'field_type' => $field['field_type']
                ];
            }
        }

        if (empty($filters)) {
            $this->log("Could not build complex filter - no sample values found", 'warning');
            return;
        }

        $user = $this->userSession->getUser();
        $userId = $user ? $user->getUID() : 'unknown';

        $stats = $this->benchmark(
            fn() => $this->filterService->filterFilesByMetadata(
                $this->testGroupfolderId,
                $filters,
                $userId
            ),
            'complex_filter',
            min(5, $this->config['test_iterations']),
            ['filter_count' => count($filters)]
        );

        $this->log(sprintf(
            "Complex filter (%d conditions): avg=%.2fms, median=%.2fms, p95=%.2fms",
            count($filters),
            $stats['duration_avg_ms'],
            $stats['duration_median_ms'],
            $stats['duration_p95_ms']
        ));

        // Target: <300ms for complex filter
        $this->evaluatePerformance($stats['duration_median_ms'], 300, 1000, 'Complex Filter');
    }

    /**
     * Test search by specific field value
     */
    private function testFieldValueSearch(): void {
        $this->log("\n--- Testing Field Value Search ---");

        if (empty($this->searchableFields)) {
            $this->log("No searchable fields available", 'warning');
            return;
        }

        $field = $this->searchableFields[0];
        $testValue = $this->getSampleValueForField($field['field_name']);

        if (!$testValue) {
            $this->log("No sample value found", 'warning');
            return;
        }

        $user = $this->userSession->getUser();
        $userId = $user ? $user->getUID() : 'unknown';

        $stats = $this->benchmark(
            fn() => $this->searchService->searchByFieldValue($field['field_name'], $testValue, $userId),
            'field_value_search',
            $this->config['test_iterations'],
            ['field' => $field['field_name']]
        );

        $this->log(sprintf(
            "Field value search: avg=%.2fms, median=%.2fms, p95=%.2fms",
            $stats['duration_avg_ms'],
            $stats['duration_median_ms'],
            $stats['duration_p95_ms']
        ));

        // Target: <50ms for field value search
        $this->evaluatePerformance($stats['duration_median_ms'], 50, 200, 'Field Value Search');
    }

    /**
     * Test multi-field search
     */
    private function testMultiFieldSearch(): void {
        $this->log("\n--- Testing Multi-Field Search ---");

        // Get a common search term from existing metadata
        $searchTerm = $this->getCommonSearchTerm();

        if (!$searchTerm) {
            $this->log("No common search term found", 'warning');
            return;
        }

        $user = $this->userSession->getUser();
        $userId = $user ? $user->getUID() : 'unknown';

        $stats = $this->benchmark(
            fn() => $this->searchService->searchFilesByMetadata($searchTerm, $userId),
            'multi_field_search',
            $this->config['test_iterations'],
            ['search_term' => $searchTerm]
        );

        $this->log(sprintf(
            "Multi-field search: avg=%.2fms, median=%.2fms, p95=%.2fms",
            $stats['duration_avg_ms'],
            $stats['duration_median_ms'],
            $stats['duration_p95_ms']
        ));

        // Target: <100ms for multi-field search
        $this->evaluatePerformance($stats['duration_median_ms'], 100, 400, 'Multi-Field Search');
    }

    /**
     * Test search index performance
     */
    private function testSearchIndexPerformance(): void {
        $this->log("\n--- Testing Search Index Performance ---");

        // Test different search term lengths
        $searchTerms = ['a', 'te', 'test', 'test_val'];

        $user = $this->userSession->getUser();
        $userId = $user ? $user->getUID() : 'unknown';

        foreach ($searchTerms as $term) {
            $stats = $this->benchmark(
                fn() => $this->searchService->searchFilesByMetadata($term, $userId),
                "search_term_length_" . strlen($term),
                min(5, $this->config['test_iterations']),
                ['term_length' => strlen($term), 'term' => $term]
            );

            $this->log(sprintf(
                "Search with '%s' (len=%d): median=%.2fms",
                $term,
                strlen($term),
                $stats['duration_median_ms']
            ));
        }

        $this->log("Search index test completed");
    }

    /**
     * Get a sample value for a field from existing metadata
     */
    private function getSampleValueForField(string $fieldName): ?string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('field_value')
            ->from('metavox_file_gf_meta')
            ->where($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($this->testGroupfolderId)))
            ->andWhere($qb->expr()->eq('field_name', $qb->createNamedParameter($fieldName)))
            ->andWhere($qb->expr()->isNotNull('field_value'))
            ->andWhere($qb->expr()->neq('field_value', $qb->createNamedParameter('')))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $value = $result->fetchOne();
        $result->closeCursor();

        return $value ?: null;
    }

    /**
     * Get a common search term from existing metadata
     */
    private function getCommonSearchTerm(): ?string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('field_value')
            ->from('metavox_file_gf_meta')
            ->where($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($this->testGroupfolderId)))
            ->andWhere($qb->expr()->isNotNull('field_value'))
            ->andWhere($qb->expr()->neq('field_value', $qb->createNamedParameter('')))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $value = $result->fetchOne();
        $result->closeCursor();

        if ($value && strlen($value) > 3) {
            return substr($value, 0, 4);  // Return first 4 chars for partial match
        }

        return null;
    }

    /**
     * Evaluate performance against thresholds
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

        $this->log("$metricName: $status (threshold: <{$targetThreshold}ms, critical: <{$criticalThreshold}ms)", $level);
    }

    /**
     * Print test results
     */
    private function printSearchResults(): void {
        $this->log("\n=== Team Folder Metadata Search Performance Test Results ===");

        $summary = $this->calculateSummary();

        $this->log(sprintf("File count tested: %d", $this->fileCount));
        $this->log(sprintf("Total tests: %d", $summary['total_tests']));
        $this->log(sprintf("Successful: %d", $summary['successful_tests']));
        $this->log(sprintf("Failed: %d", $summary['failed_tests']));
        $this->log(sprintf("Total duration: %.2f seconds", $summary['total_duration_ms'] / 1000));

        $this->log("\nTest completed successfully!");
    }
}
