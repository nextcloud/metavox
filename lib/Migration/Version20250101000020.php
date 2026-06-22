<?php

declare(strict_types=1);

namespace OCA\metavox\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add a UNIQUE index on metavox_search_index(file_id, user_id).
 *
 * The search-index upsert previously did SELECT-then-INSERT/UPDATE with no
 * unique constraint, so two concurrent writers (the defaults listener and the
 * discovery job, or a retried job) could both insert a row for the same
 * (file_id, user_id), producing duplicate index rows and double hits in
 * unified search. The unique index makes the bulk ON CONFLICT / ON DUPLICATE
 * upsert (introduced alongside this migration) atomic and idempotent.
 *
 * Existing duplicates must be removed first, otherwise the unique index cannot
 * be created. We keep the lowest id per (file_id, user_id) group.
 */
class Version20250101000020 extends SimpleMigrationStep {

    public function __construct(
        private IDBConnection $db,
    ) {}

    /**
     * Remove duplicate rows before the unique index is added.
     */
    public function preSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if (!$schema->hasTable('metavox_search_index')) {
            return;
        }

        // Find (file_id, user_id) groups that have more than one row.
        $qb = $this->db->getQueryBuilder();
        $qb->select('file_id', 'user_id')
           ->selectAlias($qb->func()->min('id'), 'keep_id')
           ->selectAlias($qb->func()->count('*'), 'cnt')
           ->from('metavox_search_index')
           ->groupBy('file_id', 'user_id')
           ->having($qb->expr()->gt($qb->func()->count('*'), $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $dupGroups = $result->fetchAll();
        $result->closeCursor();

        if (empty($dupGroups)) {
            return;
        }

        $output->info('Removing ' . \count($dupGroups) . ' duplicate search-index group(s) before adding unique index...');

        foreach ($dupGroups as $group) {
            // Delete every row in the group except the one we keep.
            $del = $this->db->getQueryBuilder();
            $del->delete('metavox_search_index')
                ->where($del->expr()->eq('file_id', $del->createNamedParameter($group['file_id'])))
                ->andWhere($del->expr()->eq('user_id', $del->createNamedParameter($group['user_id'])))
                ->andWhere($del->expr()->neq('id', $del->createNamedParameter($group['keep_id'], IQueryBuilder::PARAM_INT)));
            $del->executeStatement();
        }
    }

    /**
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options) {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('metavox_search_index')) {
            return null;
        }

        $table = $schema->getTable('metavox_search_index');
        if (!$table->hasIndex('mv_search_file_user_uniq')) {
            $output->info('Adding unique index on metavox_search_index(file_id, user_id)...');
            $table->addUniqueIndex(['file_id', 'user_id'], 'mv_search_file_user_uniq');
        }

        return $schema;
    }
}
