<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Performance;

/**
 * Performance tests voor FilterService
 *
 * Test scenarios:
 * 1. Simple filters (1 conditie) met verschillende operators
 * 2. Complex filters (5+ condities, meerdere operators)
 * 3. Filters op verschillende field types
 * 4. Performance met/zonder indexes
 * 5. Resultaat set sizes (10, 100, 1000, 10000+ matches)
 */
class FilterServiceTest extends PerformanceTestBase {

    private array $testFields = [];
    private int $testGroupfolderId = 0;
    private string $userId = 'admin';

    public function run(): void {
        $this->log("=== FilterService Performance Tests ===");

        $this->loadTestData();
        $this->testSimpleFilters();
        $this->testComplexFilters();
        $this->testDifferentFieldTypes();
        $this->testLargeResultSets();

        $this->saveMetrics('filter_service_' . date('Y-m-d_His') . '.json');
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

        $this->log("Loaded " . count($this->testFields) . " test fields");

        // Voor deze test hebben we een groupfolder ID nodig
        // In een echte test zou dit via de GroupfolderService komen
        // Voor nu gebruiken we een dummy ID
        $this->testGroupfolderId = 1;

        // We hebben ook een userId nodig voor filter calls
        $user = $this->userSession->getUser();
        $this->userId = $user ? $user->getUID() : 'admin';
    }

    /**
     * Test simple filters (1 conditie)
     */
    private function testSimpleFilters(): void {
        $this->log("\n--- Testing simple filters ---");

        $operators = [
            'equals',
            'not_equals',
            'contains',
            'not_contains',
            'starts_with',
            'ends_with',
            'is_empty',
            'is_not_empty',
        ];

        // Test met text field
        $textField = $this->findFieldByType('text');
        if (!$textField) {
            $this->log("No text field found for testing", 'warning');
            return;
        }

        foreach ($operators as $operator) {
            $filters = [
                [
                    'field' => $textField['field_name'],
                    'operator' => $operator,
                    'value' => $operator === 'is_empty' || $operator === 'is_not_empty' ? '' : 'test',
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
        }

        // Target: <200ms voor simple filter
        $equalsStats = array_filter(
            $this->metrics,
            fn($m) => $m['name'] === 'simple_filter_equals'
        );
        if (!empty($equalsStats)) {
            $stat = reset($equalsStats);
            $this->evaluatePerformance($stat['duration_median_ms'], 200, 1000, 'Simple filter');
        }
    }

    /**
     * Test complexe filters (meerdere condities)
     */
    private function testComplexFilters(): void {
        $this->log("\n--- Testing complex filters ---");

        $textField = $this->findFieldByType('text');
        $numberField = $this->findFieldByType('number');
        $dateField = $this->findFieldByType('date');
        $selectField = $this->findFieldByType('select');

        if (!$textField || !$numberField || !$dateField || !$selectField) {
            $this->log("Not all required field types available", 'warning');
            return;
        }

        // Test met 2, 3, 5, 10 condities
        $complexityCounts = [2, 3, 5, 10];

        foreach ($complexityCounts as $count) {
            $filters = [];

            // Voeg verschillende condities toe
            if ($count >= 1 && $textField) {
                $filters[] = [
                    'field' => $textField['field_name'],
                    'operator' => 'contains',
                    'value' => 'test',
                ];
            }

            if ($count >= 2 && $selectField) {
                $filters[] = [
                    'field' => $selectField['field_name'],
                    'operator' => 'equals',
                    'value' => 'draft',
                ];
            }

            if ($count >= 3 && $numberField) {
                $filters[] = [
                    'field' => $numberField['field_name'],
                    'operator' => 'greater_than',
                    'value' => '50',
                ];
            }

            if ($count >= 4 && $dateField) {
                $filters[] = [
                    'field' => $dateField['field_name'],
                    'operator' => 'less_than',
                    'value' => '2025-01-01',
                ];
            }

            if ($count >= 5 && $textField) {
                $filters[] = [
                    'field' => $textField['field_name'],
                    'operator' => 'not_contains',
                    'value' => 'archive',
                ];
            }

            // Vul aan tot gewenst aantal met extra condities
            while (count($filters) < $count) {
                $filters[] = [
                    'field' => $textField['field_name'],
                    'operator' => 'is_not_empty',
                    'value' => '',
                ];
            }

            $stats = $this->benchmark(
                fn() => $this->filterService->filterFilesByMetadata(
                    $this->testGroupfolderId,
                    $filters,
                    $this->userId
                ),
                "complex_filter_{$count}_conditions",
                min(10, $this->config['test_iterations']), // Minder iteraties voor complexe filters
                ['conditions' => $count]
            );

            $this->log(sprintf(
                "Filter %d conditions: avg=%.2fms, median=%.2fms, p95=%.2fms",
                $count,
                $stats['duration_avg_ms'],
                $stats['duration_median_ms'],
                $stats['duration_p95_ms']
            ));
        }

        // Target: <500ms voor 5 condities
        $complex5Stats = array_filter(
            $this->metrics,
            fn($m) => $m['name'] === 'complex_filter_5_conditions'
        );
        if (!empty($complex5Stats)) {
            $stat = reset($complex5Stats);
            $this->evaluatePerformance($stat['duration_median_ms'], 500, 2000, 'Complex filter (5 conditions)');
        }
    }

    /**
     * Test filters op verschillende field types
     */
    private function testDifferentFieldTypes(): void {
        $this->log("\n--- Testing different field types ---");

        $fieldTypes = [
            'text' => ['operator' => 'contains', 'value' => 'test'],
            'number' => ['operator' => 'greater_than', 'value' => '50'],
            'date' => ['operator' => 'less_than', 'value' => '2025-01-01'],
            'select' => ['operator' => 'equals', 'value' => 'draft'],
            'checkbox' => ['operator' => 'equals', 'value' => 'true'],
        ];

        foreach ($fieldTypes as $type => $config) {
            $field = $this->findFieldByType($type);
            if (!$field) {
                $this->log("Field type '$type' not found", 'warning');
                continue;
            }

            $filters = [
                [
                    'field' => $field['field_name'],
                    'operator' => $config['operator'],
                    'value' => $config['value'],
                ]
            ];

            $stats = $this->benchmark(
                fn() => $this->filterService->filterFilesByMetadata(
                    $this->testGroupfolderId,
                    $filters,
                    $this->userId
                ),
                "filter_field_type_$type",
                $this->config['test_iterations'],
                ['field_type' => $type, 'operator' => $config['operator']]
            );

            $this->log(sprintf(
                "Field type '%s' (%s): avg=%.2fms, median=%.2fms",
                $type,
                $config['operator'],
                $stats['duration_avg_ms'],
                $stats['duration_median_ms']
            ));
        }
    }

    /**
     * Test met verschillende resultaat set sizes
     */
    private function testLargeResultSets(): void {
        $this->log("\n--- Testing large result sets ---");

        // Test met een filter die veel resultaten oplevert
        $field = $this->findFieldByType('text');
        if (!$field) {
            $this->log("No text field found", 'warning');
            return;
        }

        // Filter die waarschijnlijk veel matches heeft
        $filters = [
            [
                'field' => $field['field_name'],
                'operator' => 'is_not_empty',
                'value' => '',
            ]
        ];

        $stats = $this->benchmark(
            fn() => $this->filterService->filterFilesByMetadata(
                $this->testGroupfolderId,
                $filters,
                $this->userId
            ),
            'filter_large_result_set',
            min(5, $this->config['test_iterations']),
            ['expected_results' => 'many']
        );

        $this->log(sprintf(
            "Large result set filter: avg=%.2fms, median=%.2fms, p95=%.2fms",
            $stats['duration_avg_ms'],
            $stats['duration_median_ms'],
            $stats['duration_p95_ms']
        ));

        // Voor zeer grote result sets is langere tijd acceptabel
        $this->evaluatePerformance($stats['duration_median_ms'], 1000, 5000, 'Large result set filter');
    }

    /**
     * Vind een field van een specifiek type
     */
    private function findFieldByType(string $type): ?array {
        foreach ($this->testFields as $field) {
            if ($field['field_type'] === $type) {
                return $field;
            }
        }
        return null;
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
        $this->log("\n=== FilterService Performance Test Results ===");

        $summary = $this->calculateSummary();

        $this->log(sprintf("Total tests: %d", $summary['total_tests']));
        $this->log(sprintf("Successful: %d", $summary['successful_tests']));
        $this->log(sprintf("Failed: %d", $summary['failed_tests']));
        $this->log(sprintf("Total duration: %.2f minutes", $summary['total_duration_minutes']));
    }
}
