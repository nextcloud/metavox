<?php

declare(strict_types=1);

namespace OCA\metavox\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Drop metavox_gf_column_config table.
 * Column configuration is now managed entirely through views.
 */
class Version20250101000016 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options) {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('metavox_gf_column_config')) {
            $output->info('Dropping metavox_gf_column_config table (column config is now managed via views)...');
            $schema->dropTable('metavox_gf_column_config');
            $output->info('Successfully dropped metavox_gf_column_config table');
        } else {
            $output->info('metavox_gf_column_config table does not exist, skipping...');
        }

        return $schema;
    }
}
