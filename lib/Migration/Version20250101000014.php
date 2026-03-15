<?php

declare(strict_types=1);

namespace OCA\metavox\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add column configuration table for Files app metadata columns.
 * Stores which metadata fields should appear as columns in the file list per groupfolder.
 */
class Version20250101000014 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options) {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('metavox_gf_column_config')) {
            $output->info('Creating metavox_gf_column_config table...');
            $table = $schema->createTable('metavox_gf_column_config');

            $table->addColumn('id', 'integer', [
                'autoincrement' => true,
                'notnull' => true,
            ]);

            $table->addColumn('groupfolder_id', 'integer', [
                'notnull' => true,
                'comment' => 'Team folder ID',
            ]);

            $table->addColumn('field_id', 'integer', [
                'notnull' => true,
                'comment' => 'FK to metavox_gf_fields.id',
            ]);

            $table->addColumn('show_as_column', 'boolean', [
                'notnull' => true,
                'default' => true,
                'comment' => 'Whether to show as column in file list',
            ]);

            $table->addColumn('column_order', 'integer', [
                'notnull' => true,
                'default' => 0,
                'comment' => 'Display order in file list',
            ]);

            $table->addColumn('filterable', 'boolean', [
                'notnull' => true,
                'default' => true,
                'comment' => 'Whether filtering is enabled for this column',
            ]);

            $table->addColumn('created_at', 'datetime', [
                'notnull' => false,
            ]);

            $table->addColumn('updated_at', 'datetime', [
                'notnull' => false,
            ]);

            $table->setPrimaryKey(['id'], 'mvx_colcfg_pk');
            $table->addUniqueIndex(['groupfolder_id', 'field_id'], 'mvx_colcfg_gf_field');
            $table->addIndex(['groupfolder_id'], 'mvx_colcfg_gf');

            $output->info('Successfully created metavox_gf_column_config table');
        } else {
            $output->info('metavox_gf_column_config table already exists, skipping...');
        }

        return $schema;
    }
}
