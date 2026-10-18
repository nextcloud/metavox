<?php

declare(strict_types=1);

namespace OCA\MetaVox\Migration;

use OCA\MetaVox\Service\FileLinkPathCleanup;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * File links keep only the file id: the stored path was the path as the saving
 * user saw it, and showed other users the name of a file they may not see.
 */
class Version20250101000022 extends SimpleMigrationStep {

    public function __construct(
        private FileLinkPathCleanup $cleanup,
    ) {
    }

    public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void {
        $schema = $schemaClosure();
        if (!($schema instanceof ISchemaWrapper)
            || !$schema->hasTable('metavox_gf_fields')
            || !$schema->hasTable('metavox_file_gf_meta')
            || !$schema->hasTable('metavox_gf_metadata')
            || !$schema->hasTable('metavox_gf_assigns')) {
            return;
        }
        $output->info(sprintf('MetaVox: removed the path from %d file-link values.', $this->cleanup->run()));
    }
}
