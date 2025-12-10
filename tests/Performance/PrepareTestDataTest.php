<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Performance;

/**
 * Test Data Preparation Script
 *
 * This test creates and stores test data (file IDs and metadata) that can be
 * reused by other performance tests. This approach is much faster than
 * recreating data for each test.
 *
 * Features:
 * - Creates test files with metadata in the database
 * - Stores file IDs for reuse by other tests
 * - Configurable dataset sizes (default: 100, supports millions)
 * - Fast database-only mode (no physical file I/O)
 *
 * Automatically creates test data and provides info on how to use it.
 * Configurable via fileCount parameter from License Server.
 */
class PrepareTestDataTest extends PerformanceTestBase {

    private int $testGroupfolderId;
    private array $testFileIds = [];
    private int $fileCount = 100;  // Default, can be overridden
    private array $teamFolderFields = [];
    private array $fileMetadataFields = [];
    private array $createdTestFields = [];
    private string $datasetName;

    // Directory to store prepared datasets
    private const DATASET_DIR = '/var/www/nextcloud/data/metavox_test_datasets';

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

        if (isset($config['fileCount'])) {
            $this->fileCount = (int)$config['fileCount'];
        }
        $this->datasetName = 'dataset_' . $this->fileCount . '_' . date('Ymd_His');
    }

    public function run(): void {
        $this->log("=== Test Data Preparation Script ===");
        $this->log("Dataset size: " . $this->fileCount . " files");

        // Ensure dataset directory exists
        if (!is_dir(self::DATASET_DIR)) {
            mkdir(self::DATASET_DIR, 0777, true);
        }

        try {
            // Initialize test data
            $this->initializeTestData();

            if (empty($this->testFileIds)) {
                $this->log("ERROR: Could not create test data", 'error');
                return;
            }

            // Save dataset info
            $this->saveDataset();

            // Print usage info
            $this->printUsageInfo();

        } catch (\Exception $e) {
            $this->log("ERROR: " . $e->getMessage(), 'error');
        }
    }

    /**
     * Initialize test data - creates test files and fields
     */
    private function initializeTestData(): void {
        $this->log("Creating test dataset...");

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

        $this->log("Dataset created successfully!");
        $this->log("  Files: " . count($this->testFileIds));
        $this->log("  Team folder fields: " . count($this->teamFolderFields));
        $this->log("  File metadata fields: " . count($this->fileMetadataFields));
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

        // Create file metadata fields if needed (at least 4 for comprehensive testing)
        $targetFieldCount = 4;
        while (count($this->fileMetadataFields) < $targetFieldCount) {
            $fieldId = $this->createTestField(
                'perf_test_file_' . time() . '_' . count($this->fileMetadataFields),
                'File Metadata Test Field ' . (count($this->fileMetadataFields) + 1),
                'text',
                0
            );
            if ($fieldId) {
                $this->createdTestFields[] = $fieldId;
                $this->fileMetadataFields[] = $this->fieldService->getFieldById($fieldId);
                $this->log("Created file metadata test field: " . $fieldId);
            } else {
                break;
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
     * Create test files with metadata (database-only for performance)
     */
    private function createTestFilesWithMetadata(): void {
        $this->log("Creating " . $this->fileCount . " test files with metadata...");

        try {
            // Start with a realistic base file ID
            $baseFileId = 1000000 + time();

            // Generate file IDs and insert metadata in batches
            $batchSize = 1000;
            $totalBatches = (int)ceil($this->fileCount / $batchSize);

            for ($batch = 0; $batch < $totalBatches; $batch++) {
                $startIdx = $batch * $batchSize;
                $endIdx = min($startIdx + $batchSize, $this->fileCount);

                // Generate file IDs for this batch
                $batchFileIds = [];
                for ($i = $startIdx; $i < $endIdx; $i++) {
                    $fileId = $baseFileId + $i;
                    $batchFileIds[] = $fileId;
                    $this->testFileIds[] = $fileId;
                }

                // Insert metadata for each file
                $this->insertBatchMetadata($batchFileIds);

                if (($batch + 1) % 10 === 0 || ($batch + 1) === $totalBatches) {
                    $this->log("Progress: " . count($this->testFileIds) . "/$this->fileCount files created");
                }
            }

            // Also add team folder metadata
            $this->addTeamFolderMetadata();

            $this->log("Successfully created " . count($this->testFileIds) . " test files with metadata");

        } catch (\Exception $e) {
            $this->log("ERROR creating test files: " . $e->getMessage(), 'error');
        }
    }

    /**
     * Insert metadata for a batch of files using raw SQL for maximum performance
     * This avoids the query builder overhead and parameter limits
     */
    private function insertBatchMetadata(array $fileIds): void {
        if (empty($fileIds) || empty($this->fileMetadataFields)) {
            return;
        }

        // Build VALUES for bulk insert - split into smaller chunks to avoid parameter limits
        // Oracle has a limit of 1000 expressions per list, PostgreSQL has 65535 parameter limit
        // With 4 columns per row, we can safely do 200 rows at a time (800 parameters)
        $chunkSize = 200;
        $chunks = array_chunk($fileIds, $chunkSize);

        foreach ($chunks as $fileIdChunk) {
            // Build all value rows for this chunk
            $valueRows = [];
            $params = [];
            $paramIndex = 0;

            foreach ($fileIdChunk as $fileId) {
                foreach ($this->fileMetadataFields as $field) {
                    $value = "test_value_" . $field['field_name'] . "_" . $fileId;

                    $valueRows[] = "(?, ?, ?, ?)";
                    $params[] = $this->testGroupfolderId;
                    $params[] = $fileId;
                    $params[] = $field['field_name'];
                    $params[] = $value;
                }
            }

            // Execute bulk insert
            $sql = "INSERT INTO `*PREFIX*metavox_file_gf_meta`
                    (`groupfolder_id`, `file_id`, `field_name`, `field_value`)
                    VALUES " . implode(', ', $valueRows);

            $this->db->executeStatement($sql, $params);
        }
    }

    /**
     * Add team folder metadata
     */
    private function addTeamFolderMetadata(): void {
        foreach ($this->teamFolderFields as $field) {
            $value = "team_folder_test_value_" . $field['field_name'];

            // Delete existing entry first to avoid duplicate key constraint violation
            $qbDel = $this->db->getQueryBuilder();
            $qbDel->delete('metavox_gf_metadata')
                ->where($qbDel->expr()->eq('groupfolder_id', $qbDel->createNamedParameter($this->testGroupfolderId)))
                ->andWhere($qbDel->expr()->eq('field_name', $qbDel->createNamedParameter($field['field_name'])))
                ->executeStatement();

            // Then insert the test value
            $qb = $this->db->getQueryBuilder();
            $qb->insert('metavox_gf_metadata')
                ->values([
                    'groupfolder_id' => $qb->createNamedParameter($this->testGroupfolderId),
                    'field_name' => $qb->createNamedParameter($field['field_name']),
                    'field_value' => $qb->createNamedParameter($value),
                ])
                ->executeStatement();
        }
    }

    /**
     * Save dataset info to file for reuse
     */
    private function saveDataset(): void {
        $datasetFile = self::DATASET_DIR . '/' . $this->datasetName . '.json';

        $datasetInfo = [
            'name' => $this->datasetName,
            'created_at' => date('Y-m-d H:i:s'),
            'file_count' => count($this->testFileIds),
            'groupfolder_id' => $this->testGroupfolderId,
            'file_ids' => $this->testFileIds,
            'team_folder_fields' => $this->teamFolderFields,
            'file_metadata_fields' => $this->fileMetadataFields,
            'created_fields' => $this->createdTestFields,
        ];

        file_put_contents($datasetFile, json_encode($datasetInfo, JSON_PRETTY_PRINT));
        $this->log("Dataset saved to: $datasetFile");
    }

    /**
     * Print usage info
     */
    private function printUsageInfo(): void {
        $this->log("\n=== Dataset Ready ===");
        $this->log("Dataset name: " . $this->datasetName);
        $this->log("Location: " . self::DATASET_DIR . '/' . $this->datasetName . '.json');
        $this->log("\nTo use this dataset in your tests:");
        $this->log("1. Load the dataset JSON file");
        $this->log("2. Use the file_ids array for testing");
        $this->log("3. Reference groupfolder_id and field info");
        $this->log("\nExample code:");
        $this->log('$dataset = json_decode(file_get_contents("' . self::DATASET_DIR . '/' . $this->datasetName . '.json"), true);');
        $this->log('$fileIds = $dataset["file_ids"];');
        $this->log('$groupfolderId = $dataset["groupfolder_id"];');
        $this->log("\nDataset created successfully! Other tests can now reuse this data.");
    }
}
