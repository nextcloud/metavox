<?php

declare(strict_types=1);

namespace OCA\metavox\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Allow One Retention Policy for Multiple Team Folders
 * Creates a many-to-many relationship between policies and groupfolders
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

        // Create junction table for many-to-many relationship
        if (!$schema->hasTable('metavox_policy_groupfolders')) {
            $output->info('Creating metavox_policy_groupfolders junction table...');
            $table = $schema->createTable('metavox_policy_groupfolders');
            
            $table->addColumn('id', 'integer', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('policy_id', 'integer', [
                'notnull' => true,
                'comment' => 'Reference to retention policy'
            ]);
            $table->addColumn('groupfolder_id', 'integer', [
                'notnull' => true,
                'comment' => 'Reference to groupfolder'
            ]);
            $table->addColumn('created_at', 'datetime', [
                'notnull' => false,
            ]);

            $table->setPrimaryKey(['id'], 'mv_pol_gf_pk');
            $table->addUniqueIndex(['policy_id', 'groupfolder_id'], 'mv_pol_gf_unique');
            $table->addIndex(['policy_id'], 'mv_pol_gf_policy');
            $table->addIndex(['groupfolder_id'], 'mv_pol_gf_folder');
        }

        // Remove the direct groupfolder_id from policies table and make it optional
        if ($schema->hasTable('metavox_ret_policies')) {
            $output->info('Updating metavox_ret_policies table...');
            $table = $schema->getTable('metavox_ret_policies');
            
            // Remove unique constraint on groupfolder_id if it exists
            if ($table->hasIndex('mv_ret_policies_gf_unique')) {
                $table->dropIndex('mv_ret_policies_gf_unique');
            }
            
            // Make groupfolder_id nullable (for backward compatibility during migration)
            if ($table->hasColumn('groupfolder_id')) {
                $table->changeColumn('groupfolder_id', [
                    'notnull' => false,
                    'comment' => 'Deprecated - use metavox_policy_groupfolders table'
                ]);
            }
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
            // Migrate existing policy-groupfolder relationships
            $output->info('Migrating existing policy-groupfolder relationships...');
            
            $result = $connection->executeQuery('
                SELECT id, groupfolder_id 
                FROM `*PREFIX*metavox_ret_policies` 
                WHERE groupfolder_id IS NOT NULL
            ');
            
            $migrated = 0;
            while ($row = $result->fetch()) {
                $policyId = $row['id'];
                $groupfolderId = $row['groupfolder_id'];
                
                // Insert into junction table
                $connection->executeStatement('
                    INSERT INTO `*PREFIX*metavox_policy_groupfolders` 
                    (policy_id, groupfolder_id, created_at) 
                    VALUES (?, ?, ?)
                ', [$policyId, $groupfolderId, date('Y-m-d H:i:s')]);
                
                $migrated++;
            }
            $result->closeCursor();
            
            $output->info("✅ Migrated {$migrated} existing policy-groupfolder relationships");
            
            // Add foreign key constraints
            try {
                $connection->executeStatement('
                    ALTER TABLE `*PREFIX*metavox_policy_groupfolders` 
                    ADD CONSTRAINT `fk_policy_gf_policy` 
                    FOREIGN KEY (`policy_id`) 
                    REFERENCES `*PREFIX*metavox_ret_policies` (`id`) 
                    ON DELETE CASCADE
                ');
                $output->info('✅ Added foreign key constraint: policy_groupfolders -> policies');
            } catch (\Exception $e) {
                $output->warning('Could not add FK constraint: ' . $e->getMessage());
            }
            
        } catch (\Exception $e) {
            $output->warning('Migration warning: ' . $e->getMessage());
        }
        
        $output->info('');
        $output->info('🔄 MetaVox Retention: One policy for multiple Team folders enabled!');
        $output->info('');
        $output->info('📋 New Features:');
        $output->info('   • One retention policy can apply to multiple Team folders');
        $output->info('   • Easier management of similar retention requirements');
        $output->info('   • Bulk policy application across multiple folders');
        $output->info('');
        $output->info('✅ Example Use Cases:');
        $output->info('   • "Legal Documents" policy → Applied to Legal, HR, Contracts folders');
        $output->info('   • "Temporary Files" policy → Applied to Temp, Cache, Staging folders');
        $output->info('   • "Long-term Archive" policy → Applied to Archive, Backup folders');
        $output->info('');
        $output->info('🎯 How it works:');
        $output->info('   1. Create a retention policy (without selecting specific folder)');
        $output->info('   2. Select multiple Team folders to apply the policy to');
        $output->info('   3. Users in any of those folders can use the policy');
        $output->info('');
    }
}