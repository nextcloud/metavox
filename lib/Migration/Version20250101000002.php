<?php

declare(strict_types=1);

namespace OCA\metavox\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add applies_to_groupfolder column to distinguish between groupfolder and file metadata
 */
class Version20250101000002 extends SimpleMigrationStep {

    /**
     * @param IOutput $output
     * @param \Closure $schemaClosure
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options) {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Add applies_to_groupfolder column to metavox_gf_fields table
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
                $table->addIndex(['applies_to_groupfolder'], 'mf_gf_fields_applies_to');
                
                $output->info('Successfully added applies_to_groupfolder column');
            } else {
                $output->info('applies_to_groupfolder column already exists, skipping...');
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
        $connection = \OC::$server->getDatabaseConnection();
        
        try {
            // Set default value for existing records (applies to files, not groupfolder itself)
            $output->info('Setting default applies_to_groupfolder value for existing fields...');
            
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
        
        $output->info('Metavox applies_to_groupfolder migration completed successfully!');
    }
}