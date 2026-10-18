<?php

declare(strict_types=1);

namespace OCA\MetaVox\Repair;

use OCA\MetaVox\Service\SearchIndexService;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * On PostgreSQL the filter index holds the start of each value, as MySQL's
 * prefix index does: with the whole value an entry over 2.7 KB could not be
 * stored, so a longer value could not be saved. An expression index, which the
 * schema API cannot make: Version20250101000023 sets it on an update, this step
 * on a new install, where only the migrations' schema runs.
 */
class PostgresValueIndex implements IRepairStep {

    private const INDEX = 'idx_gf_file_meta_filter';

    public function __construct(
        private IDBConnection $db,
    ) {
    }

    public function getName(): string {
        return 'Index the start of metadata values on PostgreSQL';
    }

    public function run(IOutput $output): void {
        $this->ensure();
    }

    /** @return bool whether the index was made now */
    public function ensure(): bool {
        if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_POSTGRES) {
            return false;
        }
        $definition = $this->db->executeQuery(
            'SELECT indexdef FROM pg_indexes WHERE schemaname = current_schema() AND indexname = ?',
            [self::INDEX]
        )->fetchOne();
        // PostgreSQL writes it back as "left"(field_value, 100).
        if (\is_string($definition) && preg_match('/\bleft\b/i', $definition) === 1) {
            return false;
        }
        $this->db->executeStatement('DROP INDEX IF EXISTS ' . self::INDEX);
        $this->db->executeStatement('CREATE INDEX ' . self::INDEX . ' ON *PREFIX*metavox_file_gf_meta '
            . '(groupfolder_id, field_name, LEFT(field_value, ' . SearchIndexService::VALUE_INDEX_CHARS . '))');
        return true;
    }
}
