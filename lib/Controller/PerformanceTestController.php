<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\SearchIndexService;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

/**
 * Performance Test API Controller
 *
 * Provides REST API endpoints for triggering and monitoring performance tests
 * Can be called from external systems (like the License Server)
 */
class PerformanceTestController extends OCSController {

    private FieldService $fieldService;
    private SearchIndexService $searchService;
    private IDBConnection $db;
    private IUserSession $userSession;
    private IRootFolder $rootFolder;
    private LoggerInterface $logger;

    // Store running tests in memory
    private static array $runningTests = [];

    // Directory for storing test run data
    // Using Nextcloud data directory instead of /tmp to avoid systemd PrivateTmp issues
    private const TEST_RUN_DIR = '/var/www/nextcloud/data/metavox_perf_tests';

    public function __construct(
        string $appName,
        IRequest $request,
        FieldService $fieldService,
        SearchIndexService $searchService,
        IDBConnection $db,
        IUserSession $userSession,
        IRootFolder $rootFolder,
        LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
        $this->fieldService = $fieldService;
        $this->searchService = $searchService;
        $this->db = $db;
        $this->userSession = $userSession;
        $this->rootFolder = $rootFolder;
        $this->logger = $logger;

        // Ensure test run directory exists
        if (!is_dir(self::TEST_RUN_DIR)) {
            mkdir(self::TEST_RUN_DIR, 0777, true);
        }

        // BREADCRUMB: Prove controller is instantiated
        file_put_contents(self::TEST_RUN_DIR . '/BREADCRUMB_constructor_' . time() . '.txt',
            'PerformanceTestController instantiated at ' . date('Y-m-d H:i:s'));
    }

    /**
     * Save test run data to file
     */
    private function saveTestRun(string $testRunId, array $data): void {
        $file = self::TEST_RUN_DIR . '/' . $testRunId . '.json';
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Load test run data from file
     */
    private function loadTestRun(string $testRunId): ?array {
        $file = self::TEST_RUN_DIR . '/' . $testRunId . '.json';
        if (!file_exists($file)) {
            return null;
        }
        $data = file_get_contents($file);
        return json_decode($data, true);
    }

    /**
     * Simple ping test to verify controller is working
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @CORS
     */
    public function ping(): DataResponse {
        // Write breadcrumb
        file_put_contents(self::TEST_RUN_DIR . '/BREADCRUMB_ping_' . time() . '.txt',
            'Ping called at ' . date('Y-m-d H:i:s'));

        return new DataResponse([
            'success' => true,
            'message' => 'pong',
            'timestamp' => time(),
        ], Http::STATUS_OK);
    }

    /**
     * Get list of available performance test suites
     * Dynamically scans the tests/Performance directory for test classes
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @CORS
     */
    public function listTests(): DataResponse {
        try {
            $tests = [];
            $testDir = __DIR__ . '/../../tests/Performance';

            // Scan directory for PHP files (excluding base class)
            if (is_dir($testDir)) {
                $files = scandir($testDir);
                foreach ($files as $file) {
                    // Skip non-PHP files and base class
                    if (!str_ends_with($file, '.php') || $file === 'PerformanceTestBase.php') {
                        continue;
                    }

                    $className = str_replace('.php', '', $file);

                    // Read file contents to extract docblock WITHOUT loading the class
                    $fileContents = file_get_contents("$testDir/$file");

                    // Extract class docblock using regex
                    $docComment = $this->extractClassDocblock($fileContents);

                    // Parse docblock for test metadata
                    $testInfo = $this->parseTestDocblock($docComment, $className);

                    $tests[] = $testInfo;
                }
            }

            // Sort tests by name for consistent ordering
            usort($tests, fn($a, $b) => strcmp($a['name'], $b['name']));

            return new DataResponse([
                'success' => true,
                'tests' => $tests,
            ], Http::STATUS_OK);

        } catch (\Exception $e) {
            return new DataResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Extract class docblock from PHP file contents using regex
     */
    private function extractClassDocblock(string $fileContents): string {
        // Match /** ... */ comment block immediately before "class"
        if (preg_match('/\/\*\*[\s\S]*?\*\/\s*(?:abstract\s+)?class\s+/i', $fileContents, $matches)) {
            // Extract just the comment part
            $match = $matches[0];
            $docComment = preg_replace('/\s*(?:abstract\s+)?class\s+$/i', '', $match);
            return trim($docComment);
        }

        return ''; // No docblock found
    }

    /**
     * Find test class name by test ID
     */
    private function findTestClassById(string $testId): ?string {
        $testDir = __DIR__ . '/../../tests/Performance';

        if (!is_dir($testDir)) {
            return null;
        }

        $files = scandir($testDir);
        foreach ($files as $file) {
            if (!str_ends_with($file, '.php') || $file === 'PerformanceTestBase.php') {
                continue;
            }

            $className = str_replace('.php', '', $file);

            // Generate ID from class name and check if it matches
            $id = strtolower(preg_replace('/Test$/', '', $className));
            $id = preg_replace('/([a-z])([A-Z])/', '$1-$2', $id);
            $id = strtolower($id);

            if ($id === $testId) {
                return $className;
            }
        }

        return null;
    }

    /**
     * Parse test class docblock to extract metadata
     */
    private function parseTestDocblock(string $docComment, string $className): array {
        // Generate ID from class name (e.g., TeamFolderMetadataReadTest -> team-folder-read)
        $id = strtolower(preg_replace('/Test$/', '', $className));
        $id = preg_replace('/([a-z])([A-Z])/', '$1-$2', $id);
        $id = strtolower($id);

        // Extract name from first line of docblock
        $lines = explode("\n", $docComment);
        $name = 'Unknown Test';
        $description = '';

        foreach ($lines as $line) {
            $line = trim($line, " \t\n\r\0\x0B/*");

            // First non-empty line is the test name
            if (empty($name) || $name === 'Unknown Test') {
                if (!empty($line) && !str_starts_with($line, '@')) {
                    $name = $line;
                    continue;
                }
            }

            // Subsequent non-empty, non-@ lines are description
            if (!empty($line) && !str_starts_with($line, '@') && $name !== 'Unknown Test') {
                $description .= $line . ' ';
            }
        }

        $description = trim($description);

        // Check if test has configurable parameters (look for 'fileCount' in docblock or default to true)
        $configurable = str_contains($docComment, 'Configurable') || str_contains($docComment, 'fileCount');

        return [
            'id' => $id,
            'name' => $name,
            'description' => $description ?: 'Performance test for ' . $className,
            'class' => $className,
            'configurable' => $configurable,
            'config_options' => $configurable ? [
                ['name' => 'fileCount', 'label' => 'Number of files to test', 'type' => 'number', 'default' => 100, 'min' => 10]
            ] : []
        ];
    }

    /**
     * Start a performance test suite
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @CORS
     */
    public function startTest(string $testId): DataResponse {
        // Start output buffering IMMEDIATELY to prevent test logs from contaminating JSON response
        ob_start();

        // Write a breadcrumb file to prove this method is called
        file_put_contents(self::TEST_RUN_DIR . '/BREADCRUMB_startTest_' . time() . '.txt', 'startTest called for: ' . $testId);

        $this->logger->info('PerformanceTest: startTest() called for test: ' . $testId);

        try {
            // Get request body for config parameters
            $requestBody = file_get_contents('php://input');
            $requestData = json_decode($requestBody, true) ?? [];
            $config = $requestData['config'] ?? [];

            $this->logger->info('PerformanceTest: Starting test ' . $testId . ' with config: ' . json_encode($config));

            // Find test class by scanning available tests
            $className = $this->findTestClassById($testId);

            if (!$className) {
                return new DataResponse([
                    'success' => false,
                    'error' => 'Test not found',
                ], Http::STATUS_NOT_FOUND);
            }
            $testRunId = uniqid('test_', true);

            $this->logger->info('PerformanceTest: Generated test run ID: ' . $testRunId);

            // Initialize test tracking
            $testData = [
                'test_id' => $testId,
                'class' => $className,
                'config' => $config,
                'status' => 'running',
                'started_at' => date('Y-m-d H:i:s'),
                'output' => [],
                'metrics' => [],
            ];

            $this->logger->info('PerformanceTest: Calling saveTestRun()...');
            $this->saveTestRun($testRunId, $testData);
            $this->logger->info('PerformanceTest: saveTestRun() completed');

            // Run test asynchronously in background to avoid HTTP timeout
            // Create a PHP script that runs the test independently
            $this->logger->info('PerformanceTest: Starting test in background...');
            $this->startTestInBackground($testRunId, $className, $config);
            $this->logger->info('PerformanceTest: Background process started');

            // Clean up output buffer and discard test output (it's saved in test data)
            ob_end_clean();

            return new DataResponse([
                'success' => true,
                'test_run_id' => $testRunId,
                'message' => 'Test started in background',
            ], Http::STATUS_OK);

        } catch (\Exception $e) {
            // Clean up output buffer on exception
            ob_end_clean();

            return new DataResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get status of a running test
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @CORS
     */
    public function getTestStatus(string $testRunId): DataResponse {
        try {
            $test = $this->loadTestRun($testRunId);
            if (!$test) {
                return new DataResponse([
                    'success' => false,
                    'error' => 'Test run not found',
                ], Http::STATUS_NOT_FOUND);
            }

            return new DataResponse([
                'success' => true,
                'test_run_id' => $testRunId,
                'test_id' => $test['test_id'],
                'status' => $test['status'],
                'started_at' => $test['started_at'],
                'completed_at' => $test['completed_at'] ?? null,
                'duration' => $test['duration'] ?? null,
            ], Http::STATUS_OK);

        } catch (\Exception $e) {
            return new DataResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get output/logs from a running or completed test
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @CORS
     */
    public function getTestOutput(string $testRunId): DataResponse {
        try {
            $test = $this->loadTestRun($testRunId);
            if (!$test) {
                return new DataResponse([
                    'success' => false,
                    'error' => 'Test run not found',
                ], Http::STATUS_NOT_FOUND);
            }

            return new DataResponse([
                'success' => true,
                'output' => is_array($test['output']) ? implode("\n", $test['output']) : $test['output'],
            ], Http::STATUS_OK);

        } catch (\Exception $e) {
            return new DataResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get results/metrics from a completed test
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @CORS
     */
    public function getTestResults(string $testRunId): DataResponse {
        try {
            $test = $this->loadTestRun($testRunId);
            if (!$test) {
                return new DataResponse([
                    'success' => false,
                    'error' => 'Test run not found',
                ], Http::STATUS_NOT_FOUND);
            }

            if ($test['status'] !== 'completed' && $test['status'] !== 'failed') {
                return new DataResponse([
                    'success' => false,
                    'error' => 'Test not yet completed',
                ], Http::STATUS_BAD_REQUEST);
            }

            // Use total_duration_ms from summary if available (correct value), otherwise fall back to duration field
            $duration = $test['duration'] ?? null;
            if (isset($test['summary']['total_duration_ms'])) {
                $duration = $test['summary']['total_duration_ms'];
            }

            return new DataResponse([
                'success' => true,
                'test_run_id' => $testRunId,
                'test_id' => $test['test_id'],
                'status' => $test['status'],
                'started_at' => $test['started_at'],
                'completed_at' => $test['completed_at'],
                'duration' => $duration,
                'metrics' => $test['metrics'] ?? [],
                'summary' => $test['summary'] ?? null,
            ], Http::STATUS_OK);

        } catch (\Exception $e) {
            return new DataResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Start test execution in background process
     */
    private function startTestInBackground(string $testRunId, string $className, array $config): void {
        // Create a runner script that will execute the test
        $runnerScript = self::TEST_RUN_DIR . "/runner_$testRunId.php";

        // Get current user for background script
        $user = $this->userSession->getUser();
        $username = $user ? $user->getUID() : 'admin';

        $configJson = base64_encode(json_encode($config));
        $scriptContent = <<<'PHP'
<?php
// Background test runner script
// This script runs independently from the web request

$testRunId = '%TEST_RUN_ID%';
$className = '%CLASS_NAME%';
$username = '%USERNAME%';
$config = json_decode(base64_decode('%CONFIG_JSON%'), true);
if (!is_array($config)) {
    $config = [];  // Fallback to empty array if decode fails
}

// Bootstrap Nextcloud
require_once '/var/www/nextcloud/lib/base.php';

// Get services from DI container
$container = \OC::$server;

// Set up user session for background script
$userManager = $container->get(\OCP\IUserManager::class);
$userSession = $container->get(\OCP\IUserSession::class);
$user = $userManager->get($username);
if ($user) {
    $userSession->setUser($user);
}

$fieldService = $container->get(\OCA\MetaVox\Service\FieldService::class);
$searchService = $container->get(\OCA\MetaVox\Service\SearchIndexService::class);
$db = $container->get(\OCP\IDBConnection::class);
$rootFolder = $container->get(\OCP\Files\IRootFolder::class);
$logger = $container->get(\Psr\Log\LoggerInterface::class);

// Load the controller and execute test
$controller = new \OCA\MetaVox\Controller\PerformanceTestController(
    'metavox',
    $container->get(\OCP\IRequest::class),
    $fieldService,
    $searchService,
    $db,
    $userSession,
    $rootFolder,
    $logger
);

// Use reflection to call private executeTest method
$reflection = new ReflectionClass($controller);
$method = $reflection->getMethod('executeTest');
$method->setAccessible(true);
$method->invoke($controller, $testRunId, $className, $config);

// Clean up this runner script
@unlink(__FILE__);
PHP;

        $scriptContent = str_replace('%TEST_RUN_ID%', $testRunId, $scriptContent);
        $scriptContent = str_replace('%CLASS_NAME%', $className, $scriptContent);
        $scriptContent = str_replace('%USERNAME%', $username, $scriptContent);
        $scriptContent = str_replace('%CONFIG_JSON%', $configJson, $scriptContent);

        file_put_contents($runnerScript, $scriptContent);
        chmod($runnerScript, 0755);

        // Execute in background with nohup to detach from parent process
        $command = sprintf(
            'nohup /usr/bin/php %s > %s/output_%s.log 2>&1 &',
            escapeshellarg($runnerScript),
            self::TEST_RUN_DIR,
            $testRunId
        );

        exec($command);

        $this->logger->info("PerformanceTest: Started background process for test run: $testRunId");
    }

    /**
     * Execute a performance test
     */
    private function executeTest(string $testRunId, string $className, array $config): void {
        try {
            $fullClassName = "OCA\\MetaVox\\Tests\\Performance\\$className";
            $testFile = __DIR__ . "/../../tests/Performance/$className.php";

            $testData = $this->loadTestRun($testRunId);
            if (!$testData) {
                return;
            }

            if (!file_exists($testFile)) {
                $testData['status'] = 'failed';
                $testData['output'] = ["Error: Test file not found: $testFile"];
                $testData['completed_at'] = date('Y-m-d H:i:s');
                $this->saveTestRun($testRunId, $testData);
                return;
            }

            // Load base class first
            require_once __DIR__ . '/../../tests/Performance/PerformanceTestBase.php';

            // Load all test files to handle inheritance dependencies
            $testDir = __DIR__ . '/../../tests/Performance';
            $files = scandir($testDir);
            foreach ($files as $file) {
                if (str_ends_with($file, 'Test.php')) {
                    require_once "$testDir/$file";
                }
            }

            if (!class_exists($fullClassName)) {
                $testData['status'] = 'failed';
                $testData['output'] = ["Error: Test class not found: $fullClassName"];
                $testData['completed_at'] = date('Y-m-d H:i:s');
                $this->saveTestRun($testRunId, $testData);
                return;
            }

            // Instantiate the test with all dependencies and config
            $test = new $fullClassName(
                $this->fieldService,
                $this->searchService,
                $this->db,
                $this->userSession,
                $this->rootFolder,
                $this->logger,
                $config  // Pass config to test constructor
            );

            // Capture output in a nested buffer (parent buffer prevents contamination of JSON response)
            ob_start();
            $startTime = microtime(true);

            // Run the test
            $test->run();

            $duration = microtime(true) - $startTime;
            $output = ob_get_clean();  // Get output from nested buffer

            // Update test status
            $testData['status'] = 'completed';
            $testData['completed_at'] = date('Y-m-d H:i:s');
            $testData['duration'] = (int)($duration * 1000);  // Convert to integer milliseconds
            $testData['output'] = $output;  // Store as string, not array
            $testData['metrics'] = $test->getMetrics();

            // Calculate summary
            $metrics = $test->getMetrics();
            $totalTests = count($metrics);
            $successfulTests = count(array_filter($metrics, fn($m) => $m['success'] ?? true));

            $testData['summary'] = [
                'total_tests' => $totalTests,
                'successful_tests' => $successfulTests,
                'failed_tests' => $totalTests - $successfulTests,
                'total_duration_ms' => (int)($duration * 1000),  // Convert to integer milliseconds
                'file_count' => $testData['config']['fileCount'] ?? $config['fileCount'] ?? null,  // Show configured file count
                'metrics' => $metrics,  // Include all metrics
            ];

            $this->saveTestRun($testRunId, $testData);

        } catch (\Exception $e) {
            $testData = $this->loadTestRun($testRunId);
            if ($testData) {
                $testData['status'] = 'failed';
                $testData['completed_at'] = date('Y-m-d H:i:s');
                $testData['output'] = "Error: " . $e->getMessage() . "\n" . $e->getTraceAsString();
                $this->saveTestRun($testRunId, $testData);
            }
        }
    }

    /**
     * Stop a running test
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @CORS
     */
    public function stopTest(string $testRunId): DataResponse {
        try {
            $testData = $this->loadTestRun($testRunId);
            if (!$testData) {
                return new DataResponse([
                    'success' => false,
                    'error' => 'Test run not found',
                ], Http::STATUS_NOT_FOUND);
            }

            // Mark as stopped (actual stopping would require process management)
            $testData['status'] = 'stopped';
            $testData['completed_at'] = date('Y-m-d H:i:s');
            $this->saveTestRun($testRunId, $testData);

            return new DataResponse([
                'success' => true,
                'message' => 'Test stopped',
            ], Http::STATUS_OK);

        } catch (\Exception $e) {
            return new DataResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Handle CORS preflight request for datasets
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     */
    public function preflightDatasets(): DataResponse {
        $response = new DataResponse([]);
        $this->addCorsHeaders($response);
        return $response;
    }

    /**
     * Get list of available prepared datasets
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @CORS
     * @PublicPage
     */
    public function getDatasets(): DataResponse {
        try {
            $datasetDir = '/var/www/nextcloud/data/metavox_test_datasets';

            if (!is_dir($datasetDir)) {
                $response = new DataResponse([
                    'success' => true,
                    'datasets' => [],
                ], Http::STATUS_OK);
                $this->addCorsHeaders($response);
                return $response;
            }

            $datasetFiles = glob($datasetDir . '/dataset_*.json');
            if (!$datasetFiles) {
                $response = new DataResponse([
                    'success' => true,
                    'datasets' => [],
                ], Http::STATUS_OK);
                $this->addCorsHeaders($response);
                return $response;
            }

            // Sort by modification time (newest first)
            usort($datasetFiles, fn($a, $b) => filemtime($b) <=> filemtime($a));

            $datasets = [];
            foreach ($datasetFiles as $file) {
                $data = json_decode(file_get_contents($file), true);
                if ($data) {
                    $datasets[] = [
                        'name' => $data['name'],
                        'file_count' => $data['file_count'],
                        'created_at' => $data['created_at'],
                        'groupfolder_id' => $data['groupfolder_id'],
                    ];
                }
            }

            $response = new DataResponse([
                'success' => true,
                'datasets' => $datasets,
            ], Http::STATUS_OK);
            $this->addCorsHeaders($response);
            return $response;

        } catch (\Exception $e) {
            $response = new DataResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
            $this->addCorsHeaders($response);
            return $response;
        }
    }

    /**
     * Add CORS headers to response
     */
    private function addCorsHeaders(DataResponse $response): void {
        $response->addHeader('Access-Control-Allow-Origin', '*');
        $response->addHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
        $response->addHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, OCS-APIRequest, Accept');
        // Note: Access-Control-Allow-Credentials NOT set to avoid CSRF issues with wildcard origin
        // Basic Auth headers are sent regardless of credentials flag
    }
}
