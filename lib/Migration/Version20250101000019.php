<?php

declare(strict_types=1);

namespace OCA\metavox\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add per-folder default values for assigned file fields.
 *
 * The default lives on the assignment row (groupfolder_id, field_id) rather
 * than on the field definition, so the same field can default to different
 * values in different team folders (folder A empty, folder B "Concept").
 *
 * Defaults apply only to file fields (metavox_gf_fields.applies_to_groupfolder = 0);
 * folder-level fields ignore this column.
 */
class Version20250101000019 extends SimpleMigrationStep {

    public function __construct(
        private IDBConnection $db,
    ) {}

    /**
     * @param IOutput $output
     * @param \Closure $schemaClosure
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options) {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('metavox_gf_assigns')) {
            return null;
        }

        $table = $schema->getTable('metavox_gf_assigns');
        if (!$table->hasColumn('default_value')) {
            $output->info('Adding default_value column to metavox_gf_assigns...');
            $table->addColumn('default_value', 'text', [
                'notnull' => false,
            ]);
        }

        return $schema;
    }
}
