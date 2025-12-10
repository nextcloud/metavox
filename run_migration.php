#!/usr/bin/env php
<?php
/**
 * Manual migration runner for Version20250101000010
 * Adds performance indexes to metavox_file_gf_meta table
 */

require __DIR__ . '/../../lib/base.php';

use OCA\MetaVox\Migration\Version20250101000010;
use OC\DB\MDB2SchemaManager;
use OCP\Migration\IOutput;

echo "=== Running MetaVox Migration: Version20250101000010 ===\n\n";

// Create output handler
$output = new class implements IOutput {
    public function info($message) {
        echo "[INFO] $message\n";
    }
    public function warning($message) {
        echo "[WARNING] $message\n";
    }
    public function startProgress($max = 0) {}
    public function advance($step = 1, $description = '') {}
    public function finishProgress() {}
};

try {
    $db = \OC::$server->getDatabaseConnection();
    $migration = new Version20250101000010($db);

    echo "Executing changeSchema()...\n\n";

    $schema = $migration->changeSchema($output, function() use ($db) {
        return $db->createSchema();
    }, []);

    if ($schema) {
        echo "\nApplying schema changes...\n";
        $db->migrateToSchema($schema);
        echo "Schema updated successfully!\n\n";
    }

    echo "Executing postSchemaChange()...\n\n";
    $migration->postSchemaChange($output, function() use ($db) {
        return $db->createSchema();
    }, []);

    echo "\n=== Migration completed successfully! ===\n";

} catch (\Exception $e) {
    echo "\n[ERROR] Migration failed: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
