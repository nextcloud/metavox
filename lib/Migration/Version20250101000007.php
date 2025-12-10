<?php

declare(strict_types=1);

namespace OCA\metavox\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add unique constraint to metavox_gf_metadata
 */
class Version20250101000007 extends SimpleMigrationStep {

    /**
     * @param IOutput $output
     * @param \Closure $schemaClosure
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options) {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('metavox_gf_metadata')) {
            $table = $schema->getTable('metavox_gf_metadata');
            
            // Check if unique index already exists
            if (!$table->hasIndex('mf_gf_meta_gf_field')) {
                $output->info('Adding unique constraint to metavox_gf_metadata...');
                $table->addUniqueIndex(['groupfolder_id', 'field_name'], 'mf_gf_meta_gf_field');
            } else {
                $output->info('Unique constraint already exists on metavox_gf_metadata, skipping...');
            }
        } else {
            $output->warning('Table metavox_gf_metadata does not exist, skipping...');
        }

        return $schema;
    }

    /**
     * @param IOutput $output
     * @param \Closure $schemaClosure
     * @param array $options
     */
    public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void {
        $output->info('MetaVox unique constraint migration completed successfully!');
    }
}