<?php

declare(strict_types=1);

namespace OCA\metavox\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add views table for predefined combinations of column visibility, filters, and sort settings per groupfolder.
 */
class Version20250101000015 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options) {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('metavox_gf_views')) {
            $output->info('Creating metavox_gf_views table...');
            $table = $schema->createTable('metavox_gf_views');

            $table->addColumn('id', 'bigint', [
                'autoincrement' => true,
                'notnull' => true,
            ]);

            $table->addColumn('gf_id', 'bigint', [
                'notnull' => true,
                'comment' => 'Groupfolder ID',
            ]);

            $table->addColumn('name', 'string', [
                'notnull' => true,
                'length' => 100,
                'comment' => 'Display name of the view',
            ]);

            $table->addColumn('is_default', 'smallint', [
                'notnull' => true,
                'default' => 0,
                'comment' => 'Whether this is the default view for the groupfolder',
            ]);

            $table->addColumn('columns', 'text', [
                'notnull' => false,
                'comment' => 'JSON: [{field_id, show_as_column, column_order}]',
            ]);

            $table->addColumn('filters', 'text', [
                'notnull' => false,
                'comment' => 'JSON: [{field_name, values: []}]',
            ]);

            $table->addColumn('sort_field', 'string', [
                'notnull' => false,
                'length' => 100,
                'comment' => 'Field to sort by',
            ]);

            $table->addColumn('sort_order', 'string', [
                'notnull' => false,
                'length' => 4,
                'comment' => 'Sort direction: asc or desc',
            ]);

            $table->addColumn('created_at', 'datetime', [
                'notnull' => true,
            ]);

            $table->addColumn('updated_at', 'datetime', [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id'], 'mvx_views_pk');
            $table->addIndex(['gf_id'], 'mvx_views_gf');
            $table->addIndex(['gf_id', 'is_default'], 'mvx_views_gf_default');

            $output->info('Successfully created metavox_gf_views table');
        } else {
            $output->info('metavox_gf_views table already exists, skipping...');
        }

        return $schema;
    }
}
