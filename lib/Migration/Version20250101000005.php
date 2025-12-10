<?php

declare(strict_types=1);

namespace OCA\metavox\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add user permissions table for MetaVox
 */
class Version20250101000005 extends SimpleMigrationStep {

    /**
     * @param IOutput $output
     * @param \Closure $schemaClosure
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options) {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Create metavox_permissions table
        if (!$schema->hasTable('metavox_permissions')) {
            $output->info('Creating metavox_permissions table...');
            $table = $schema->createTable('metavox_permissions');
            
            $table->addColumn('id', 'integer', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            
            $table->addColumn('user_id', 'string', [
                'notnull' => false,
                'length' => 64,
                'comment' => 'User ID (null for group permissions)',
            ]);
            
            $table->addColumn('group_id', 'string', [
                'notnull' => false,
                'length' => 64,
                'comment' => 'Group ID (null for user permissions)',
            ]);
            
            $table->addColumn('permission_type', 'string', [
                'notnull' => true,
                'length' => 50,
                'comment' => 'Type: view_metadata, edit_metadata, manage_fields',
            ]);
            
            $table->addColumn('groupfolder_id', 'integer', [
                'notnull' => false,
                'comment' => 'Specific groupfolder (null for all)',
            ]);
            
            $table->addColumn('field_scope', 'string', [
                'notnull' => false,
                'length' => 50,
                'comment' => 'Scope: global, groupfolder, or null for all',
            ]);
            
            $table->addColumn('created_at', 'datetime', [
                'notnull' => false,
            ]);
            
            $table->addColumn('updated_at', 'datetime', [
                'notnull' => false,
            ]);

            $table->setPrimaryKey(['id'], 'mf_perms_pk');
            $table->addIndex(['user_id'], 'mf_perms_user');
            $table->addIndex(['group_id'], 'mf_perms_group');
            $table->addIndex(['permission_type'], 'mf_perms_type');
            $table->addIndex(['groupfolder_id'], 'mf_perms_gf');
            
            $output->info('Successfully created metavox_permissions table');
        } else {
            $output->info('metavox_permissions table already exists, skipping...');
        }

        return $schema;
    }

    /**
     * @param IOutput $output
     * @param \Closure $schemaClosure
     * @param array $options
     */
    public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void {
        $output->info('MetaVox permissions migration completed successfully!');
    }
}