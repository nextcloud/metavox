<?php

declare(strict_types=1);

namespace OCA\metavox\Migration;

use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Restore metadata from search index.
 *
 * A bug in CleanupDeletedMetadata::cleanupMovedFiles() incorrectly treated
 * all groupfolder files as "moved" because it expected filecache paths like
 * __groupfolders/{id}/... but groupfolder files are stored under files/...
 * on user-scoped storages. This caused all metadata to be deleted.
 *
 * The search index (metavox_search_index) still contains the original
 * field_data as JSON. This migration restores metadata from that source
 * for any files that are missing from metavox_file_gf_meta.
 */
class Version20250101000013 extends SimpleMigrationStep {

    public function __construct(
        private IDBConnection $db,
    ) {}

    public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void {
        // Build storage-to-groupfolder mapping
        // Groupfolder storages have id like: local::/path/__groupfolders/{gfId}/
        $qb = $this->db->getQueryBuilder();
        $qb->select('numeric_id', 'id')
           ->from('storages')
           ->where($qb->expr()->like('id', $qb->createNamedParameter('%__groupfolders/%')));

        $result = $qb->executeQuery();
        $storageToGf = [];
        while ($row = $result->fetch()) {
            // Extract groupfolder ID from storage id like "local::/path/__groupfolders/4788/"
            if (preg_match('/__groupfolders\/(\d+)\/?$/', $row['id'], $m)) {
                $storageToGf[(int)$row['numeric_id']] = (int)$m[1];
            }
        }
        $result->closeCursor();

        if (empty($storageToGf)) {
            $output->info('No groupfolder storages found, skipping restore.');
            return;
        }

        // Get all search index entries with field_data
        $qb = $this->db->getQueryBuilder();
        $qb->select('si.file_id', 'si.field_data', 'fc.storage')
           ->from('metavox_search_index', 'si')
           ->innerJoin('si', 'filecache', 'fc', $qb->expr()->eq('si.file_id', 'fc.fileid'))
           ->where($qb->expr()->isNotNull('si.field_data'));

        $result = $qb->executeQuery();
        $toRestore = [];
        while ($row = $result->fetch()) {
            $storageId = (int)$row['storage'];
            if (!isset($storageToGf[$storageId])) {
                continue;
            }
            $fieldData = json_decode($row['field_data'], true);
            if (empty($fieldData)) {
                continue;
            }
            $toRestore[] = [
                'file_id' => (int)$row['file_id'],
                'groupfolder_id' => $storageToGf[$storageId],
                'fields' => $fieldData,
            ];
        }
        $result->closeCursor();

        if (empty($toRestore)) {
            $output->info('No metadata to restore.');
            return;
        }

        $restored = 0;
        $skipped = 0;

        foreach ($toRestore as $entry) {
            foreach ($entry['fields'] as $fieldName => $fieldValue) {
                // Check if this entry already exists
                $qb = $this->db->getQueryBuilder();
                $qb->select($qb->func()->count('*', 'cnt'))
                   ->from('metavox_file_gf_meta')
                   ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($entry['file_id'])))
                   ->andWhere($qb->expr()->eq('field_name', $qb->createNamedParameter($fieldName)))
                   ->andWhere($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($entry['groupfolder_id'])));

                $count = (int)$qb->executeQuery()->fetchOne();

                if ($count > 0) {
                    $skipped++;
                    continue;
                }

                // Insert the restored entry
                $qb = $this->db->getQueryBuilder();
                $qb->insert('metavox_file_gf_meta')
                   ->values([
                       'file_id' => $qb->createNamedParameter($entry['file_id']),
                       'groupfolder_id' => $qb->createNamedParameter($entry['groupfolder_id']),
                       'field_name' => $qb->createNamedParameter($fieldName),
                       'field_value' => $qb->createNamedParameter($fieldValue ?? ''),
                       'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
                       'updated_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
                   ]);
                $qb->executeStatement();
                $restored++;
            }
        }

        $output->info("MetaVox: Restored {$restored} metadata entries from search index ({$skipped} already existed).");
    }
}
