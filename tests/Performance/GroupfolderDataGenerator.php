<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Performance;

use OCP\IDBConnection;

/**
 * Genereert GROUPFOLDER test data via directe DB injectie (snel!)
 *
 * Aanpak:
 * 1. Inject dummy groupfolder + fields + files direct in DB (SNEL)
 * 2. Test daarna VIA services (REALISTISCH)
 *
 * Dit test de ECHTE productie scenario's:
 * - Groupfolder metadata (oc_metavox_gf_metadata)
 * - File metadata binnen groupfolders (oc_metavox_file_gf_meta)
 */
class GroupfolderDataGenerator extends PerformanceTestBase {

    private int $testGroupfolderId = 999999;
    private array $createdFieldIds = [];
    private array $createdFileIds = [];

    /**
     * Hoofdmethode voor data generatie
     */
    public function run(): void {
        $this->log("=== MetaVox Groupfolder Performance Test Data Generator ===");
        $this->log("Target: {$this->config['max_records']} metadata records");
        $this->log("Strategy: Direct DB injection (fast) + Service testing (realistic)");

        // Stap 1: Maak groupfolder entry
        $this->createTestGroupfolder();

        // Stap 2: Maak field definities (groupfolder + file fields)
        $this->createGroupfolderFields();

        // Stap 3: Inject dummy file IDs (snel - geen echte files)
        $this->injectDummyFiles();

        // Stap 4: Inject groupfolder metadata
        $this->injectGroupfolderMetadata();

        // Stap 5: Inject file metadata
        $this->injectFileMetadata();

        // Stap 6: Rapport
        $this->saveMetrics('gf_data_generation_' . date('Y-m-d_His') . '.json');
        $this->printSummary();
    }

    /**
     * Maak een test groupfolder entry
     */
    private function createTestGroupfolder(): void {
        $this->log("Creating test groupfolder...");

        $result = $this->measure(function() {
            $qb = $this->db->getQueryBuilder();

            // Check if groupfolder already exists
            $qb->select('id')
                ->from('group_folders')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($this->testGroupfolderId)));

            $existing = $qb->executeQuery()->fetch();

            if ($existing) {
                $this->log("Test groupfolder already exists (ID: {$this->testGroupfolderId})");
                return;
            }

            // Insert test groupfolder
            $qb = $this->db->getQueryBuilder();
            $qb->insert('group_folders')
                ->values([
                    'id' => $qb->createNamedParameter($this->testGroupfolderId),
                    'mount_point' => $qb->createNamedParameter('MetaVox_Perf_Test'),
                    'quota' => $qb->createNamedParameter(-3),
                    'acl' => $qb->createNamedParameter(0),
                ])
                ->executeStatement();

            $this->log("Created test groupfolder: MetaVox_Perf_Test (ID: {$this->testGroupfolderId})");
        }, 'create_groupfolder');
    }

    /**
     * Maak groupfolder fields (applies_to_groupfolder = 0 en 1)
     */
    private function createGroupfolderFields(): void {
        $this->log("Creating groupfolder field definitions...");

        $fields = [
            // Groupfolder-level fields (applies_to_groupfolder = 1)
            [
                'field_name' => 'gf_perf_team_name',
                'field_label' => 'Team Name',
                'field_type' => 'text',
                'field_description' => 'Name of the team',
                'applies_to_groupfolder' => 1,
                'field_options' => '[]',
            ],
            [
                'field_name' => 'gf_perf_department',
                'field_label' => 'Department',
                'field_type' => 'select',
                'field_description' => 'Department',
                'applies_to_groupfolder' => 1,
                'field_options' => json_encode(['engineering', 'finance', 'hr', 'legal', 'marketing']),
            ],

            // File-level fields (applies_to_groupfolder = 0)
            [
                'field_name' => 'gf_perf_title',
                'field_label' => 'Title',
                'field_type' => 'text',
                'field_description' => 'Document title',
                'applies_to_groupfolder' => 0,
                'field_options' => '[]',
            ],
            [
                'field_name' => 'gf_perf_status',
                'field_label' => 'Status',
                'field_type' => 'select',
                'field_description' => 'Document status',
                'applies_to_groupfolder' => 0,
                'field_options' => json_encode(['draft', 'review', 'approved', 'archived']),
            ],
            [
                'field_name' => 'gf_perf_category',
                'field_label' => 'Category',
                'field_type' => 'select',
                'field_description' => 'Document category',
                'applies_to_groupfolder' => 0,
                'field_options' => json_encode(['financial', 'hr', 'legal', 'technical', 'other']),
            ],
            [
                'field_name' => 'gf_perf_priority',
                'field_label' => 'Priority',
                'field_type' => 'number',
                'field_description' => 'Priority (1-5)',
                'applies_to_groupfolder' => 0,
                'field_options' => '[]',
            ],
            [
                'field_name' => 'gf_perf_tags',
                'field_label' => 'Tags',
                'field_type' => 'multiselect',
                'field_description' => 'Document tags',
                'applies_to_groupfolder' => 0,
                'field_options' => json_encode(['urgent', 'confidential', 'public', 'internal']),
            ],
        ];

        foreach ($fields as $fieldData) {
            $this->measure(function() use ($fieldData) {
                $qb = $this->db->getQueryBuilder();

                // Check if field already exists
                $qb->select('id')
                    ->from('metavox_gf_fields')
                    ->where($qb->expr()->eq('field_name', $qb->createNamedParameter($fieldData['field_name'])));

                $existing = $qb->executeQuery()->fetch();

                if ($existing) {
                    $this->createdFieldIds[] = (int)$existing['id'];
                    return;
                }

                // Insert field
                $qb = $this->db->getQueryBuilder();
                $qb->insert('metavox_gf_fields')
                    ->values([
                        'field_name' => $qb->createNamedParameter($fieldData['field_name']),
                        'field_label' => $qb->createNamedParameter($fieldData['field_label']),
                        'field_type' => $qb->createNamedParameter($fieldData['field_type']),
                        'field_description' => $qb->createNamedParameter($fieldData['field_description']),
                        'applies_to_groupfolder' => $qb->createNamedParameter($fieldData['applies_to_groupfolder']),
                        'field_options' => $qb->createNamedParameter($fieldData['field_options']),
                        'is_required' => $qb->createNamedParameter(0),
                    ])
                    ->executeStatement();

                $this->createdFieldIds[] = (int)$qb->getLastInsertId();
            }, 'create_field', ['field_name' => $fieldData['field_name']]);
        }

        // Assign fields to groupfolder
        foreach ($this->createdFieldIds as $fieldId) {
            $qb = $this->db->getQueryBuilder();

            // Check if already assigned
            $qb->select('field_id')
                ->from('metavox_gf_assigns')
                ->where($qb->expr()->eq('field_id', $qb->createNamedParameter($fieldId)))
                ->andWhere($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($this->testGroupfolderId)));

            if ($qb->executeQuery()->fetch()) {
                continue;
            }

            // Assign field to groupfolder
            $qb = $this->db->getQueryBuilder();
            $qb->insert('metavox_gf_assigns')
                ->values([
                    'field_id' => $qb->createNamedParameter($fieldId),
                    'groupfolder_id' => $qb->createNamedParameter($this->testGroupfolderId),
                ])
                ->executeStatement();
        }

        $this->log("Created " . count($this->createdFieldIds) . " groupfolder field definitions");
    }

    /**
     * Inject dummy file IDs (snel - GEEN echte files aanmaken!)
     */
    private function injectDummyFiles(): void {
        $this->log("Injecting dummy file IDs...");

        $targetFiles = (int)($this->config['max_records'] / 5); // 5 file fields per file
        $batchSize = 1000;

        $this->log("Target: {$targetFiles} dummy files");

        $startFileId = 20000000; // Start hoog om conflicten te vermijden

        for ($i = 0; $i < $targetFiles; $i++) {
            $fileId = $startFileId + $i;
            $this->createdFileIds[] = $fileId;
        }

        $this->log("Generated {$targetFiles} dummy file IDs ({$startFileId} - " . ($startFileId + $targetFiles - 1) . ")");
    }

    /**
     * Inject groupfolder metadata (applies_to_groupfolder = 1 fields)
     */
    private function injectGroupfolderMetadata(): void {
        $this->log("Injecting groupfolder metadata...");

        // Get groupfolder fields only
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'field_name', 'field_type', 'field_options')
            ->from('metavox_gf_fields')
            ->where($qb->expr()->eq('applies_to_groupfolder', $qb->createNamedParameter(1)));

        $gfFields = $qb->executeQuery()->fetchAll();

        if (empty($gfFields)) {
            $this->log("No groupfolder fields found");
            return;
        }

        $timestamp = date('Y-m-d H:i:s');

        foreach ($gfFields as $field) {
            $value = $this->generateFieldValue($field);

            $this->measure(function() use ($field, $value, $timestamp) {
                $qb = $this->db->getQueryBuilder();

                // Delete existing
                $qb->delete('metavox_gf_metadata')
                    ->where($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($this->testGroupfolderId)))
                    ->andWhere($qb->expr()->eq('field_name', $qb->createNamedParameter($field['field_name'])))
                    ->executeStatement();

                // Insert new
                $qb = $this->db->getQueryBuilder();
                $qb->insert('metavox_gf_metadata')
                    ->values([
                        'groupfolder_id' => $qb->createNamedParameter($this->testGroupfolderId),
                        'field_name' => $qb->createNamedParameter($field['field_name']),
                        'field_value' => $qb->createNamedParameter($value),
                        'created_at' => $qb->createNamedParameter($timestamp),
                        'updated_at' => $qb->createNamedParameter($timestamp),
                    ])
                    ->executeStatement();
            }, 'inject_gf_metadata', ['field' => $field['field_name']]);
        }

        $this->log("Injected metadata for " . count($gfFields) . " groupfolder fields");
    }

    /**
     * Inject file metadata (applies_to_groupfolder = 0 fields)
     */
    private function injectFileMetadata(): void {
        $this->log("Injecting file metadata...");

        // Get file fields only
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'field_name', 'field_type', 'field_options')
            ->from('metavox_gf_fields')
            ->where($qb->expr()->eq('applies_to_groupfolder', $qb->createNamedParameter(0)));

        $fileFields = $qb->executeQuery()->fetchAll();

        if (empty($fileFields)) {
            $this->log("No file fields found");
            return;
        }

        $totalRecords = count($this->createdFileIds) * count($fileFields);
        $this->log("Injecting {$totalRecords} file metadata records...");

        $batchSize = 1000;
        $inserted = 0;
        $startTime = microtime(true);

        $this->db->beginTransaction();

        try {
            $timestamp = date('Y-m-d H:i:s');

            foreach ($this->createdFileIds as $idx => $fileId) {
                foreach ($fileFields as $field) {
                    $value = $this->generateFieldValue($field);

                    $qb = $this->db->getQueryBuilder();
                    $qb->insert('metavox_file_gf_meta')
                        ->values([
                            'groupfolder_id' => $qb->createNamedParameter($this->testGroupfolderId),
                            'file_id' => $qb->createNamedParameter($fileId),
                            'field_name' => $qb->createNamedParameter($field['field_name']),
                            'field_value' => $qb->createNamedParameter($value),
                            'created_at' => $qb->createNamedParameter($timestamp),
                            'updated_at' => $qb->createNamedParameter($timestamp),
                        ])
                        ->executeStatement();

                    $inserted++;

                    // Commit batch
                    if ($inserted % $batchSize === 0) {
                        $this->db->commit();
                        $this->db->beginTransaction();

                        $elapsed = microtime(true) - $startTime;
                        $rate = $inserted / $elapsed;
                        $remaining = $totalRecords - $inserted;
                        $eta = $remaining / $rate;

                        $this->log(sprintf(
                            "Progress: %d/%d (%.1f%%) - %.0f records/sec - ETA: %.0fs",
                            $inserted,
                            $totalRecords,
                            ($inserted / $totalRecords) * 100,
                            $rate,
                            $eta
                        ));
                    }
                }
            }

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }

        $duration = microtime(true) - $startTime;
        $this->log(sprintf(
            "Injected %d file metadata records in %.2fs (%.0f records/sec)",
            $inserted,
            $duration,
            $inserted / $duration
        ));
    }

    /**
     * Genereer realistic field value
     */
    private function generateFieldValue(array $field): string {
        // Handle field_options - could be JSON string or array
        $options = [];
        if (!empty($field['field_options'])) {
            if (is_string($field['field_options'])) {
                $decoded = json_decode($field['field_options'], true);
                $options = is_array($decoded) ? $decoded : [];
            } elseif (is_array($field['field_options'])) {
                $options = $field['field_options'];
            }
        }

        switch ($field['field_type']) {
            case 'text':
            case 'textarea':
                return 'Test value ' . bin2hex(random_bytes(4));

            case 'select':
                if (!empty($options) && is_array($options)) {
                    return $options[array_rand($options)];
                }
                return 'default';

            case 'multiselect':
                if (empty($options) || !is_array($options)) return '';
                $count = rand(1, min(3, count($options)));
                $selected = array_rand(array_flip($options), $count);
                return is_array($selected) ? implode(';#', $selected) : $selected;

            case 'number':
                return (string)rand(1, 100);

            case 'date':
                return date('Y-m-d', strtotime('-' . rand(0, 365) . ' days'));

            case 'checkbox':
                return (string)rand(0, 1);

            default:
                return 'test';
        }
    }

    /**
     * Cleanup test data
     */
    public function cleanup(): void {
        $this->log("Cleaning up groupfolder test data...");

        // Delete file metadata
        $qb = $this->db->getQueryBuilder();
        $qb->delete('metavox_file_gf_meta')
            ->where($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($this->testGroupfolderId)))
            ->executeStatement();

        // Delete groupfolder metadata
        $qb = $this->db->getQueryBuilder();
        $qb->delete('metavox_gf_metadata')
            ->where($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($this->testGroupfolderId)))
            ->executeStatement();

        // Delete field assignments
        $qb = $this->db->getQueryBuilder();
        $qb->delete('metavox_gf_assigns')
            ->where($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($this->testGroupfolderId)))
            ->executeStatement();

        // Delete fields
        foreach ($this->createdFieldIds as $fieldId) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete('metavox_gf_fields')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($fieldId)))
                ->executeStatement();
        }

        // Delete groupfolder
        $qb = $this->db->getQueryBuilder();
        $qb->delete('group_folders')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($this->testGroupfolderId)))
            ->executeStatement();

        $this->log("Cleanup complete!");
    }

    /**
     * Print summary
     */
    private function printSummary(): void {
        $this->log("\n=== Data Generation Summary ===");
        $this->log("Groupfolder ID: {$this->testGroupfolderId}");
        $this->log("Fields created: " . count($this->createdFieldIds));
        $this->log("Dummy files: " . count($this->createdFileIds));
        $this->log("Total metadata records: " . (count($this->createdFileIds) * 5));
    }
}
