<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Performance;

/**
 * Test Data Cleanup Script
 *
 * Cleans up test datasets and metadata created by PrepareTestDataTest.
 *
 * Features:
 * - Lists all available datasets
 * - Removes dataset files and database entries
 * - Optionally clean up all or specific datasets
 * - Reports cleanup statistics
 */
class CleanupTestDataTest extends PerformanceTestBase {

    private const DATASET_DIR = '/var/www/nextcloud/data/metavox_test_datasets';
    private int $filesDeleted = 0;
    private int $fieldsDeleted = 0;
    private int $datasetsDeleted = 0;

    public function run(): void {
        $this->log("=== Test Data Cleanup Script ===");

        try {
            // List all available datasets
            $datasets = $this->listPreparedDatasets();

            if (empty($datasets)) {
                $this->log("No datasets found to clean up.");
                return;
            }

            $this->log("Found " . count($datasets) . " dataset(s):");
            foreach ($datasets as $dataset) {
                $this->log("  - {$dataset['name']}: {$dataset['file_count']} files (created: {$dataset['created_at']})");
            }

            // Clean up all datasets
            foreach ($datasets as $dataset) {
                $this->cleanupDataset($dataset['name']);
            }

            // Print summary
            $this->printSummary();

        } catch (\Exception $e) {
            $this->log("ERROR: " . $e->getMessage(), 'error');
        }
    }

    /**
     * Clean up a specific dataset
     */
    private function cleanupDataset(string $datasetName): void {
        $this->log("\nCleaning up dataset: $datasetName");

        // Load dataset info
        $datasetFile = self::DATASET_DIR . "/$datasetName.json";
        if (!file_exists($datasetFile)) {
            $this->log("Dataset file not found: $datasetFile", 'warning');
            return;
        }

        $dataset = json_decode(file_get_contents($datasetFile), true);
        if (!$dataset) {
            $this->log("Failed to parse dataset file", 'error');
            return;
        }

        // Delete file metadata
        if (!empty($dataset['file_ids'])) {
            $this->deleteFileMetadata($dataset['groupfolder_id'], $dataset['file_ids']);
        }

        // Delete team folder metadata
        if (!empty($dataset['team_folder_fields'])) {
            $this->deleteTeamFolderMetadata($dataset['groupfolder_id'], $dataset['team_folder_fields']);
        }

        // Delete created fields
        if (!empty($dataset['created_fields'])) {
            $this->deleteFields($dataset['created_fields']);
        }

        // Delete dataset file
        if (unlink($datasetFile)) {
            $this->log("Deleted dataset file: $datasetFile");
            $this->datasetsDeleted++;
        }
    }

    /**
     * Delete file metadata from database
     */
    private function deleteFileMetadata(int $groupfolderId, array $fileIds): void {
        $this->log("Deleting metadata for " . count($fileIds) . " files...");

        try {
            $batchSize = 1000;
            $totalBatches = (int)ceil(count($fileIds) / $batchSize);

            for ($batch = 0; $batch < $totalBatches; $batch++) {
                $startIdx = $batch * $batchSize;
                $fileIdsBatch = array_slice($fileIds, $startIdx, $batchSize);

                $qb = $this->db->getQueryBuilder();
                $qb->delete('metavox_file_gf_meta')
                    ->where($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($groupfolderId)))
                    ->andWhere($qb->expr()->in('file_id', $qb->createNamedParameter($fileIdsBatch, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY)));
                $deleted = $qb->executeStatement();

                $this->filesDeleted += $deleted;

                if (($batch + 1) % 10 === 0 || ($batch + 1) === $totalBatches) {
                    $this->log("Progress: $this->filesDeleted metadata entries deleted");
                }
            }

            $this->log("File metadata cleaned up successfully");
        } catch (\Exception $e) {
            $this->log("Error cleaning up file metadata: " . $e->getMessage(), 'warning');
        }
    }

    /**
     * Delete team folder metadata from database
     */
    private function deleteTeamFolderMetadata(int $groupfolderId, array $fields): void {
        $this->log("Deleting team folder metadata for " . count($fields) . " fields...");

        try {
            foreach ($fields as $field) {
                $qb = $this->db->getQueryBuilder();
                $qb->delete('metavox_gf_metadata')
                    ->where($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($groupfolderId)))
                    ->andWhere($qb->expr()->eq('field_name', $qb->createNamedParameter($field['field_name'])));
                $qb->executeStatement();
            }

            $this->log("Team folder metadata cleaned up successfully");
        } catch (\Exception $e) {
            $this->log("Error cleaning up team folder metadata: " . $e->getMessage(), 'warning');
        }
    }

    /**
     * Delete fields from database
     */
    private function deleteFields(array $fieldIds): void {
        $this->log("Deleting " . count($fieldIds) . " test fields...");

        foreach ($fieldIds as $fieldId) {
            try {
                $this->fieldService->deleteField($fieldId);
                $this->fieldsDeleted++;
            } catch (\Exception $e) {
                $this->log("Error deleting field $fieldId: " . $e->getMessage(), 'warning');
            }
        }

        $this->log("Test fields cleaned up successfully");
    }

    /**
     * Print cleanup summary
     */
    private function printSummary(): void {
        $this->log("\n=== Cleanup Summary ===");
        $this->log("Datasets deleted: $this->datasetsDeleted");
        $this->log("File metadata entries deleted: $this->filesDeleted");
        $this->log("Fields deleted: $this->fieldsDeleted");
        $this->log("\nCleanup completed successfully!");
    }
}
