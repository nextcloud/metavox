<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Performance;

use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\SearchIndexService;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\Files\IRootFolder;

/**
 * Base class voor performance tests
 *
 * BELANGRIJK: Tests gebruiken ALLEEN de Nextcloud services,
 * niet directe database toegang. Dit zorgt voor:
 * - Realistische test scenario's
 * - Correcte caching behavior
 * - Event listeners worden getriggered
 * - Permission checks worden uitgevoerd
 */
abstract class PerformanceTestBase {

    protected FieldService $fieldService;
    protected SearchIndexService $searchService;
    protected IDBConnection $db;
    protected IUserSession $userSession;
    protected IRootFolder $rootFolder;
    protected $logger;  // No type hint - Nextcloud logger interface varies

    protected array $metrics = [];
    protected array $config = [];

    public function __construct(
        FieldService $fieldService,
        SearchIndexService $searchService,
        IDBConnection $db,
        IUserSession $userSession,
        IRootFolder $rootFolder,
        $logger  // No type hint - Nextcloud logger interface varies
    ) {
        $this->fieldService = $fieldService;
        $this->searchService = $searchService;
        $this->db = $db;
        $this->userSession = $userSession;
        $this->rootFolder = $rootFolder;
        $this->logger = $logger;

        $this->loadConfig();
    }

    /**
     * Laad test configuratie
     */
    protected function loadConfig(): void {
        $configFile = __DIR__ . '/config.php';
        if (file_exists($configFile)) {
            $this->config = require $configFile;
        } else {
            // Default configuratie
            $this->config = [
                'warmup_iterations' => 3,
                'test_iterations' => 10,
                'concurrent_users' => 10,
                'batch_size' => 1000,
                'max_records' => 10000000,
                'timeout_seconds' => 3600, // 1 uur voor grote datasets
            ];
        }
    }

    /**
     * Meet de executie tijd van een operatie
     *
     * @param callable $operation De uit te voeren operatie
     * @param string $name Naam van de metric
     * @param array $context Extra context informatie
     * @return mixed Het resultaat van de operatie
     */
    protected function measure(callable $operation, string $name, array $context = []): mixed {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        try {
            $result = $operation();
            $success = true;
            $error = null;
        } catch (\Exception $e) {
            $success = false;
            $error = $e->getMessage();
            $result = null;
            $this->logger->error("Performance test error in $name: " . $e->getMessage(), [
                'exception' => $e,
                'context' => $context,
            ]);
        }

        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);

        $metric = [
            'name' => $name,
            'duration_ms' => ($endTime - $startTime) * 1000,
            'memory_mb' => ($endMemory - $startMemory) / 1024 / 1024,
            'peak_memory_mb' => memory_get_peak_usage(true) / 1024 / 1024,
            'timestamp' => date('Y-m-d H:i:s'),
            'success' => $success,
            'error' => $error,
            'context' => $context,
        ];

        $this->metrics[] = $metric;

        return $result;
    }

    /**
     * Voer een operatie meerdere keren uit en meet statistieken
     */
    protected function benchmark(callable $operation, string $name, int $iterations, array $context = []): array {
        $durations = [];
        $memories = [];

        // Warmup
        for ($i = 0; $i < $this->config['warmup_iterations']; $i++) {
            $operation();
        }

        // Echte metingen
        for ($i = 0; $i < $iterations; $i++) {
            $startTime = microtime(true);
            $startMemory = memory_get_usage(true);

            $operation();

            $durations[] = (microtime(true) - $startTime) * 1000;
            $memories[] = (memory_get_usage(true) - $startMemory) / 1024 / 1024;
        }

        $stats = [
            'name' => $name,
            'iterations' => $iterations,
            'duration_avg_ms' => array_sum($durations) / count($durations),
            'duration_min_ms' => min($durations),
            'duration_max_ms' => max($durations),
            'duration_median_ms' => $this->median($durations),
            'duration_p95_ms' => $this->percentile($durations, 95),
            'duration_p99_ms' => $this->percentile($durations, 99),
            'memory_avg_mb' => array_sum($memories) / count($memories),
            'memory_max_mb' => max($memories),
            'timestamp' => date('Y-m-d H:i:s'),
            'context' => $context,
        ];

        $this->metrics[] = $stats;

        return $stats;
    }

    /**
     * Bereken mediaan
     */
    protected function median(array $values): float {
        sort($values);
        $count = count($values);
        $middle = floor($count / 2);

        if ($count % 2 === 0) {
            return ($values[$middle - 1] + $values[$middle]) / 2;
        }

        return $values[$middle];
    }

    /**
     * Bereken percentile
     */
    protected function percentile(array $values, float $percentile): float {
        sort($values);
        $index = ceil((count($values) * $percentile) / 100) - 1;
        return $values[$index] ?? 0;
    }

    /**
     * Log een bericht met timestamp
     */
    protected function log(string $message, string $level = 'info'): void {
        $timestamp = date('Y-m-d H:i:s');
        $formattedMessage = "[$timestamp] [$level] $message";

        echo $formattedMessage . PHP_EOL;

        $this->logger->log($level, $message);
    }

    /**
     * Haal alle metrics op
     */
    public function getMetrics(): array {
        return $this->metrics;
    }

    /**
     * Reset metrics
     */
    public function resetMetrics(): void {
        $this->metrics = [];
    }

    /**
     * Sla metrics op naar JSON file
     */
    public function saveMetrics(string $filename): void {
        $resultsDir = __DIR__ . '/results';
        if (!is_dir($resultsDir)) {
            mkdir($resultsDir, 0755, true);
        }

        $filepath = $resultsDir . '/' . $filename;
        file_put_contents(
            $filepath,
            json_encode([
                'test_class' => get_class($this),
                'config' => $this->config,
                'database' => $this->getDatabaseInfo(),
                'php_version' => PHP_VERSION,
                'metrics' => $this->metrics,
                'summary' => $this->calculateSummary(),
            ], JSON_PRETTY_PRINT)
        );

        $this->log("Metrics saved to: $filepath");
    }

    /**
     * Database informatie voor rapporten
     */
    protected function getDatabaseInfo(): array {
        try {
            $platform = $this->db->getDatabasePlatform();

            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('VERSION()'));
            $result = $qb->executeQuery();
            $version = $result->fetchOne();
            $result->closeCursor();

            return [
                'platform' => get_class($platform),
                'version' => $version,
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Bereken samenvatting van alle metrics
     */
    protected function calculateSummary(): array {
        $totalTests = count($this->metrics);
        $successfulTests = count(array_filter($this->metrics, fn($m) => $m['success'] ?? true));
        $totalDuration = array_sum(array_column($this->metrics, 'duration_ms'));

        return [
            'total_tests' => $totalTests,
            'successful_tests' => $successfulTests,
            'failed_tests' => $totalTests - $successfulTests,
            'total_duration_ms' => $totalDuration,
            'total_duration_minutes' => $totalDuration / 1000 / 60,
        ];
    }

    /**
     * Load a prepared dataset by name or find the latest matching dataset
     *
     * @param int|null $fileCount Expected file count (null = use latest available)
     * @return array|null Dataset info or null if not found
     */
    protected function loadPreparedDataset(?int $fileCount = null): ?array {
        $datasetDir = '/var/www/nextcloud/data/metavox_test_datasets';

        if (!is_dir($datasetDir)) {
            $this->log("Dataset directory not found: $datasetDir", 'warning');
            return null;
        }

        // Find all dataset files
        $datasetFiles = glob($datasetDir . '/dataset_*.json');
        if (empty($datasetFiles)) {
            $this->log("No prepared datasets found in $datasetDir", 'warning');
            return null;
        }

        // Sort by modification time (newest first)
        usort($datasetFiles, fn($a, $b) => filemtime($b) <=> filemtime($a));

        // If fileCount specified, try to find exact match first
        if ($fileCount !== null) {
            foreach ($datasetFiles as $file) {
                $data = json_decode(file_get_contents($file), true);
                if ($data && ($data['file_count'] ?? 0) >= $fileCount) {
                    $this->log("Found matching dataset: " . basename($file) . " with " . $data['file_count'] . " files");
                    return $data;
                }
            }
            $this->log("No dataset found with at least $fileCount files", 'warning');
            return null;
        }

        // Use latest dataset
        $latestFile = $datasetFiles[0];
        $data = json_decode(file_get_contents($latestFile), true);
        if ($data) {
            $this->log("Using latest dataset: " . basename($latestFile) . " with " . ($data['file_count'] ?? 0) . " files");
            return $data;
        }

        return null;
    }

    /**
     * List all available prepared datasets
     *
     * @return array Array of dataset info
     */
    protected function listPreparedDatasets(): array {
        $datasetDir = '/var/www/nextcloud/data/metavox_test_datasets';

        if (!is_dir($datasetDir)) {
            return [];
        }

        $datasetFiles = glob($datasetDir . '/dataset_*.json');
        if (empty($datasetFiles)) {
            return [];
        }

        $datasets = [];
        foreach ($datasetFiles as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data) {
                $datasets[] = [
                    'name' => $data['name'] ?? basename($file, '.json'),
                    'file_count' => $data['file_count'] ?? 0,
                    'created_at' => $data['created_at'] ?? '',
                    'groupfolder_id' => $data['groupfolder_id'] ?? 0,
                ];
            }
        }

        return $datasets;
    }

    /**
     * Abstract methode die elke test moet implementeren
     */
    abstract public function run(): void;
}
