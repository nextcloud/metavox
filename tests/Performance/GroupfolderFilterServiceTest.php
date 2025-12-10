<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Performance;

/**
 * Performance tests voor Groupfolder FilterService
 *
 * Test scenarios:
 * 1. Simple filters (single field, different operators)
 * 2. Complex filters (multiple fields combined)
 * 3. Filter with groupfolder metadata
 * 4. Filter with file metadata within groupfolders
 * 5. Performance scaling (100K, 1M, 10M records)
 *
 * Uses FilterService->filterFilesByMetadata() with groupfolder context
 */
class GroupfolderFilterServiceTest extends PerformanceTestBase {

    private int $testGroupfolderId = 999999;
    private array $testFields = [];
    private string $userId = 'admin';

    public function run(): void {
        $this->log("=== Groupfolder FilterService Performance Tests ===");

        // Get actual user
        $user = $this->userSession->getUser();
        $this->userId = $user ? $user->getUID() : 'admin';

        // Load test data
        $this->loadTestData();

        // Run tests
        $this->testSimpleFilters();
        $this->testComplexFilters();
        $this->testMultipleConditions();
        $this->testFilterPerformanceByResultSize();

        $this->saveMetrics('gf_filter_service_' . date('Y-m-d_His') . '.json');
        $this->printResults();
    }

    /**
     * Load test field metadata
     */
    private function loadTestData(): void {
        $this->log("Loading test fields...");

        // Get file fields (applies_to_groupfolder = 0)
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('metavox_gf_fields')
            ->where($qb->expr()->eq('applies_to_groupfolder', $qb->createNamedParameter(0)));

        $result = $qb->executeQuery();
        while ($row = $result->fetch()) {
            $this->testFields[] = $row;
        }
        $result->closeCursor();

        $this->log("Loaded " . count($this->testFields) . " file fields");
    }

    /**
     * Test simple filters with different operators
     */
    private function testSimpleFilters(): void {
        $this->log("\n--- Testing simple filters (single field) ---");

        if (empty($this->testFields)) {
            $this->log("No test fields available", 'warning');
            return;
        }

        // Find text field
        $textField = null;
        foreach ($this->testFields as $field) {
            if ($field['field_type'] === 'text') {
                $textField = $field;
                break;
            }
        }

        if (!$textField) {
            $this->log("No text field found", 'warning');
            return;
        }

        $operators = ['equals', 'contains', 'starts_with', 'ends_with'];

        foreach ($operators as $operator) {
            $filters = [
                [
                    'field' => $textField['field_name'],
                    'operator' => $operator,
                    'value' => 'Test'
                ]
            ];

            $stats = $this->benchmark(
                fn() => $this->filterService->filterFilesByMetadata(
                    $this->testGroupfolderId,
                    $filters,
                    $this->userId
                ),
                "simple_filter_$operator",
                $this->config['test_iterations'],
                ['operator' => $operator, 'field' => $textField['field_name']]
            );

            $this->log(sprintf(
                "Filter '%s': avg=%.2fms, median=%.2fms, p95=%.2fms",
                $operator,
                $stats['duration_avg_ms'],
                $stats['duration_median_ms'],
                $stats['duration_p95_ms']
            ));

            $this->evaluatePerformance($stats['duration_median_ms'], 200, 1000, "Simple filter ($operator)");
        }
    }

    /**
     * Test complex filters (multiple fields, different types)
     */
    private function testComplexFilters(): void {
        $this->log("\n--- Testing complex filters (multiple fields) ---");

        if (count($this->testFields) < 3) {
            $this->log("Not enough fields for complex filter test", 'warning');
            return;
        }

        // Build complex filter with different field types
        $filters = [];
        $fieldCount = 0;

        foreach ($this->testFields as $field) {
            if ($fieldCount >= 3) break;

            switch ($field['field_type']) {
                case 'text':
                    $filters[] = [
                        'field' => $field['field_name'],
                        'operator' => 'contains',
                        'value' => 'Test'
                    ];
                    $fieldCount++;
                    break;

                case 'select':
                    $options = json_decode($field['field_options'], true);
                    if (!empty($options)) {
                        $filters[] = [
                            'field' => $field['field_name'],
                            'operator' => 'equals',
                            'value' => $options[0]
                        ];
                        $fieldCount++;
                    }
                    break;

                case 'number':
                    $filters[] = [
                        'field' => $field['field_name'],
                        'operator' => 'greater_than',
                        'value' => '50'
                    ];
                    $fieldCount++;
                    break;
            }
        }

        if (empty($filters)) {
            $this->log("Could not build complex filter", 'warning');
            return;
        }

        $stats = $this->benchmark(
            fn() => $this->filterService->filterFilesByMetadata(
                $this->testGroupfolderId,
                $filters,
                $this->userId
            ),
            'complex_filter',
            $this->config['test_iterations'],
            ['filter_count' => count($filters)]
        );

        $this->log(sprintf(
            "Complex filter (%d conditions): avg=%.2fms, median=%.2fms, p95=%.2fms",
            count($filters),
            $stats['duration_avg_ms'],
            $stats['duration_median_ms'],
            $stats['duration_p95_ms']
        ));

        $this->evaluatePerformance($stats['duration_median_ms'], 500, 2000, 'Complex filter');
    }

    /**
     * Test filter with many conditions (stress test)
     */
    private function testMultipleConditions(): void {
        $this->log("\n--- Testing filter with many conditions ---");

        $conditionCounts = [5, 10];

        foreach ($conditionCounts as $count) {
            $filters = [];

            // Use available fields cyclically
            for ($i = 0; $i < $count; $i++) {
                $field = $this->testFields[$i % count($this->testFields)];

                switch ($field['field_type']) {
                    case 'text':
                        $filters[] = [
                            'field' => $field['field_name'],
                            'operator' => 'contains',
                            'value' => 'Test'
                        ];
                        break;

                    case 'select':
                        $options = json_decode($field['field_options'], true);
                        $filters[] = [
                            'field' => $field['field_name'],
                            'operator' => 'equals',
                            'value' => !empty($options) ? $options[0] : 'default'
                        ];
                        break;

                    case 'number':
                        $filters[] = [
                            'field' => $field['field_name'],
                            'operator' => 'greater_than',
                            'value' => '25'
                        ];
                        break;

                    default:
                        $filters[] = [
                            'field' => $field['field_name'],
                            'operator' => 'equals',
                            'value' => 'test'
                        ];
                }
            }

            $stats = $this->benchmark(
                fn() => $this->filterService->filterFilesByMetadata(
                    $this->testGroupfolderId,
                    $filters,
                    $this->userId
                ),
                "filter_{$count}_conditions",
                min(10, $this->config['test_iterations']),
                ['condition_count' => $count]
            );

            $this->log(sprintf(
                "Filter with %d conditions: avg=%.2fms, median=%.2fms, p95=%.2fms",
                $count,
                $stats['duration_avg_ms'],
                $stats['duration_median_ms'],
                $stats['duration_p95_ms']
            ));

            $threshold = $count <= 5 ? 1000 : 2000;
            $this->evaluatePerformance($stats['duration_median_ms'], $threshold, $threshold * 2, "Filter $count conditions");
        }
    }

    /**
     * Test filter performance by expected result size
     */
    private function testFilterPerformanceByResultSize(): void {
        $this->log("\n--- Testing filter by result size ---");

        if (empty($this->testFields)) {
            $this->log("No test fields available", 'warning');
            return;
        }

        // Find select field
        $selectField = null;
        foreach ($this->testFields as $field) {
            if ($field['field_type'] === 'select') {
                $selectField = $field;
                break;
            }
        }

        if (!$selectField) {
            $this->log("No select field found", 'warning');
            return;
        }

        $options = json_decode($selectField['field_options'], true);
        if (empty($options)) {
            $this->log("Select field has no options", 'warning');
            return;
        }

        // Test with first option (should have many results)
        $filters = [
            [
                'field' => $selectField['field_name'],
                'operator' => 'equals',
                'value' => $options[0]
            ]
        ];

        $stats = $this->benchmark(
            fn() => $this->filterService->filterFilesByMetadata(
                $this->testGroupfolderId,
                $filters,
                $this->userId
            ),
            'filter_many_results',
            $this->config['test_iterations'],
            ['expected' => 'many results']
        );

        $this->log(sprintf(
            "Filter (many results): avg=%.2fms, median=%.2fms",
            $stats['duration_avg_ms'],
            $stats['duration_median_ms']
        ));
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

        $this->log("$metricName: $status", $level);
    }

    /**
     * Print test results
     */
    private function printResults(): void {
        $this->log("\n=== Groupfolder FilterService Performance Test Results ===");

        $summary = $this->calculateSummary();

        $this->log(sprintf("Total tests: %d", $summary['total_tests']));
        $this->log(sprintf("Successful: %d", $summary['successful_tests']));
        $this->log(sprintf("Failed: %d", $summary['failed_tests']));
        $this->log(sprintf("Total duration: %.2f minutes", $summary['total_duration_minutes']));
    }
}
