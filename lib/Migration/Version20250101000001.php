<?php

declare(strict_types=1);

namespace OCA\metavox\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Complete Metavox schema with short table names
 */
class Version20250101000001 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
	) {
	}

    /**
     * @param IOutput $output
     * @param \Closure $schemaClosure
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options) {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // 1. Create metavox_fields table
        if (!$schema->hasTable('metavox_fields')) {
            $output->info('Creating metavox_fields table...');
            $table = $schema->createTable('metavox_fields');
            $table->addColumn('id', 'integer', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('field_name', 'string', [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('field_label', 'string', [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('field_type', 'string', [
                'notnull' => true,
                'length' => 50,
            ]);
            $table->addColumn('field_options', 'text', [
                'notnull' => false,
            ]);
            $table->addColumn('is_required', 'integer', [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('sort_order', 'integer', [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('scope', 'string', [
                'notnull' => true,
                'length' => 50,
                'default' => 'global',
            ]);
            $table->addColumn('created_at', 'datetime', [
                'notnull' => false,
            ]);
            $table->addColumn('updated_at', 'datetime', [
                'notnull' => false,
            ]);

            $table->setPrimaryKey(['id'], 'mf_fields_pk');
            $table->addUniqueIndex(['field_name', 'scope'], 'mf_fields_name_scope');
            $table->addIndex(['scope'], 'mf_fields_scope');
        }

        // Add scope column to existing table if needed
        if ($schema->hasTable('metavox_fields')) {
            $table = $schema->getTable('metavox_fields');
            if (!$table->hasColumn('scope')) {
                $output->info('Adding scope column to metavox_fields...');
                $table->addColumn('scope', 'string', [
                    'notnull' => true,
                    'length' => 50,
                    'default' => 'global',
                ]);
                $table->addIndex(['scope'], 'mf_fields_scope');
            }
        }

        // 2. Create metavox_metadata table
        if (!$schema->hasTable('metavox_metadata')) {
            $output->info('Creating metavox_metadata table...');
            $table = $schema->createTable('metavox_metadata');
            $table->addColumn('id', 'integer', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('file_id', 'bigint', [
                'notnull' => true,
            ]);
            $table->addColumn('field_id', 'integer', [
                'notnull' => true,
            ]);
            $table->addColumn('value', 'text', [
                'notnull' => false,
            ]);
            $table->addColumn('updated_at', 'datetime', [
                'notnull' => false,
            ]);

            $table->setPrimaryKey(['id'], 'mf_meta_pk');
            $table->addUniqueIndex(['file_id', 'field_id'], 'mf_meta_file_field');
            $table->addIndex(['file_id'], 'mf_meta_file');
            $table->addIndex(['field_id'], 'mf_meta_field');
        }

        // 3. Create metavox_gf_fields table
        if (!$schema->hasTable('metavox_gf_fields')) {
            $output->info('Creating metavox_gf_fields table...');
            $table = $schema->createTable('metavox_gf_fields');
            $table->addColumn('id', 'integer', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('field_name', 'string', [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('field_label', 'string', [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('field_type', 'string', [
                'notnull' => true,
                'length' => 50,
            ]);
            $table->addColumn('field_options', 'text', [
                'notnull' => false,
            ]);
            $table->addColumn('is_required', 'integer', [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('sort_order', 'integer', [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('created_at', 'datetime', [
                'notnull' => false,
            ]);
            $table->addColumn('updated_at', 'datetime', [
                'notnull' => false,
            ]);

            $table->setPrimaryKey(['id'], 'mf_gf_fields_pk');
            $table->addUniqueIndex(['field_name'], 'mf_gf_fields_name');
        }

        // 4. Create metavox_gf_metadata table
        if (!$schema->hasTable('metavox_gf_metadata')) {
            $output->info('Creating metavox_gf_metadata table...');
            $table = $schema->createTable('metavox_gf_metadata');
            $table->addColumn('id', 'integer', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('groupfolder_id', 'integer', [
                'notnull' => true,
            ]);
            $table->addColumn('field_name', 'string', [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('field_value', 'text', [
                'notnull' => false,
            ]);
            $table->addColumn('created_at', 'datetime', [
                'notnull' => false,
            ]);
            $table->addColumn('updated_at', 'datetime', [
                'notnull' => false,
            ]);

            $table->setPrimaryKey(['id'], 'mf_gf_meta_pk');
            $table->addUniqueIndex(['groupfolder_id', 'field_name'], 'mf_gf_meta_gf_field');
            $table->addIndex(['groupfolder_id'], 'mf_gf_meta_gf');
            $table->addIndex(['field_name'], 'mf_gf_meta_field');
        }

        // 5. Create metavox_gf_assigns table
        if (!$schema->hasTable('metavox_gf_assigns')) {
            $output->info('Creating metavox_gf_assigns table...');
            $table = $schema->createTable('metavox_gf_assigns');
            $table->addColumn('id', 'integer', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('groupfolder_id', 'integer', [
                'notnull' => true,
            ]);
            $table->addColumn('field_id', 'integer', [
                'notnull' => true,
            ]);
            $table->addColumn('created_at', 'datetime', [
                'notnull' => false,
            ]);

            $table->setPrimaryKey(['id'], 'mf_gf_assigns_pk');
            $table->addUniqueIndex(['groupfolder_id', 'field_id'], 'mf_gf_assigns_gf_field');
            $table->addIndex(['groupfolder_id'], 'mf_gf_assigns_gf');
            $table->addIndex(['field_id'], 'mf_gf_assigns_field');
        }

        // 6. Create metavox_file_gf_meta table
        if (!$schema->hasTable('metavox_file_gf_meta')) {
            $output->info('Creating metavox_file_gf_meta table...');
            $table = $schema->createTable('metavox_file_gf_meta');
            $table->addColumn('id', 'integer', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('file_id', 'bigint', [
                'notnull' => true,
            ]);
            $table->addColumn('groupfolder_id', 'integer', [
                'notnull' => true,
            ]);
            $table->addColumn('field_name', 'string', [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('field_value', 'text', [
                'notnull' => false,
            ]);
            $table->addColumn('created_at', 'datetime', [
                'notnull' => false,
            ]);
            $table->addColumn('updated_at', 'datetime', [
                'notnull' => false,
            ]);

            $table->setPrimaryKey(['id'], 'mf_file_gf_pk');
            $table->addUniqueIndex(['file_id', 'groupfolder_id', 'field_name'], 'mf_file_gf_meta_unique');
            $table->addIndex(['file_id'], 'mf_file_gf_meta_file');
            $table->addIndex(['groupfolder_id'], 'mf_file_gf_meta_gf');
            $table->addIndex(['field_name'], 'mf_file_gf_meta_field');
        }

        return $schema;
    }

    /**
     * @param IOutput $output
     * @param \Closure $schemaClosure
     * @param array $options
     */
    public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void {
        try {
            // Update existing records to set default scope
            $output->info('Setting default scope for existing fields...');

            $qb = $this->db->getQueryBuilder();
            $qb->update('metavox_fields')
               ->set('scope', $qb->createNamedParameter('global'))
               ->where($qb->expr()->orX(
                   $qb->expr()->isNull('scope'),
                   $qb->expr()->eq('scope', $qb->createNamedParameter(''))
               ));

            $affected = $qb->executeStatement();
            $output->info("Updated $affected existing fields with scope 'global'");

        } catch (\Exception $e) {
            $output->warning('Could not update existing records: ' . $e->getMessage());
        }

        $output->info('Metavox database schema created successfully!');
    }
}
