<?php

declare(strict_types=1);

namespace OCA\metavox\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add field_description column to both metavox_fields and metavox_gf_fields tables
 */
class Version20250101000004 extends SimpleMigrationStep {

    /**
     * @param IOutput $output
     * @param \Closure $schemaClosure
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options) {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // 1. Add field_description column to metavox_fields table
        if ($schema->hasTable('metavox_fields')) {
            $table = $schema->getTable('metavox_fields');
            
            if (!$table->hasColumn('field_description')) {
                $output->info('Adding field_description column to metavox_fields...');
                
                $table->addColumn('field_description', 'text', [
                    'notnull' => false,
                    'comment' => 'Optional description for this field',
                ]);
                
                $output->info('Successfully added field_description column to metavox_fields');
            } else {
                $output->info('field_description column already exists in metavox_fields, skipping...');
            }
        } else {
            $output->warning('Table metavox_fields does not exist, skipping...');
        }

        // 2. Add field_description column to metavox_gf_fields table
        if ($schema->hasTable('metavox_gf_fields')) {
            $table = $schema->getTable('metavox_gf_fields');
            
            if (!$table->hasColumn('field_description')) {
                $output->info('Adding field_description column to metavox_gf_fields...');
                
                $table->addColumn('field_description', 'text', [
                    'notnull' => false,
                    'comment' => 'Optional description for this groupfolder field',
                ]);
                
                $output->info('Successfully added field_description column to metavox_gf_fields');
            } else {
                $output->info('field_description column already exists in metavox_gf_fields, skipping...');
            }
        } else {
            $output->warning('Table metavox_gf_fields does not exist, skipping...');
        }

        return $schema;
    }

    /**
     * @param IOutput $output
     * @param \Closure $schemaClosure
     * @param array $options
     */
    public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void {
        $output->info('field_description columns migration completed successfully!');
    }
}