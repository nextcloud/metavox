<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Performance;

use OCA\MetaVox\Service\FieldService;
use OCP\Files\IRootFolder;
use OCP\Files\File;
use OCP\Files\Folder;

/**
 * Genereert test data VIA de FieldService (niet direct DB!)
 *
 * Dit zorgt ervoor dat:
 * - Alle business logic correct wordt uitgevoerd
 * - Caches correct worden opgebouwd
 * - Event listeners worden getriggered
 * - Background jobs worden aangemaakt waar nodig
 */
class DataGenerator extends PerformanceTestBase {

    private array $createdFields = [];
    private array $createdFiles = [];

    /**
     * Hoofdmethode voor data generatie
     */
    public function run(): void {
        $this->log("=== MetaVox Performance Test Data Generator ===");
        $this->log("Target: {$this->config['max_records']} metadata records");

        // Stap 1: Maak field definities
        $this->createFieldDefinitions();

        // Stap 2: Maak test files in Nextcloud
        $this->createTestFiles();

        // Stap 3: Genereer metadata voor alle combinaties
        $this->generateMetadata();

        // Stap 4: Rapport
        $this->saveMetrics('data_generation_' . date('Y-m-d_His') . '.json');
        $this->printSummary();
    }

    /**
     * Maak verschillende field types via FieldService
     */
    private function createFieldDefinitions(): void {
        $this->log("Creating field definitions...");

        $fieldTypes = [
            [
                'field_name' => 'perf_test_title',
                'field_label' => 'Title',
                'field_type' => 'text',
                'field_description' => 'Document title',
                'is_required' => true,
                'field_options' => [],
            ],
            [
                'field_name' => 'perf_test_description',
                'field_label' => 'Description',
                'field_type' => 'textarea',
                'field_description' => 'Document description',
                'is_required' => false,
                'field_options' => [],
            ],
            [
                'field_name' => 'perf_test_category',
                'field_label' => 'Category',
                'field_type' => 'select',
                'field_description' => 'Document category',
                'is_required' => false,
                'field_options' => [
                    'financial',
                    'hr',
                    'legal',
                    'marketing',
                    'sales',
                    'support',
                    'technical',
                    'other',
                ],
            ],
            [
                'field_name' => 'perf_test_status',
                'field_label' => 'Status',
                'field_type' => 'select',
                'field_description' => 'Document status',
                'is_required' => true,
                'field_options' => [
                    'draft',
                    'review',
                    'approved',
                    'archived',
                ],
            ],
            [
                'field_name' => 'perf_test_priority',
                'field_label' => 'Priority',
                'field_type' => 'number',
                'field_description' => 'Priority level (1-5)',
                'is_required' => false,
                'field_options' => [],
            ],
            [
                'field_name' => 'perf_test_created_date',
                'field_label' => 'Created Date',
                'field_type' => 'date',
                'field_description' => 'Creation date',
                'is_required' => false,
                'field_options' => [],
            ],
            [
                'field_name' => 'perf_test_due_date',
                'field_label' => 'Due Date',
                'field_type' => 'date',
                'field_description' => 'Due date',
                'is_required' => false,
                'field_options' => [],
            ],
            [
                'field_name' => 'perf_test_archived',
                'field_label' => 'Archived',
                'field_type' => 'checkbox',
                'field_description' => 'Is archived',
                'is_required' => false,
                'field_options' => [],
            ],
            [
                'field_name' => 'perf_test_tags',
                'field_label' => 'Tags',
                'field_type' => 'multiselect',
                'field_description' => 'Document tags',
                'is_required' => false,
                'field_options' => [
                    'urgent',
                    'confidential',
                    'public',
                    'internal',
                    'external',
                    'review-needed',
                    'approved',
                ],
            ],
            [
                'field_name' => 'perf_test_department',
                'field_label' => 'Department',
                'field_type' => 'select',
                'field_description' => 'Owning department',
                'is_required' => false,
                'field_options' => [
                    'engineering',
                    'finance',
                    'hr',
                    'legal',
                    'marketing',
                    'operations',
                    'sales',
                ],
            ],
        ];

        foreach ($fieldTypes as $fieldData) {
            // Add scope to fieldData
            $fieldData['scope'] = 'global';

            $fieldId = $this->measure(
                fn() => $this->fieldService->createField($fieldData),
                'create_field',
                ['field_name' => $fieldData['field_name']]
            );

            $this->createdFields[] = [
                'id' => $fieldId,
                'field_name' => $fieldData['field_name'],
                'field_type' => $fieldData['field_type'],
                'field_options' => $fieldData['field_options'],
            ];
            $this->log("Created field: {$fieldData['field_name']} (ID: {$fieldId})");
        }

        $this->log("Created " . count($this->createdFields) . " field definitions");
    }

    /**
     * Maak test files in Nextcloud file system
     *
     * BELANGRIJK: We maken echte files aan via Nextcloud IRootFolder,
     * zodat de filecache correct wordt gevuld
     */
    private function createTestFiles(): void {
        $this->log("Creating test files in Nextcloud...");

        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \RuntimeException("No user logged in for test file creation");
        }

        $userFolder = $this->rootFolder->getUserFolder($user->getUID());

        // Maak test folder aan
        try {
            $testFolder = $userFolder->newFolder('metavox_performance_test');
        } catch (\Exception $e) {
            // Folder bestaat al
            $testFolder = $userFolder->get('metavox_performance_test');
        }

        // Bereken hoeveel files we nodig hebben
        $fieldsPerFile = count($this->createdFields);
        $targetMetadataRecords = $this->config['max_records'];
        $targetFiles = (int) ceil($targetMetadataRecords / $fieldsPerFile);

        $this->log("Creating $targetFiles files ($fieldsPerFile fields per file = $targetMetadataRecords total records)");

        $batchSize = $this->config['batch_size'];
        $batches = (int) ceil($targetFiles / $batchSize);

        for ($batch = 0; $batch < $batches; $batch++) {
            $batchStart = $batch * $batchSize;
            $batchEnd = min($batchStart + $batchSize, $targetFiles);
            $batchCount = $batchEnd - $batchStart;

            $this->measure(
                function() use ($testFolder, $batchStart, $batchEnd) {
                    for ($i = $batchStart; $i < $batchEnd; $i++) {
                        $filename = sprintf('test_file_%08d.txt', $i);
                        $content = "Test file for MetaVox performance testing\n";
                        $content .= "File number: $i\n";
                        $content .= "Generated: " . date('Y-m-d H:i:s') . "\n";

                        try {
                            $file = $testFolder->newFile($filename, $content);
                            $this->createdFiles[] = [
                                'id' => $file->getId(),
                                'name' => $filename,
                                'path' => $file->getPath(),
                            ];
                        } catch (\Exception $e) {
                            // File bestaat al, gebruik bestaande
                            $file = $testFolder->get($filename);
                            $this->createdFiles[] = [
                                'id' => $file->getId(),
                                'name' => $filename,
                                'path' => $file->getPath(),
                            ];
                        }
                    }
                },
                'create_files_batch',
                [
                    'batch' => $batch + 1,
                    'batch_size' => $batchCount,
                    'total_files' => count($this->createdFiles),
                ]
            );

            $progress = (($batch + 1) / $batches) * 100;
            $this->log(sprintf(
                "Batch %d/%d completed (%.1f%%) - %d files created",
                $batch + 1,
                $batches,
                $progress,
                count($this->createdFiles)
            ));
        }

        $this->log("Created " . count($this->createdFiles) . " test files");
    }

    /**
     * Genereer metadata voor alle files via FieldService.saveFieldValue()
     *
     * Dit is de realistische manier: elke metadata waarde wordt via de service opgeslagen
     */
    private function generateMetadata(): void {
        $this->log("Generating metadata via FieldService...");

        $totalFiles = count($this->createdFiles);
        $totalFields = count($this->createdFields);
        $totalRecords = $totalFiles * $totalFields;

        $this->log("Files: $totalFiles, Fields: $totalFields, Total records: $totalRecords");

        $batchSize = $this->config['batch_size'];
        $batches = (int) ceil($totalFiles / $batchSize);

        $recordsCreated = 0;

        for ($batch = 0; $batch < $batches; $batch++) {
            $batchStart = $batch * $batchSize;
            $batchEnd = min($batchStart + $batchSize, $totalFiles);
            $batchFiles = array_slice($this->createdFiles, $batchStart, $batchEnd - $batchStart);

            $this->measure(
                function() use ($batchFiles, &$recordsCreated) {
                    foreach ($batchFiles as $file) {
                        foreach ($this->createdFields as $field) {
                            $value = $this->generateFieldValue($field);

                            // Via FieldService - dit triggert alle business logic!
                            $this->fieldService->saveFieldValue(
                                (int) $file['id'],
                                (int) $field['id'],
                                $value
                            );

                            $recordsCreated++;
                        }
                    }
                },
                'generate_metadata_batch',
                [
                    'batch' => $batch + 1,
                    'files_in_batch' => count($batchFiles),
                    'records_created' => $recordsCreated,
                ]
            );

            $progress = (($batch + 1) / $batches) * 100;
            $this->log(sprintf(
                "Batch %d/%d completed (%.1f%%) - %s records created",
                $batch + 1,
                $batches,
                $progress,
                number_format($recordsCreated)
            ));
        }

        $this->log("Generated " . number_format($recordsCreated) . " metadata records");
    }

    /**
     * Genereer realistische test waarde voor een field
     */
    private function generateFieldValue(array $field): string {
        switch ($field['field_type']) {
            case 'text':
                return $this->randomText();

            case 'textarea':
                return $this->randomParagraph();

            case 'number':
                return (string) rand(1, 100);

            case 'date':
                $timestamp = strtotime('-' . rand(0, 365) . ' days');
                return date('Y-m-d', $timestamp);

            case 'checkbox':
                return rand(0, 1) ? 'true' : 'false';

            case 'select':
                $options = is_array($field['field_options']) ? $field['field_options'] : json_decode($field['field_options'], true);
                return !empty($options) ? $options[array_rand($options)] : 'default';

            case 'multiselect':
                $options = is_array($field['field_options']) ? $field['field_options'] : json_decode($field['field_options'], true);
                if (empty($options)) {
                    return 'default';
                }
                $selected = array_rand($options, rand(1, min(3, count($options))));
                return is_array($selected)
                    ? implode(',', array_map(fn($i) => $options[$i], $selected))
                    : $options[$selected];

            default:
                return 'test_value';
        }
    }

    private function randomText(): string {
        $words = ['Project', 'Report', 'Document', 'Analysis', 'Proposal', 'Summary', 'Review'];
        return $words[array_rand($words)] . ' ' . rand(1000, 9999);
    }

    private function randomParagraph(): string {
        $sentences = [
            'This is a test document for performance testing.',
            'MetaVox handles large volumes of metadata efficiently.',
            'We are testing scalability with millions of records.',
            'The system should maintain good performance under load.',
        ];
        $selectedKeys = (array) array_rand(array_flip($sentences), rand(1, 3));
        $selected = array_map(fn($key) => $sentences[$key] ?? $sentences[array_keys($sentences)[$key]], $selectedKeys);
        return implode(' ', $selected);
    }

    /**
     * Print samenvatting van gegenereerde data
     */
    private function printSummary(): void {
        $this->log("\n=== Data Generation Summary ===");
        $this->log("Fields created: " . count($this->createdFields));
        $this->log("Files created: " . number_format(count($this->createdFiles)));

        $totalRecords = count($this->createdFiles) * count($this->createdFields);
        $this->log("Total metadata records: " . number_format($totalRecords));

        $summary = $this->calculateSummary();
        $this->log(sprintf(
            "Total duration: %.2f minutes",
            $summary['total_duration_minutes']
        ));

        $this->log("\nData generation complete!");
        $this->log("You can now run performance tests with: php tests/Performance/run_tests.php");
    }

    /**
     * Cleanup: verwijder alle gegenereerde test data
     */
    public function cleanup(): void {
        $this->log("Cleaning up test data...");

        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \RuntimeException("No user logged in for cleanup");
        }

        $userFolder = $this->rootFolder->getUserFolder($user->getUID());

        // Verwijder test folder
        try {
            $testFolder = $userFolder->get('metavox_performance_test');
            $testFolder->delete();
            $this->log("Deleted test folder with files");
        } catch (\Exception $e) {
            $this->log("Could not delete test folder: " . $e->getMessage(), 'warning');
        }

        // Verwijder ALLE perf_test_ fields (niet alleen uit $createdFields array)
        // Dit lost het probleem op dat fields blijven bestaan tussen test runs
        $this->log("Deleting all perf_test_* fields...");

        try {
            $allFields = $this->fieldService->getAllFields();
            $deletedCount = 0;

            foreach ($allFields as $field) {
                if (str_starts_with($field['field_name'], 'perf_test_')) {
                    try {
                        $this->fieldService->deleteField((int) $field['id']);
                        $deletedCount++;
                    } catch (\Exception $e) {
                        $this->log("Could not delete field {$field['field_name']}: " . $e->getMessage(), 'warning');
                    }
                }
            }

            $this->log("Deleted $deletedCount performance test fields");
        } catch (\Exception $e) {
            $this->log("Error during field cleanup: " . $e->getMessage(), 'error');
        }

        $this->log("Cleanup complete!");
    }
}
