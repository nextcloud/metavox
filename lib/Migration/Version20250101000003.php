<?php

declare(strict_types=1);

namespace OCA\metavox\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add applies_to_groupfolder column and create field overrides table
 */
class Version20250101000003 extends SimpleMigrationStep {

    /**
     * @param IOutput $output
     * @param \Closure $schemaClosure
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options) {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // 1. Add applies_to_groupfolder column to metavox_gf_fields table
        if ($schema->hasTable('metavox_gf_fields')) {
            $table = $schema->getTable('metavox_gf_fields');
            
            if (!$table->hasColumn('applies_to_groupfolder')) {
                $output->info('Adding applies_to_groupfolder column to metavox_gf_fields...');
                
                $table->addColumn('applies_to_groupfolder', 'integer', [
                    'notnull' => true,
                    'default' => 0,
                    'comment' => 'Whether this field applies to the groupfolder itself (1) or to files within it (0)',
                ]);
                
                // Add index for better performance when filtering
                $table->addIndex(['applies_to_groupfolder'], 'mf_gf_fields_applies');
                
                $output->info('Successfully added applies_to_groupfolder column to metavox_gf_fields');
            } else {
                $output->info('applies_to_groupfolder column already exists in metavox_gf_fields, skipping...');
            }
        }

        // 2. Create metavox_gf_overrides table
        if (!$schema->hasTable('metavox_gf_overrides')) {
            $output->info('Creating metavox_gf_overrides table...');
            $table = $schema->createTable('metavox_gf_overrides');
            
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
            $table->addColumn('applies_to_groupfolder', 'integer', [
                'notnull' => true,
                'default' => 0,
                'comment' => 'Override: whether this field applies to groupfolder (1) or files (0) for this specific groupfolder',
            ]);
            $table->addColumn('created_at', 'datetime', [
                'notnull' => false,
            ]);
            $table->addColumn('updated_at', 'datetime', [
                'notnull' => false,
            ]);

            $table->setPrimaryKey(['id'], 'mf_gf_overrides_pk');
            $table->addUniqueIndex(['groupfolder_id', 'field_name'], 'mf_gf_overrides_unique');
            $table->addIndex(['groupfolder_id'], 'mf_gf_overrides_gf');
            $table->addIndex(['field_name'], 'mf_gf_overrides_field');
            $table->addIndex(['applies_to_groupfolder'], 'mf_gf_overrides_applies');
            
            $output->info('Successfully created metavox_gf_overrides table');
        } else {
            $output->info('metavox_gf_overrides table already exists, skipping...');
        }

        return $schema;
    }

    /**
     * @param IOutput $output
     * @param \Closure $schemaClosure
     * @param array $options
     */
    public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void {
        $connection = \OC::$server->getDatabaseConnection();
        
        try {
            // Set default value for existing records in metavox_gf_fields (applies to files, not groupfolder itself)
            $output->info('Setting default applies_to_groupfolder value for existing groupfolder fields...');
            
            $qb = $connection->getQueryBuilder();
            $qb->update('metavox_gf_fields')
               ->set('applies_to_groupfolder', $qb->createNamedParameter(0))
               ->where($qb->expr()->orX(
                   $qb->expr()->isNull('applies_to_groupfolder'),
                   $qb->expr()->eq('applies_to_groupfolder', $qb->createNamedParameter(''))
               ));
            
            $affected = $qb->execute();
            $output->info("Updated $affected existing groupfolder fields with applies_to_groupfolder = 0 (applies to files)");
            
        } catch (\Exception $e) {
            $output->warning('Could not update existing records: ' . $e->getMessage());
        }
        
        $output->info('Metavox field overrides migration completed successfully!');
    }
}