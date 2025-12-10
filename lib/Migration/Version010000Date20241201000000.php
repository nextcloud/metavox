<?php
declare(strict_types=1);

namespace OCA\MetaVox\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version010000Date20241201000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('metavox_search_index')) {
            $table = $schema->createTable('metavox_search_index');
            
            $table->addColumn('id', 'bigint', [
                'autoincrement' => true,
                'notnull' => true,
                'length' => 8,
            ]);
            $table->addColumn('file_id', 'bigint', [
                'notnull' => true,
                'length' => 8,
            ]);
            $table->addColumn('user_id', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('storage_id', 'bigint', [
                'notnull' => true,
                'length' => 8,
            ]);
            $table->addColumn('search_content', 'text', [
                'notnull' => true,
            ]);
            $table->addColumn('field_data', 'text', [
                'notnull' => false,
            ]);
            $table->addColumn('updated_at', 'datetime', [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id', 'storage_id'], 'metavox_search_user_storage');
            $table->addIndex(['file_id'], 'metavox_search_file_id');
            
            // REMOVE THIS LINE - this causes the MySQL key length error:
            // $table->addIndex(['search_content'], 'metavox_search_content');
        }

        // Add indexes to existing tables for better performance
        if ($schema->hasTable('metavox_metadata')) {
            $table = $schema->getTable('metavox_metadata');
            
            // Check if indexes exist before adding them
            if (!$table->hasIndex('mv_meta_file_idx')) {
                $table->addIndex(['file_id'], 'mv_meta_file_idx');
            }
            if (!$table->hasIndex('mv_meta_field_idx')) {
                $table->addIndex(['field_id'], 'mv_meta_field_idx');
            }
        }

        // Add indexes to groupfolder tables if they exist
        if ($schema->hasTable('metavox_gf_metadata')) {
            $table = $schema->getTable('metavox_gf_metadata');
            if (!$table->hasIndex('mv_gf_meta_gf_idx')) {
                $table->addIndex(['groupfolder_id'], 'mv_gf_meta_gf_idx');
            }
            if (!$table->hasIndex('mv_gf_meta_field_idx')) {
                $table->addIndex(['field_name'], 'mv_gf_meta_field_idx');
            }
        }

        if ($schema->hasTable('metavox_file_gf_meta')) {
            $table = $schema->getTable('metavox_file_gf_meta');
            if (!$table->hasIndex('mv_file_gf_file_idx')) {
                $table->addIndex(['file_id'], 'mv_file_gf_file_idx');
            }
            if (!$table->hasIndex('mv_file_gf_gf_idx')) {
                $table->addIndex(['groupfolder_id'], 'mv_file_gf_gf_idx');
            }
        }

        return $schema;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $connection = \OC::$server->getDatabaseConnection();

        // Add FULLTEXT index for MySQL after table creation
        if ($connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySqlPlatform) {
            try {
                // Get the actual table name with prefix
                $tablePrefix = $connection->getPrefix();
                $tableName = $tablePrefix . 'metavox_search_index';

                // Check if FULLTEXT index already exists
                $sql = "SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.STATISTICS
                        WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = ?
                        AND INDEX_NAME = 'search_content_fulltext'";

                $result = $connection->executeQuery($sql, [$tableName]);
                $row = $result->fetch();

                if ($row['count'] == 0) {
                    // Add FULLTEXT index with proper MySQL syntax
                    $sql = "ALTER TABLE `" . $tableName . "` ADD FULLTEXT INDEX `search_content_fulltext` (`search_content`)";
                    $connection->executeStatement($sql);
                    $output->info('Added FULLTEXT index for MySQL search');
                } else {
                    $output->info('FULLTEXT index already exists');
                }
            } catch (\Exception $e) {
                $output->warning('Could not add FULLTEXT index: ' . $e->getMessage());
                // This is not critical - search will still work with LIKE queries
            }
        } else {
            // For non-MySQL databases, we'll use LIKE queries instead of FULLTEXT
            $output->info('Using LIKE-based search for ' . get_class($connection->getDatabasePlatform()));
        }
    }
}