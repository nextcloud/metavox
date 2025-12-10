<?php

declare(strict_types=1);

namespace OCA\metavox\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Fix: Convert remaining comma-separated multiselect values to semicolon with space separated
 * This migration handles cases that were missed in Version20250101000008
 */
class Version20250101000009 extends SimpleMigrationStep {

    public function __construct(
        private IDBConnection $db,
    ) {}

    /**
     * @param IOutput $output
     * @param \Closure $schemaClosure
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options) {
        // No schema changes needed for value conversion
        return $schemaClosure();
    }

    /**
     * @param IOutput $output
     * @param \Closure $schemaClosure
     * @param array $options
     */
    public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void {
        try {
            $output->info('Converting remaining comma-separated multiselect values to semicolon-separated...');

            // Get all multiselect field names from groupfolder fields
            $multiselectFieldNames = $this->getMultiselectFieldNames();
            $output->info('Found ' . count($multiselectFieldNames) . ' groupfolder multiselect fields');

            // Get all multiselect field IDs from global fields
            $multiselectFieldIds = $this->getMultiselectFieldIds();
            $output->info('Found ' . count($multiselectFieldIds) . ' global multiselect fields');

            $totalConverted = 0;

            // Convert metavox_metadata (global file metadata)
            if (count($multiselectFieldIds) > 0) {
                $count = $this->convertMetadataByFieldId($multiselectFieldIds);
                $output->info("Converted $count records in metavox_metadata");
                $totalConverted += $count;
            }

            // Convert metavox_gf_metadata (groupfolder metadata)
            if (count($multiselectFieldNames) > 0) {
                $count = $this->convertMetadataByFieldName('metavox_gf_metadata', 'field_value', $multiselectFieldNames);
                $output->info("Converted $count records in metavox_gf_metadata");
                $totalConverted += $count;
            }

            // Convert metavox_file_gf_meta (file metadata within groupfolders)
            if (count($multiselectFieldNames) > 0) {
                $count = $this->convertMetadataByFieldName('metavox_file_gf_meta', 'field_value', $multiselectFieldNames);
                $output->info("Converted $count records in metavox_file_gf_meta");
                $totalConverted += $count;
            }

            $output->info("Successfully converted $totalConverted total records from comma to semicolon");

        } catch (\Exception $e) {
            $output->warning('Error during conversion: ' . $e->getMessage());
            $output->warning('Stack trace: ' . $e->getTraceAsString());
        }
    }

    /**
     * Get IDs of all multiselect fields from metavox_fields
     */
    private function getMultiselectFieldIds(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
           ->from('metavox_fields')
           ->where($qb->expr()->eq('field_type', $qb->createNamedParameter('multiselect')));

        $result = $qb->executeQuery();
        $fieldIds = [];
        while ($row = $result->fetch()) {
            $fieldIds[] = (int)$row['id'];
        }
        $result->closeCursor();

        return $fieldIds;
    }

    /**
     * Get names of all multiselect fields from metavox_gf_fields
     */
    private function getMultiselectFieldNames(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('field_name')
           ->from('metavox_gf_fields')
           ->where($qb->expr()->eq('field_type', $qb->createNamedParameter('multiselect')));

        $result = $qb->executeQuery();
        $fieldNames = [];
        while ($row = $result->fetch()) {
            $fieldNames[] = $row['field_name'];
        }
        $result->closeCursor();

        return $fieldNames;
    }

    /**
     * Convert metadata values in metavox_metadata table (uses field_id)
     */
    private function convertMetadataByFieldId(array $fieldIds): int {
        if (empty($fieldIds)) {
            return 0;
        }

        $qb = $this->db->getQueryBuilder();

        // Select all multiselect values that contain commas
        $qb->select('id', 'value')
           ->from('metavox_metadata')
           ->where($qb->expr()->in('field_id', $qb->createNamedParameter($fieldIds, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY)))
           ->andWhere($qb->expr()->like('value', $qb->createNamedParameter('%,%')));

        $result = $qb->executeQuery();
        $rowsToUpdate = [];

        while ($row = $result->fetch()) {
            $rowsToUpdate[] = [
                'id' => (int)$row['id'],
                'new_value' => str_replace(',', ';#', $row['value'])
            ];
        }
        $result->closeCursor();

        // Update each row
        $count = 0;
        foreach ($rowsToUpdate as $update) {
            $qb = $this->db->getQueryBuilder();
            $qb->update('metavox_metadata')
               ->set('value', $qb->createNamedParameter($update['new_value']))
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($update['id'], \Doctrine\DBAL\ParameterType::INTEGER)));
            $count += $qb->executeStatement();
        }

        return $count;
    }

    /**
     * Convert metadata values by field name (for groupfolder tables)
     */
    private function convertMetadataByFieldName(string $tableName, string $columnName, array $fieldNames): int {
        if (empty($fieldNames)) {
            return 0;
        }

        $qb = $this->db->getQueryBuilder();

        // Select all multiselect values that contain commas
        $qb->select('id', $columnName)
           ->from($tableName)
           ->where($qb->expr()->in('field_name', $qb->createNamedParameter($fieldNames, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)))
           ->andWhere($qb->expr()->like($columnName, $qb->createNamedParameter('%,%')));

        $result = $qb->executeQuery();
        $rowsToUpdate = [];

        while ($row = $result->fetch()) {
            $rowsToUpdate[] = [
                'id' => (int)$row['id'],
                'new_value' => str_replace(',', ';#', $row[$columnName])
            ];
        }
        $result->closeCursor();

        // Update each row
        $count = 0;
        foreach ($rowsToUpdate as $update) {
            $qb = $this->db->getQueryBuilder();
            $qb->update($tableName)
               ->set($columnName, $qb->createNamedParameter($update['new_value']))
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($update['id'], \Doctrine\DBAL\ParameterType::INTEGER)));
            $count += $qb->executeStatement();
        }

        return $count;
    }
}
