<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Performance;

/**
 * Filecache integration tests
 *
 * KRITIEKE TEST: Wat gebeurt er met MetaVox metadata wanneer:
 * 1. occ files:scan --all wordt uitgevoerd (file IDs kunnen veranderen!)
 * 2. Files worden verplaatst/gekopieerd
 * 3. Files worden verwijderd
 * 4. Groupfolders worden gescand
 *
 * Deze test simuleert de effecten van filecache operaties en meet:
 * - Orphaned metadata detectie
 * - Metadata preservering bij file operaties
 * - Performance impact van cleanup jobs
 * - Recovery strategieën
 */
class FileCacheIntegrationTest extends PerformanceTestBase {

    private array $testFiles = [];
    private array $testFields = [];
    private array $orphanedMetadata = [];

    public function run(): void {
        $this->log("=== FileCacheIntegration Performance Tests ===");
        $this->log("WAARSCHUWING: Deze test simuleert file operaties die metadata kunnen beïnvloeden");

        $this->loadTestData();
        $this->testFileCopyMetadataPreservation();
        $this->testFileDeleteOrphanedMetadata();
        $this->testOrphanedMetadataDetection();
        $this->testCleanupPerformance();
        $this->testFileScanImpact();

        $this->saveMetrics('filecache_integration_' . date('Y-m-d_His') . '.json');
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

        $user = $this->userSession->getUser();
        if ($user) {
            $userFolder = $this->rootFolder->getUserFolder($user->getUID());
            try {
                $testFolder = $userFolder->get('metavox_performance_test');
                $files = array_slice($testFolder->getDirectoryListing(), 0, 50);

                foreach ($files as $file) {
                    $this->testFiles[] = [
                        'id' => $file->getId(),
                        'name' => $file->getName(),
                        'node' => $file,
                    ];
                }
            } catch (\Exception $e) {
                $this->log("Could not load test files: " . $e->getMessage(), 'warning');
            }
        }

        $this->log("Loaded " . count($this->testFields) . " fields, " . count($this->testFiles) . " files");
    }

    /**
     * Test metadata preservering bij file copy
     */
    private function testFileCopyMetadataPreservation(): void {
        $this->log("\n--- Testing file copy metadata preservation ---");

        if (empty($this->testFiles) || empty($this->testFields)) {
            $this->log("No test data available", 'warning');
            return;
        }

        $sourceFile = $this->testFiles[0];
        $sourceFileId = (int) $sourceFile['id'];

        // Zet metadata op source file
        $this->log("Setting metadata on source file...");
        foreach ($this->testFields as $field) {
            $this->fieldService->saveFieldValue(
                $sourceFileId,
                (int) $field['id'],
                "copy_test_value"
            );
        }

        // Get source metadata
        $sourceMetadata = $this->fieldService->getFieldMetadata($sourceFileId);
        $this->log("Source file has " . count($sourceMetadata) . " metadata records");

        // Copy file via Nextcloud
        $user = $this->userSession->getUser();
        $userFolder = $this->rootFolder->getUserFolder($user->getUID());

        $stats = $this->measure(
            function() use ($sourceFile, $userFolder, &$copiedFile) {
                $testFolder = $userFolder->get('metavox_performance_test');
                $copiedFile = $sourceFile['node']->copy($testFolder->getPath() . '/copied_' . $sourceFile['name']);
                return $copiedFile;
            },
            'file_copy_operation',
            ['source_file' => $sourceFile['name']]
        );

        if ($stats && isset($copiedFile)) {
            $copiedFileId = $copiedFile->getId();

            // Check of metadata is gekopieerd (via FileCopyListener)
            $copiedMetadata = $this->fieldService->getFieldMetadata($copiedFileId);

            $this->metrics[] = [
                'name' => 'file_copy_metadata_preservation',
                'source_file_id' => $sourceFileId,
                'copied_file_id' => $copiedFileId,
                'source_metadata_count' => count($sourceMetadata),
                'copied_metadata_count' => count($copiedMetadata),
                'metadata_preserved' => count($copiedMetadata) === count($sourceMetadata),
                'timestamp' => date('Y-m-d H:i:s'),
            ];

            if (count($copiedMetadata) === count($sourceMetadata)) {
                $this->log("✓ Metadata successfully preserved on copy", 'info');
            } else {
                $this->log("✗ Metadata NOT preserved (expected: " . count($sourceMetadata) . ", got: " . count($copiedMetadata) . ")", 'error');
            }

            // Cleanup: verwijder copied file
            try {
                $copiedFile->delete();
            } catch (\Exception $e) {
                // Ignore
            }
        }
    }

    /**
     * Test orphaned metadata bij file delete
     */
    private function testFileDeleteOrphanedMetadata(): void {
        $this->log("\n--- Testing file delete orphaned metadata ---");

        if (empty($this->testFiles) || empty($this->testFields)) {
            $this->log("No test data available", 'warning');
            return;
        }

        // Maak een tijdelijk test bestand
        $user = $this->userSession->getUser();
        $userFolder = $this->rootFolder->getUserFolder($user->getUID());
        $testFolder = $userFolder->get('metavox_performance_test');

        $tempFile = $testFolder->newFile('temp_delete_test.txt', 'test content');
        $tempFileId = $tempFile->getId();

        // Voeg metadata toe
        $this->log("Adding metadata to temporary file...");
        foreach ($this->testFields as $field) {
            $this->fieldService->saveFieldValue(
                $tempFileId,
                (int) $field['id'],
                "delete_test_value"
            );
        }

        $metadataBefore = $this->fieldService->getFieldMetadata($tempFileId);
        $this->log("File has " . count($metadataBefore) . " metadata records before delete");

        // Delete file
        $stats = $this->measure(
            fn() => $tempFile->delete(),
            'file_delete_operation',
            ['file_id' => $tempFileId]
        );

        // Check of metadata nog bestaat (zou niet moeten!)
        // In de echte implementatie triggert FileDeleteListener een background job
        // Die background job verwijdert de metadata asynchroon

        $this->log("File deleted. Background cleanup job would remove metadata.");

        $this->metrics[] = [
            'name' => 'file_delete_orphaned_metadata',
            'file_id' => $tempFileId,
            'metadata_count_before_delete' => count($metadataBefore),
            'note' => 'CleanupDeletedMetadata background job scheduled',
            'timestamp' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Test detectie van orphaned metadata
     *
     * Dit is een KRITIEKE functie: als file IDs veranderen door occ files:scan,
     * dan kunnen metadata records "orphaned" raken.
     */
    private function testOrphanedMetadataDetection(): void {
        $this->log("\n--- Testing orphaned metadata detection ---");

        // Query om orphaned metadata te vinden:
        // Metadata records waar de file_id niet meer bestaat in de filecache

        $stats = $this->measure(
            function() {
                $qb = $this->db->getQueryBuilder();

                // Check metavox_metadata table
                $qb->select('m.id', 'm.file_id', 'm.field_id')
                    ->from('metavox_metadata', 'm')
                    ->leftJoin('m', 'filecache', 'f', 'm.file_id = f.fileid')
                    ->where('f.fileid IS NULL')
                    ->setMaxResults(100);

                $result = $qb->executeQuery();
                $orphaned = $result->fetchAll();
                $result->closeCursor();

                return $orphaned;
            },
            'orphaned_metadata_detection_legacy',
            []
        );

        if ($stats) {
            $this->orphanedMetadata = $stats;
            $orphanedCount = is_array($stats) ? count($stats) : 0;

            $this->log("Found $orphanedCount orphaned metadata records in legacy table");

            if ($orphanedCount > 0) {
                $this->log("WARNING: Orphaned metadata detected! File IDs may have changed.", 'warning');
                $this->log("This can happen after: occ files:scan --all", 'warning');
            }
        }

        // Check ook metavox_file_gf_meta table
        $statsGf = $this->measure(
            function() {
                $qb = $this->db->getQueryBuilder();

                $qb->select('m.id', 'm.file_id', 'm.groupfolder_id', 'm.field_name')
                    ->from('metavox_file_gf_meta', 'm')
                    ->leftJoin('m', 'filecache', 'f', 'm.file_id = f.fileid')
                    ->where('f.fileid IS NULL')
                    ->setMaxResults(100);

                $result = $qb->executeQuery();
                $orphaned = $result->fetchAll();
                $result->closeCursor();

                return $orphaned;
            },
            'orphaned_metadata_detection_groupfolder',
            []
        );

        if ($statsGf) {
            $orphanedCountGf = is_array($statsGf) ? count($statsGf) : 0;
            $this->log("Found $orphanedCountGf orphaned metadata records in groupfolder table");
        }
    }

    /**
     * Test cleanup performance
     */
    private function testCleanupPerformance(): void {
        $this->log("\n--- Testing cleanup performance ---");

        if (empty($this->orphanedMetadata)) {
            $this->log("No orphaned metadata to clean up");
            return;
        }

        $orphanedIds = array_column($this->orphanedMetadata, 'id');

        $stats = $this->measure(
            function() use ($orphanedIds) {
                $qb = $this->db->getQueryBuilder();
                $qb->delete('metavox_metadata')
                    ->where($qb->expr()->in('id', $qb->createNamedParameter($orphanedIds, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY)));

                return $qb->executeStatement();
            },
            'cleanup_orphaned_metadata',
            ['orphaned_count' => count($orphanedIds)]
        );

        $this->log("Cleaned up " . count($orphanedIds) . " orphaned records");
    }

    /**
     * Test impact van occ files:scan simulatie
     *
     * BELANGRIJK: We kunnen occ files:scan niet echt uitvoeren in deze test,
     * maar we kunnen wel de effecten simuleren en meten
     */
    private function testFileScanImpact(): void {
        $this->log("\n--- Testing file scan impact ---");

        $this->log("CRITICAL WARNING:");
        $this->log("occ files:scan --all kan file IDs veranderen!");
        $this->log("Dit leidt tot orphaned metadata.");
        $this->log("");
        $this->log("AANBEVOLEN WORKFLOW:");
        $this->log("1. Maak database backup VOOR files:scan");
        $this->log("2. Export alle metadata naar JSON");
        $this->log("3. Run occ files:scan --all");
        $this->log("4. Detecteer orphaned metadata");
        $this->log("5. Re-map metadata op basis van file paths (niet IDs)");
        $this->log("6. Run cleanup voor orphaned records");
        $this->log("");

        // Simulatie: hoeveel metadata records zouden we moeten re-mappen?
        $totalMetadataCount = $this->measure(
            function() {
                $qb = $this->db->getQueryBuilder();
                $qb->select($qb->func()->count('*'))
                    ->from('metavox_metadata');

                return (int) $qb->executeQuery()->fetchOne();
            },
            'count_total_metadata',
            []
        );

        $totalGfMetadataCount = $this->measure(
            function() {
                $qb = $this->db->getQueryBuilder();
                $qb->func()->count('*')
                    ->from('metavox_file_gf_meta');

                return (int) $qb->executeQuery()->fetchOne();
            },
            'count_total_gf_metadata',
            []
        );

        $this->metrics[] = [
            'name' => 'file_scan_impact_analysis',
            'total_metadata_records' => $totalMetadataCount,
            'total_gf_metadata_records' => $totalGfMetadataCount,
            'total_records_at_risk' => $totalMetadataCount + $totalGfMetadataCount,
            'recommendation' => 'Always backup before occ files:scan',
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        $totalAtRisk = $totalMetadataCount + $totalGfMetadataCount;
        $this->log(sprintf(
            "Total metadata records at risk during files:scan: %s",
            number_format($totalAtRisk)
        ));

        if ($totalAtRisk > 1000000) {
            $this->log("WARNING: >1M metadata records. Backup is CRITICAL before files:scan!", 'error');
        }
    }

    /**
     * Print resultaten met aanbevelingen
     */
    private function printResults(): void {
        $this->log("\n=== FileCacheIntegration Test Results ===");

        $summary = $this->calculateSummary();

        $this->log(sprintf("Total tests: %d", $summary['total_tests']));
        $this->log(sprintf("Successful: %d", $summary['successful_tests']));
        $this->log(sprintf("Failed: %d", $summary['failed_tests']));

        $this->log("\n=== RECOMMENDATIONS ===");
        $this->log("1. Implement metadata backup before files:scan");
        $this->log("2. Add metadata export/import based on file paths");
        $this->log("3. Add automated orphaned metadata cleanup job");
        $this->log("4. Monitor orphaned metadata count in admin dashboard");
        $this->log("5. Consider using stable file identifiers (checksums)");

        $this->log("\nSee PERFORMANCE_TEST_SUMMARY.md for detailed mitigation strategies");
    }
}
