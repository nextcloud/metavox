<?php

declare(strict_types=1);

namespace OCA\metavox\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Remove redundant indexes from metavox_file_gf_meta.
 * The remaining 4 indexes cover all query patterns:
 *   - mf_file_gf_meta_unique (file_id, groupfolder_id, field_name): upserts + file lookups
 *   - idx_file_gf_gf_lookup (groupfolder_id, file_id): reverse lookups per folder
 *   - idx_gf_file_meta_filter (groupfolder_id, field_name, field_value): filter dropdowns
 *   - PRIMARY (id)
 */
class Version20250101000017 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options) {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('metavox_file_gf_meta')) {
            return $schema;
        }

        $table = $schema->getTable('metavox_file_gf_meta');

        $redundantIndexes = [
            'idx_file_gf_composite',        // exact duplicaat van mf_file_gf_meta_unique
            'mf_file_gf_meta_file',         // (file_id) = prefix van unique
            'idx_gf_file_meta_file_id',     // (file_id, groupfolder_id) = prefix van unique
            'mf_file_gf_meta_gf',           // (groupfolder_id) = prefix van gf_lookup en filter
            'mf_file_gf_meta_field',        // (field_name) = prefix van filter; zeldzame DELETE-query
            'idx_gf_file_meta_timestamps',  // (created_at, updated_at) = geen query filtert hierop
            'idx_file_gf_updated',          // (updated_at) = geen query filtert hierop
        ];

        foreach ($redundantIndexes as $indexName) {
            if ($table->hasIndex($indexName)) {
                $output->info("Dropping redundant index: $indexName");
                $table->dropIndex($indexName);
            }
        }

        return $schema;
    }
}
