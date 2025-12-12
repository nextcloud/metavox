<?php

declare(strict_types=1);

namespace OCA\metavox\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Remove unused database tables
 *
 * This migration removes tables that are no longer used after the cleanup:
 * - metavox_fields: Global field definitions (replaced by metavox_gf_fields)
 * - metavox_metadata: Global metadata values (replaced by metavox_gf_metadata)
 * - metavox_gf_overrides: Field overrides (feature was never used in frontend)
 *
 * These tables were part of the original design but are no longer needed
 * as the app now exclusively uses groupfolder-scoped fields and metadata.
 */
class Version20250101000011 extends SimpleMigrationStep {

    public function __construct(
        private IDBConnection $db,
    ) {}

    /**
     * @param IOutput $output
     * @param \Closure $schemaClosure
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Remove metavox_fields table (global field definitions - no longer used)
        if ($schema->hasTable('metavox_fields')) {
            $output->info('Removing unused table: metavox_fields (global field definitions)');
            $schema->dropTable('metavox_fields');
        }

        // Remove metavox_metadata table (global metadata values - no longer used)
        if ($schema->hasTable('metavox_metadata')) {
            $output->info('Removing unused table: metavox_metadata (global metadata values)');
            $schema->dropTable('metavox_metadata');
        }

        // Remove metavox_gf_overrides table (field overrides - never used in frontend)
        if ($schema->hasTable('metavox_gf_overrides')) {
            $output->info('Removing unused table: metavox_gf_overrides (field overrides)');
            $schema->dropTable('metavox_gf_overrides');
        }

        return $schema;
    }

    /**
     * @param IOutput $output
     * @param \Closure $schemaClosure
     * @param array $options
     */
    public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void {
        $output->info('');
        $output->info('Unused tables have been removed successfully!');
        $output->info('');
        $output->info('Removed tables:');
        $output->info('  - metavox_fields (global field definitions)');
        $output->info('  - metavox_metadata (global metadata values)');
        $output->info('  - metavox_gf_overrides (field overrides)');
        $output->info('');
        $output->info('Active tables:');
        $output->info('  - metavox_gf_fields (groupfolder field definitions)');
        $output->info('  - metavox_gf_metadata (groupfolder metadata values)');
        $output->info('  - metavox_file_gf_meta (file metadata within groupfolders)');
        $output->info('  - metavox_gf_assigns (field assignments to groupfolders)');
        $output->info('  - metavox_permissions (user/group permissions)');
        $output->info('  - metavox_search_index (search index for unified search)');
    }
}
