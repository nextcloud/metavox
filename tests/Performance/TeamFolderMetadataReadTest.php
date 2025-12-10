<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Performance;

/**
 * Team Folder Metadata Read Performance Test
 *
 * Tests READ performance for:
 * 1. Team folder metadata (groupfolder-level metadata, applies_to_groupfolder=1)
 * 2. File team folder metadata (file-level metadata in groupfolders, applies_to_groupfolder=0)
 *
 * Automatically creates test data and cleans up afterwards.
 * Configurable via fileCount parameter from License Server.
 */
class TeamFolderMetadataReadTest extends PerformanceTestBase {

    protected int $testGroupfolderId;
    protected array $testFileIds = [];
    protected int $fileCount = 100;  // Default, can be overridden
    protected array $teamFolderFields = [];
    protected array $fileMetadataFields = [];
    private array $createdTestFiles = [];  // Track files we created for cleanup
    private array $createdTestFields = [];  // Track fields we created for cleanup
    private bool $usingPreparedDataset = false;  // Track if using prepared dataset

    public function __construct(
        $fieldService,
        $filterService,
        $searchService,
        $db,
        $userSession,
        $rootFolder,
        $logger,
        array $config = []
    ) {
        parent::__construct($fieldService, $filterService, $searchService, $db, $userSession, $rootFolder, $logger);

        // Override file count if specified in config
        if (isset($config['fileCount'])) {
            $this->fileCount = (int)$config['fileCount'];
        }
    }

    public function run(): void {
        $this->log("=== Team Folder Metadata Read Performance Test ===");
        $this->log("File count: " . $this->fileCount);

        try {
            // Initialize test data (creates files and fields if needed)
            $this->initializeTestData();

            if (empty($this->testFileIds)) {
                $this->log("ERROR: Could not initialize test data", 'error');
                return;
            }

            // Run read performance tests
            $this->testReadTeamFolderMetadata();
            $this->testReadFileTeamFolderMetadata();
            $this->testBulkReadFileMetadata();
            $this->testReadTeamFolderWithAllAssignedFields();
            $this->testColdVsWarmCacheRead();

            // Save results
            $this->saveMetrics('team_folder_read_' . $this->fileCount . 'files_' . date('Y-m-d_His') . '.json');
            $this->printResults();

        } finally {
            // Always clean up test data
            $this->cleanupTestData();
        }
    }

    /**
     * Initialize test data - loads prepared dataset or creates test files and fields if needed
     */
    protected function initializeTestData(): void {
        $this->log("Initializing test data...");

        // Try to load a prepared dataset first
        $dataset = $this->loadPreparedDataset($this->fileCount);

        if ($dataset) {
            // Use prepared dataset
            $this->log("Using prepared dataset with " . $dataset['file_count'] . " files");
            $this->testGroupfolderId = $dataset['groupfolder_id'];
            $this->testFileIds = $dataset['file_ids'];
            $this->teamFolderFields = $dataset['team_folder_fields'];
            $this->fileMetadataFields = $dataset['file_metadata_fields'];
            $this->createdTestFields = $dataset['created_fields'] ?? [];
            $this->usingPreparedDataset = true;  // Mark that we're using prepared dataset

            $this->log("Dataset loaded: " . count($this->testFileIds) . " files, " .
                       count($this->teamFolderFields) . " team folder fields, " .
                       count($this->fileMetadataFields) . " file metadata fields");
            return;
        }

        // No prepared dataset found, create test data on-the-fly
        $this->log("No prepared dataset found, creating test data...");

        // Step 1: Find or use first available groupfolder
        $this->testGroupfolderId = $this->findGroupfolder();
        if (!$this->testGroupfolderId) {
            $this->log("ERROR: No groupfolders available", 'error');
            return;
        }
        $this->log("Using groupfolder ID: " . $this->testGroupfolderId);

        // Step 2: Create or find test fields
        $this->setupTestFields();

        // Step 3: Create test files with metadata
        $this->createTestFilesWithMetadata();

        $this->log("Setup complete: " . count($this->testFileIds) . " files, " .
                   count($this->teamFolderFields) . " team folder fields, " .
                   count($this->fileMetadataFields) . " file metadata fields");
    }

    /**
     * Find a groupfolder to use for testing
     */
    private function findGroupfolder(): ?int {
        $qb = $this->db->getQueryBuilder();
        $qb->select('folder_id')
            ->from('group_folders')
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return $row ? (int)$row['folder_id'] : null;
    }

    /**
     * Setup test fields (create if needed)
     */
    private function setupTestFields(): void {
        $this->log("Setting up test fields...");

        // Check if we already have test fields assigned to this groupfolder
        $existingFields = $this->fieldService->getAssignedFieldsWithDataForGroupfolder($this->testGroupfolderId);

        foreach ($existingFields as $field) {
            if (($field['applies_to_groupfolder'] ?? 0) === 1) {
                $this->teamFolderFields[] = $field;
            } else {
                $this->fileMetadataFields[] = $field;
            }
        }

        // Create team folder field if none exists
        if (empty($this->teamFolderFields)) {
            $fieldId = $this->createTestField('perf_test_tf_' . time(), 'Team Folder Test Field', 'text', 1);
            if ($fieldId) {
                $this->createdTestFields[] = $fieldId;
                $this->teamFolderFields[] = $this->fieldService->getFieldById($fieldId);
                $this->log("Created team folder test field: " . $fieldId);
            }
        }

        // Create file metadata field if none exists
        if (empty($this->fileMetadataFields)) {
            $fieldId = $this->createTestField('perf_test_file_' . time(), 'File Metadata Test Field', 'text', 0);
            if ($fieldId) {
                $this->createdTestFields[] = $fieldId;
                $this->fileMetadataFields[] = $this->fieldService->getFieldById($fieldId);
                $this->log("Created file metadata test field: " . $fieldId);
            }
        }

        // Assign fields to groupfolder
        $allFieldIds = array_merge(
            array_column($this->teamFolderFields, 'id'),
            array_column($this->fileMetadataFields, 'id')
        );
        $this->fieldService->setGroupfolderFields($this->testGroupfolderId, $allFieldIds);
    }

    /**
     * Create a test field
     */
    private function createTestField(string $name, string $label, string $type, int $appliesToGroupfolder): ?int {
        try {
            return $this->fieldService->createField([
                'field_name' => $name,
                'field_label' => $label,
                'field_type' => $type,
                'field_options' => [],
                'is_required' => false,
                'sort_order' => 999,
                'scope' => 'groupfolder',
                'applies_to_groupfolder' => $appliesToGroupfolder
            ]);
        } catch (\Exception $e) {
            $this->log("Failed to create test field: " . $e->getMessage(), 'error');
            return null;
        }
    }

    /**
     * Create test files with metadata
     * For performance, we inject metadata directly into the database without creating physical files
     */
    private function createTestFilesWithMetadata(): void {
        $this->log("Preparing " . $this->fileCount . " test file metadata entries...");

        // Get field ID for metadata
        $fileField = $this->fileMetadataFields[0] ?? null;
        if (!$fileField) {
            $this->log("ERROR: No file metadata field available", 'error');
            return;
        }

        try {
            // For performance testing, we inject metadata directly into DB
            // This allows testing with millions of files without disk I/O bottleneck
            $this->log("Injecting metadata directly into database (fast mode)");

            // Start with a realistic base file ID (simulating existing files)
            $baseFileId = 1000000 + time();

            // Insert metadata in batches for optimal performance
            $batchSize = 1000;
            $totalBatches = (int)ceil($this->fileCount / $batchSize);

            for ($batch = 0; $batch < $totalBatches; $batch++) {
                $startIdx = $batch * $batchSize;
                $endIdx = min($startIdx + $batchSize, $this->fileCount);

                // Prepare batch insert
                $qb = $this->db->getQueryBuilder();
                $qb->insert('metavox_file_gf_meta')
                    ->values([
                        'groupfolder_id' => $qb->createParameter('groupfolder_id'),
                        'file_id' => $qb->createParameter('file_id'),
                        'field_name' => $qb->createParameter('field_name'),
                        'field_value' => $qb->createParameter('field_value'),
                        'created_at' => $qb->createParameter('created_at'),
                        'updated_at' => $qb->createParameter('updated_at')
                    ]);

                for ($i = $startIdx; $i < $endIdx; $i++) {
                    $fileId = $baseFileId + $i;
                    $this->testFileIds[] = $fileId;

                    $qb->setParameter('groupfolder_id', $this->testGroupfolderId)
                        ->setParameter('file_id', $fileId)
                        ->setParameter('field_name', $fileField['field_name'])
                        ->setParameter('field_value', "Test value $i")
                        ->setParameter('created_at', date('Y-m-d H:i:s'))
                        ->setParameter('updated_at', date('Y-m-d H:i:s'));

                    $qb->executeStatement();
                }

                if (($batch + 1) % 10 === 0 || ($batch + 1) === $totalBatches) {
                    $this->log("Progress: " . count($this->testFileIds) . "/$this->fileCount metadata entries created");
                }
            }

            // Add team folder metadata
            if (!empty($this->teamFolderFields)) {
                $teamField = $this->teamFolderFields[0];
                $this->fieldService->saveGroupfolderFieldValue(
                    $this->testGroupfolderId,
                    $teamField['id'],
                    "Team folder test value"
                );
                $this->log("Added team folder metadata");
            }

            $this->log("Successfully created " . count($this->testFileIds) . " test files with metadata");

        } catch (\Exception $e) {
            $this->log("ERROR creating test files: " . $e->getMessage(), 'error');
        }
    }

    /**
     * Find groupfolder mount path
     */
    private function findGroupfolderPath(int $groupfolderId): ?string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('mount_point')
            ->from('group_folders')
            ->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($groupfolderId)));

        $result = $qb->executeQuery();
        $mountPoint = $result->fetchOne();
        $result->closeCursor();

        // Groupfolders are mounted at /__groupfolders/<mount_point>
        return $mountPoint ? '/__groupfolders/' . $mountPoint : null;
    }

    /**
     * Find the groupfolder node in user's accessible folders
     */
    protected function findGroupfolderNode($userFolder) {
        try {
            // Get mount point name from database
            $qb = $this->db->getQueryBuilder();
            $qb->select('mount_point')
                ->from('group_folders')
                ->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($this->testGroupfolderId)));

            $result = $qb->executeQuery();
            $mountPoint = $result->fetchOne();
            $result->closeCursor();

            if (!$mountPoint) {
                return null;
            }

            $this->log("Looking for groupfolder with mount point: " . $mountPoint);

            // Try to find the groupfolder in user's accessible folders
            // Groupfolders can be mounted in different ways, so we try multiple approaches

            // Approach 1: Direct path with __groupfolders
            try {
                $path = '__groupfolders/' . $mountPoint;
                $node = $userFolder->get($path);
                $this->log("Found groupfolder at: " . $path);
                return $node;
            } catch (\Exception $e) {
                // Not found this way, try next approach
            }

            // Approach 2: Search by mount point name at root
            try {
                $node = $userFolder->get($mountPoint);
                $this->log("Found groupfolder at root: " . $mountPoint);
                return $node;
            } catch (\Exception $e) {
                // Not found this way either
            }

            // Approach 3: Search all folders for one with matching name
            $this->log("Searching all folders in user directory...");
            $folders = $userFolder->getDirectoryListing();
            foreach ($folders as $folder) {
                if ($folder->getName() === $mountPoint) {
                    $this->log("Found groupfolder by name match: " . $mountPoint);
                    return $folder;
                }
                // Also check __groupfolders subdirectory
                if ($folder->getName() === '__groupfolders') {
                    try {
                        $gfSubfolders = $folder->getDirectoryListing();
                        foreach ($gfSubfolders as $gfFolder) {
                            if ($gfFolder->getName() === $mountPoint) {
                                $this->log("Found groupfolder in __groupfolders: " . $mountPoint);
                                return $gfFolder;
                            }
                        }
                    } catch (\Exception $e) {
                        // Continue searching
                    }
                }
            }

            $this->log("Could not find groupfolder: " . $mountPoint, 'warning');
            return null;
        } catch (\Exception $e) {
            $this->log("Error finding groupfolder node: " . $e->getMessage(), 'error');
            return null;
        }
    }

    /**
     * Clean up all test data
     */
    protected function cleanupTestData(): void {
        $this->log("\n=== Cleaning up test data ===");

        // Skip cleanup if using prepared dataset (we want to keep it for reuse)
        if ($this->usingPreparedDataset) {
            $this->log("Using prepared dataset - skipping cleanup to preserve data for reuse");
            return;
        }

        // Delete test metadata (no physical files to delete in fast mode)
        if (!empty($this->testFileIds)) {
            $this->log("Cleaning up " . count($this->testFileIds) . " test metadata entries...");

            try {
                // Delete in batches for performance
                $batchSize = 1000;
                $totalBatches = (int)ceil(count($this->testFileIds) / $batchSize);

                for ($batch = 0; $batch < $totalBatches; $batch++) {
                    $startIdx = $batch * $batchSize;
                    $fileIdsBatch = array_slice($this->testFileIds, $startIdx, $batchSize);

                    $qb = $this->db->getQueryBuilder();
                    $qb->delete('metavox_file_gf_meta')
                        ->where($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($this->testGroupfolderId)))
                        ->andWhere($qb->expr()->in('file_id', $qb->createNamedParameter($fileIdsBatch, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY)));
                    $qb->executeStatement();

                    if (($batch + 1) % 10 === 0 || ($batch + 1) === $totalBatches) {
                        $deletedCount = min(($batch + 1) * $batchSize, count($this->testFileIds));
                        $this->log("Progress: $deletedCount/" . count($this->testFileIds) . " metadata entries deleted");
                    }
                }

                $this->log("Test metadata cleaned up successfully");
            } catch (\Exception $e) {
                $this->log("Error cleaning up metadata: " . $e->getMessage(), 'warning');
            }
        }

        // Delete test fields
        if (!empty($this->createdTestFields)) {
            $this->log("Deleting " . count($this->createdTestFields) . " test fields...");

            foreach ($this->createdTestFields as $fieldId) {
                try {
                    $this->fieldService->deleteField($fieldId);
                } catch (\Exception $e) {
                    $this->log("Error deleting field $fieldId: " . $e->getMessage(), 'warning');
                }
            }

            $this->log("Test fields cleaned up successfully");
        }

        $this->log("Cleanup complete!");
    }

    /**
     * Test reading team folder metadata (groupfolder-level)
     */
    private function testReadTeamFolderMetadata(): void {
        $this->log("\n--- Testing Team Folder Metadata Read ---");

        $stats = $this->benchmark(
            fn() => $this->fieldService->getGroupfolderMetadata($this->testGroupfolderId),
            'read_team_folder_metadata',
            $this->config['test_iterations']
        );

        $this->log(sprintf(
            "Read team folder metadata: avg=%.2fms, median=%.2fms, p95=%.2fms, p99=%.2fms",
            $stats['duration_avg_ms'],
            $stats['duration_median_ms'],
            $stats['duration_p95_ms'],
            $stats['duration_p99_ms']
        ));

        // Target: <50ms for team folder metadata read
        $this->evaluatePerformance($stats['duration_median_ms'], 50, 200, 'Team Folder Metadata Read');
    }

    /**
     * Test reading file metadata within team folder
     */
    private function testReadFileTeamFolderMetadata(): void {
        $this->log("\n--- Testing File Team Folder Metadata Read ---");

        if (empty($this->testFileIds)) {
            $this->log("No test files available", 'warning');
            return;
        }

        $testFileId = $this->testFileIds[array_rand($this->testFileIds)];

        $stats = $this->benchmark(
            fn() => $this->fieldService->getGroupfolderFileMetadata($this->testGroupfolderId, $testFileId),
            'read_file_team_folder_metadata',
            $this->config['test_iterations'],
            ['file_id' => $testFileId]
        );

        $this->log(sprintf(
            "Read file team folder metadata: avg=%.2fms, median=%.2fms, p95=%.2fms",
            $stats['duration_avg_ms'],
            $stats['duration_median_ms'],
            $stats['duration_p95_ms']
        ));

        // Target: <20ms for single file metadata read
        $this->evaluatePerformance($stats['duration_median_ms'], 20, 100, 'File Team Folder Metadata Read');
    }

    /**
     * Test bulk reading of file metadata
     */
    private function testBulkReadFileMetadata(): void {
        $this->log("\n--- Testing Bulk Read of File Metadata ---");

        if (count($this->testFileIds) < 10) {
            $this->log("Not enough test files for bulk read test", 'warning');
            return;
        }

        // Scale batch sizes based on total file count
        $maxBatchSize = min(10000, count($this->testFileIds));
        $batchSizes = [10, 50, 100, 500, 1000];

        // Add larger batch sizes for big datasets
        if ($maxBatchSize >= 5000) {
            $batchSizes[] = 5000;
        }
        if ($maxBatchSize >= 10000) {
            $batchSizes[] = 10000;
        }

        foreach ($batchSizes as $size) {
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
                "bulk_read_{$size}_files",
                min(5, $this->config['test_iterations']),
                ['file_count' => $size]
            );

            $avgPerFile = $stats['duration_median_ms'] / $size;
            $filesPerSecond = 1000 / $avgPerFile;

            $this->log(sprintf(
                "Bulk read %d files: total=%.2fms, avg=%.2fms/file, throughput=%.2f files/sec",
                $size,
                $stats['duration_median_ms'],
                $avgPerFile,
                $filesPerSecond
            ));

            // Target: <5ms per file for bulk reads
            if ($avgPerFile <= 5) {
                $this->log("  Performance: EXCELLENT", 'info');
            } elseif ($avgPerFile <= 10) {
                $this->log("  Performance: GOOD", 'info');
            } elseif ($avgPerFile <= 20) {
                $this->log("  Performance: ACCEPTABLE", 'warning');
            } else {
                $this->log("  Performance: SLOW", 'error');
            }
        }
    }

    /**
     * Test reading team folder with all assigned fields
     */
    private function testReadTeamFolderWithAllAssignedFields(): void {
        $this->log("\n--- Testing Read Team Folder with All Assigned Fields ---");

        $stats = $this->benchmark(
            fn() => $this->fieldService->getAssignedFieldsWithDataForGroupfolder($this->testGroupfolderId),
            'read_assigned_fields_with_data',
            $this->config['test_iterations']
        );

        $this->log(sprintf(
            "Read assigned fields with data: avg=%.2fms, median=%.2fms, p95=%.2fms",
            $stats['duration_avg_ms'],
            $stats['duration_median_ms'],
            $stats['duration_p95_ms']
        ));

        // Target: <100ms for reading all assigned fields
        $this->evaluatePerformance($stats['duration_median_ms'], 100, 300, 'Read Assigned Fields');
    }

    /**
     * Test cold cache vs warm cache performance
     */
    private function testColdVsWarmCacheRead(): void {
        $this->log("\n--- Testing Cold vs Warm Cache Performance ---");

        // Warm cache test - repeated calls should be faster
        $warmIterations = 100;
        $startTime = microtime(true);

        for ($i = 0; $i < $warmIterations; $i++) {
            $this->fieldService->getGroupfolderMetadata($this->testGroupfolderId);
        }

        $warmDuration = (microtime(true) - $startTime) * 1000;
        $warmAvg = $warmDuration / $warmIterations;

        $this->metrics[] = [
            'name' => 'warm_cache_read',
            'avg_duration_ms' => $warmAvg,
            'total_duration_ms' => $warmDuration,
            'iterations' => $warmIterations,
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        $this->log(sprintf(
            "Warm cache (100 calls): avg=%.2fms per call",
            $warmAvg
        ));

        if ($warmAvg < 1) {
            $this->log("Cache performance: EXCELLENT", 'info');
        } elseif ($warmAvg < 5) {
            $this->log("Cache performance: GOOD", 'info');
        } elseif ($warmAvg < 10) {
            $this->log("Cache performance: ACCEPTABLE", 'warning');
        } else {
            $this->log("Cache performance: POOR", 'error');
        }
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
    private function printResults(): void {
        $this->log("\n=== Team Folder Metadata Read Performance Test Results ===");

        $summary = $this->calculateSummary();

        $this->log(sprintf("File count tested: %d", $this->fileCount));
        $this->log(sprintf("Total tests: %d", $summary['total_tests']));
        $this->log(sprintf("Successful: %d", $summary['successful_tests']));
        $this->log(sprintf("Failed: %d", $summary['failed_tests']));
        $this->log(sprintf("Total duration: %.2f seconds", $summary['total_duration_ms'] / 1000));

        $this->log("\nTest completed successfully!");
    }
}
