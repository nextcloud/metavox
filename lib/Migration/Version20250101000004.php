<?php

declare(strict_types=1);

namespace OCA\metavox\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 🔄 Add Retention Workflow Tables
 * Creates separate tables for retention policies and file retention management
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

        // 1. Create metavox_ret_policies table
        if (!$schema->hasTable('metavox_ret_policies')) {
            $output->info('Creating metavox_ret_policies table...');
            $table = $schema->createTable('metavox_ret_policies');
            
            $table->addColumn('id', 'integer', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('name', 'string', [
                'notnull' => true,
                'length' => 255,
                'comment' => 'Human-readable policy name'
            ]);
            $table->addColumn('description', 'text', [
                'notnull' => false,
                'comment' => 'Policy description for admins'
            ]);
            $table->addColumn('groupfolder_id', 'integer', [
                'notnull' => true,
                'comment' => 'Which groupfolder this policy applies to'
            ]);
            $table->addColumn('is_active', 'integer', [
                'notnull' => true,
                'default' => 1,
                'comment' => 'Whether this policy is currently active'
            ]);
            $table->addColumn('default_action', 'string', [
                'notnull' => true,
                'length' => 50,
                'default' => 'move',
                'comment' => 'Default action: move, delete, archive'
            ]);
            $table->addColumn('default_target_path', 'string', [
                'notnull' => false,
                'length' => 500,
                'comment' => 'Default path for move/archive actions'
            ]);
            $table->addColumn('notify_before_days', 'integer', [
                'notnull' => true,
                'default' => 7,
                'comment' => 'Days before expiry to notify users'
            ]);
            $table->addColumn('auto_process', 'integer', [
                'notnull' => true,
                'default' => 1,
                'comment' => 'Whether to automatically process expired files'
            ]);
            $table->addColumn('allowed_retention_periods', 'text', [
                'notnull' => false,
                'comment' => 'JSON array of allowed retention periods'
            ]);
            $table->addColumn('require_justification', 'integer', [
                'notnull' => true,
                'default' => 0,
                'comment' => 'Whether users must provide justification'
            ]);
            $table->addColumn('created_at', 'datetime', [
                'notnull' => false,
            ]);
            $table->addColumn('updated_at', 'datetime', [
                'notnull' => false,
            ]);

            $table->setPrimaryKey(['id'], 'mv_ret_policies_pk');
            $table->addUniqueIndex(['groupfolder_id'], 'mv_ret_policies_gf_unique');
            $table->addIndex(['is_active'], 'mv_ret_policies_active');
            $table->addIndex(['groupfolder_id', 'is_active'], 'mv_ret_policies_gf_active');
        }

        // 2. Create metavox_file_retention table
        if (!$schema->hasTable('metavox_file_retention')) {
            $output->info('Creating metavox_file_retention table...');
            $table = $schema->createTable('metavox_file_retention');
            
            $table->addColumn('id', 'integer', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('file_id', 'bigint', [
                'notnull' => true,
                'comment' => 'Nextcloud file ID'
            ]);
            $table->addColumn('policy_id', 'integer', [
                'notnull' => true,
                'comment' => 'Reference to retention policy'
            ]);
            $table->addColumn('retention_period', 'integer', [
                'notnull' => true,
                'comment' => 'Number of units (e.g., 2 for "2 years")'
            ]);
            $table->addColumn('retention_unit', 'string', [
                'notnull' => true,
                'length' => 20,
                'default' => 'years',
                'comment' => 'Unit: days, weeks, months, years'
            ]);
            $table->addColumn('expire_date', 'date', [
                'notnull' => true,
                'comment' => 'Calculated expiration date'
            ]);
            $table->addColumn('action', 'string', [
                'notnull' => false,
                'length' => 50,
                'comment' => 'Override action for this file (or use policy default)'
            ]);
            $table->addColumn('target_path', 'string', [
                'notnull' => false,
                'length' => 500,
                'comment' => 'Override target path for this file'
            ]);
            $table->addColumn('justification', 'text', [
                'notnull' => false,
                'comment' => 'User-provided justification for retention period'
            ]);
            $table->addColumn('notify_before_days', 'integer', [
                'notnull' => false,
                'comment' => 'Override notification period for this file'
            ]);
            $table->addColumn('status', 'string', [
                'notnull' => true,
                'length' => 20,
                'default' => 'active',
                'comment' => 'Status: active, processed, cancelled'
            ]);
            $table->addColumn('created_by', 'string', [
                'notnull' => true,
                'length' => 64,
                'comment' => 'User who set the retention'
            ]);
            $table->addColumn('created_at', 'datetime', [
                'notnull' => false,
            ]);
            $table->addColumn('updated_at', 'datetime', [
                'notnull' => false,
            ]);

            $table->setPrimaryKey(['id'], 'mv_file_ret_pk');
            $table->addUniqueIndex(['file_id'], 'mv_file_ret_file_unique');
            $table->addIndex(['policy_id'], 'mv_file_ret_policy');
            $table->addIndex(['expire_date'], 'mv_file_ret_expire');
            $table->addIndex(['status'], 'mv_file_ret_status');
            $table->addIndex(['created_by'], 'mv_file_ret_user');
            $table->addIndex(['expire_date', 'status'], 'mv_file_ret_expire_status');
        }

        // 3. Create metavox_ret_logs table
        if (!$schema->hasTable('metavox_ret_logs')) {
            $output->info('Creating metavox_ret_logs table...');
            $table = $schema->createTable('metavox_ret_logs');
            
            $table->addColumn('id', 'integer', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('file_id', 'bigint', [
                'notnull' => true,
                'comment' => 'File that was processed'
            ]);
            $table->addColumn('policy_id', 'integer', [
                'notnull' => true,
                'comment' => 'Policy that was applied'
            ]);
            $table->addColumn('action', 'string', [
                'notnull' => true,
                'length' => 50,
                'comment' => 'Action that was performed: move, delete, archive'
            ]);
            $table->addColumn('status', 'string', [
                'notnull' => true,
                'length' => 20,
                'comment' => 'Result: success, failed, skipped'
            ]);
            $table->addColumn('message', 'text', [
                'notnull' => false,
                'comment' => 'Detailed message about the action'
            ]);
            $table->addColumn('file_path', 'text', [
                'notnull' => false,
                'comment' => 'Original file path'
            ]);
            $table->addColumn('target_path', 'text', [
                'notnull' => false,
                'comment' => 'Target path for move/archive actions'
            ]);
            $table->addColumn('created_at', 'datetime', [
                'notnull' => false,
            ]);

            $table->setPrimaryKey(['id'], 'mv_ret_logs_pk');
            $table->addIndex(['file_id'], 'mv_ret_logs_file');
            $table->addIndex(['policy_id'], 'mv_ret_logs_policy');
            $table->addIndex(['status'], 'mv_ret_logs_status');
            $table->addIndex(['created_at'], 'mv_ret_logs_date');
            $table->addIndex(['action', 'status'], 'mv_ret_logs_action_status');
        }

        // 4. Create metavox_ret_notifications table (for scheduled notifications)
        if (!$schema->hasTable('metavox_ret_notifications')) {
            $output->info('Creating metavox_ret_notifications table...');
            $table = $schema->createTable('metavox_ret_notifications');
            
            $table->addColumn('id', 'integer', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('file_id', 'bigint', [
                'notnull' => true,
                'comment' => 'File that will expire'
            ]);
            $table->addColumn('retention_id', 'integer', [
                'notnull' => true,
                'comment' => 'Reference to file retention record'
            ]);
            $table->addColumn('user_id', 'string', [
                'notnull' => true,
                'length' => 64,
                'comment' => 'User to notify'
            ]);
            $table->addColumn('notification_type', 'string', [
                'notnull' => true,
                'length' => 50,
                'default' => 'warning',
                'comment' => 'Type: warning, final_warning, expired'
            ]);
            $table->addColumn('scheduled_date', 'date', [
                'notnull' => true,
                'comment' => 'When to send this notification'
            ]);
            $table->addColumn('sent_at', 'datetime', [
                'notnull' => false,
                'comment' => 'When notification was actually sent'
            ]);
            $table->addColumn('status', 'string', [
                'notnull' => true,
                'length' => 20,
                'default' => 'pending',
                'comment' => 'Status: pending, sent, failed, cancelled'
            ]);
            $table->addColumn('message', 'text', [
                'notnull' => false,
                'comment' => 'Notification message content'
            ]);
            $table->addColumn('created_at', 'datetime', [
                'notnull' => false,
            ]);

            $table->setPrimaryKey(['id'], 'mv_ret_notif_pk');
            $table->addIndex(['file_id'], 'mv_ret_notif_file');
            $table->addIndex(['retention_id'], 'mv_ret_notif_retention');
            $table->addIndex(['user_id'], 'mv_ret_notif_user');
            $table->addIndex(['scheduled_date'], 'mv_ret_notif_schedule');
            $table->addIndex(['status'], 'mv_ret_notif_status');
            $table->addIndex(['scheduled_date', 'status'], 'mv_ret_notif_schedule_status');
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
            // Add foreign key constraints after table creation (if database supports it)
            $output->info('Adding foreign key constraints...');
            
            // metavox_file_retention -> metavox_ret_policies
            try {
                $connection->executeStatement('
                    ALTER TABLE `*PREFIX*metavox_file_retention` 
                    ADD CONSTRAINT `fk_file_ret_policy` 
                    FOREIGN KEY (`policy_id`) 
                    REFERENCES `*PREFIX*metavox_ret_policies` (`id`) 
                    ON DELETE CASCADE
                ');
                $output->info('✅ Added foreign key: file_retention -> policies');
            } catch (\Exception $e) {
                $output->warning('Could not add FK constraint file_retention -> policies: ' . $e->getMessage());
            }
            
            // metavox_ret_logs -> metavox_ret_policies
            try {
                $connection->executeStatement('
                    ALTER TABLE `*PREFIX*metavox_ret_logs` 
                    ADD CONSTRAINT `fk_ret_logs_policy` 
                    FOREIGN KEY (`policy_id`) 
                    REFERENCES `*PREFIX*metavox_ret_policies` (`id`) 
                    ON DELETE CASCADE
                ');
                $output->info('✅ Added foreign key: retention_logs -> policies');
            } catch (\Exception $e) {
                $output->warning('Could not add FK constraint retention_logs -> policies: ' . $e->getMessage());
            }
            
            // metavox_ret_notifications -> metavox_file_retention
            try {
                $connection->executeStatement('
                    ALTER TABLE `*PREFIX*metavox_ret_notifications` 
                    ADD CONSTRAINT `fk_ret_notif_retention` 
                    FOREIGN KEY (`retention_id`) 
                    REFERENCES `*PREFIX*metavox_file_retention` (`id`) 
                    ON DELETE CASCADE
                ');
                $output->info('✅ Added foreign key: notifications -> file_retention');
            } catch (\Exception $e) {
                $output->warning('Could not add FK constraint notifications -> file_retention: ' . $e->getMessage());
            }
            
        } catch (\Exception $e) {
            $output->warning('Some foreign key constraints could not be added: ' . $e->getMessage());
        }
        
        // Create initial indexes for performance
        try {
            $output->info('Creating additional performance indexes...');
            
            // Combined indexes for common queries
            $connection->executeStatement('
                CREATE INDEX IF NOT EXISTS `idx_file_ret_policy_status` 
                ON `*PREFIX*metavox_file_retention` (`policy_id`, `status`)
            ');
            
            $connection->executeStatement('
                CREATE INDEX IF NOT EXISTS `idx_file_ret_expire_policy` 
                ON `*PREFIX*metavox_file_retention` (`expire_date`, `policy_id`)
            ');
            
            $connection->executeStatement('
                CREATE INDEX IF NOT EXISTS `idx_ret_logs_date_status` 
                ON `*PREFIX*metavox_ret_logs` (`created_at`, `status`)
            ');
            
            $output->info('✅ Additional performance indexes created');
            
        } catch (\Exception $e) {
            $output->warning('Could not create all performance indexes: ' . $e->getMessage());
        }
        
        $output->info('');
        $output->info('🔄 MetaVox Retention Workflow tables created successfully!');
        $output->info('');
        $output->info('📋 Database Schema Summary:');
        $output->info('   • metavox_ret_policies - Admin-configured retention policies');
        $output->info('   • metavox_file_retention - User-set retention settings per file');
        $output->info('   • metavox_ret_logs - Processing history and audit trail');
        $output->info('   • metavox_ret_notifications - Scheduled user notifications');
        $output->info('');
        $output->info('🎯 Retention Workflow is now available:');
        $output->info('   🔒 Admin: Configure retention policies in MetaVox admin panel');
        $output->info('   👤 Users: Set retention periods in the Files app metadata tab');
        $output->info('   🤖 System: Automatic processing via background jobs');
        $output->info('   📧 System: User notifications before expiry');
        $output->info('');
        $output->info('✅ Next steps:');
        $output->info('1. Enable retention policies in Admin Settings → MetaVox → Retention');
        $output->info('2. Configure background job for automatic processing');
        $output->info('3. Set up notification system (optional)');
        $output->info('4. Test retention workflow with sample files');
        $output->info('');
        $output->info('💡 Workflow Overview:');
        $output->info('   Admin sets HOW & WHERE → User sets WHEN → System processes automatically');
    }
}