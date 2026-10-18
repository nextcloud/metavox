<?php

declare(strict_types=1);

namespace OCA\MetaVox\Migration;

use OCA\MetaVox\Repair\PostgresValueIndex;
use OCA\MetaVox\Service\SearchWordsService;
use OCP\BackgroundJob\IJobList;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Search gets its own index, filled in the background: the words of each value
 * for free text, looked up by team folder and the first letters of a word, and
 * each whole value for the field filter, looked up by its key. The old index
 * goes: it held one user per file.
 *
 * On PostgreSQL the filter index on the values holds their start, as MySQL's
 * prefix does (PostgresValueIndex, which a new install runs as a repair step).
 */
class Version20250101000023 extends SimpleMigrationStep {

    public function __construct(
        private IJobList $jobList,
        private SearchWordsService $searchWords,
        private PostgresValueIndex $valueIndex,
    ) {
    }

    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('metavox_search_words')) {
            $table = $schema->createTable('metavox_search_words');
            $table->addColumn('groupfolder_id', 'integer', ['notnull' => true]);
            $table->addColumn('field_id', 'integer', ['notnull' => true]);
            $table->addColumn('file_id', 'bigint', ['notnull' => true]);
            $table->addColumn('word', 'string', ['notnull' => true, 'length' => 64]);
            $table->addColumn('word_start', 'string', ['notnull' => true, 'length' => 3]);
            $table->setPrimaryKey(['groupfolder_id', 'word_start', 'word', 'file_id', 'field_id']);
            $table->addIndex(['file_id', 'field_id'], 'mv_search_words_file');
        }
        if ($schema->hasTable('metavox_search_index')) {
            $schema->dropTable('metavox_search_index');
        }
        return $schema;
    }

    public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void {
        $this->valueIndex->ensure();

        // The job class is gone; its queued runs would only fail.
        $this->jobList->remove('OCA\MetaVox\BackgroundJobs\UpdateSearchIndex');
        $this->searchWords->rebuildAll();
    }
}
