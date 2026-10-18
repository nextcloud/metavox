<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Removes the paths from stored file-link values ("12:/a.pdf" becomes "12:").
 * Runs once as a migration, and again after a backup restore because an older
 * backup still holds paths. File links have no search words.
 */
class FileLinkPathCleanup {

    private const BATCH = 1000;

    public function __construct(
        private readonly IDBConnection $db,
        private readonly MetadataVersions $versions,
    ) {
    }

    /** @return int the number of values that changed */
    public function run(): int {
        $fields = $this->fileLinkFields();
        if ($fields === []) {
            return 0;
        }

        $changed = $this->clean('metavox_file_gf_meta', 'field_value', 'field_name', array_values($fields), IQueryBuilder::PARAM_STR_ARRAY)
            + $this->clean('metavox_gf_metadata', 'field_value', 'field_name', array_values($fields), IQueryBuilder::PARAM_STR_ARRAY)
            + $this->clean('metavox_gf_assigns', 'default_value', 'field_id', array_keys($fields), IQueryBuilder::PARAM_INT_ARRAY);
        if ($changed > 0) {
            $this->versions->bumpAll();
        }
        return $changed;
    }

    /** @return array<int, string> field id => field name */
    private function fileLinkFields(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'field_name')
           ->from('metavox_gf_fields')
           ->where($qb->expr()->in('field_type', $qb->createNamedParameter(FileReferenceService::FILELINK_TYPES, IQueryBuilder::PARAM_STR_ARRAY)));
        $result = $qb->executeQuery();
        $fields = [];
        while ($row = $result->fetch()) {
            $fields[(int)$row['id']] = (string)$row['field_name'];
        }
        $result->closeCursor();
        return $fields;
    }

    /**
     * @param list<string|int> $keys the file-link fields, by name or id
     */
    private function clean(string $table, string $column, string $keyColumn, array $keys, int $keyType): int {
        $changed = 0;
        $lastId = 0;
        do {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', $column)
               ->from($table)
               ->where($qb->expr()->in($keyColumn, $qb->createNamedParameter($keys, $keyType)))
               ->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($lastId, IQueryBuilder::PARAM_INT)))
               ->andWhere($qb->expr()->neq($column, $qb->createNamedParameter('')))
               ->orderBy('id', 'ASC')
               ->setMaxResults(self::BATCH);
            $result = $qb->executeQuery();
            $rows = $result->fetchAll();
            $result->closeCursor();

            foreach ($rows as $row) {
                $lastId = (int)$row['id'];
                $value = (string)$row[$column];
                $stored = FileReferenceService::withoutPaths($value);
                if ($stored !== $value) {
                    $update = $this->db->getQueryBuilder();
                    $update->update($table)
                       ->set($column, $update->createNamedParameter($stored))
                       ->where($update->expr()->eq('id', $update->createNamedParameter($lastId, IQueryBuilder::PARAM_INT)));
                    $update->executeStatement();
                    $changed++;
                }
            }
        } while (count($rows) === self::BATCH);
        return $changed;
    }
}
