<?php

declare(strict_types=1);

namespace OCA\metavox\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add position column to metavox_gf_views for custom tab ordering.
 */
class Version20250101000018 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options) {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('metavox_gf_views')) {
            $table = $schema->getTable('metavox_gf_views');

            if (!$table->hasColumn('position')) {
                $output->info('Adding position column to metavox_gf_views...');
                $table->addColumn('position', 'integer', [
                    'notnull' => true,
                    'default' => 0,
                    'comment' => 'Tab display order (lower = earlier)',
                ]);
            }
        }

        return $schema;
    }
}
