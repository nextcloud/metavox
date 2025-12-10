<?php
declare(strict_types=1);

namespace OCA\MetaVox\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version010100Date20241019000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('metavox_license_settings')) {
            $table = $schema->createTable('metavox_license_settings');

            $table->addColumn('id', 'integer', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('license_key', 'string', [
                'notnull' => false,
                'length' => 255,
            ]);
            $table->addColumn('license_server_url', 'string', [
                'notnull' => false,
                'length' => 500,
            ]);
            $table->addColumn('updated_at', 'datetime', [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id'], 'mvox_lic_set_pk');
        }

        return $schema;
    }
}
