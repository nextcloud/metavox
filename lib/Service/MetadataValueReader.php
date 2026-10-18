<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Reads the stored metadata values of a team folder's files. No access checks:
 * callers pass only file ids the user may read.
 */
class MetadataValueReader {

    /** Ids per query; the MAX_IN_PARAMETERS constant only exists from NC 35. */
    public const CHUNK = 1000;

    public function __construct(
        private readonly IDBConnection $db,
    ) {
    }

    /**
     * @param int[] $fileIds
     * @param ?int $maxChars cut values off at this many characters in SQL; null for full values
     * @return array<int, array<string, ?string>> file id => field name => value ('' and null kept)
     */
    public function readValues(int $groupfolderId, array $fileIds, ?int $maxChars = null): array {
        $ids = array_values(array_unique(array_map('intval', $fileIds)));
        $values = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('file_id', 'field_name');
            if ($maxChars === null) {
                $qb->addSelect('field_value');
            } else {
                $qb->selectAlias($qb->func()->substring(
                    'field_value',
                    $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
                    $qb->createNamedParameter($maxChars, IQueryBuilder::PARAM_INT),
                ), 'field_value');
            }
            $qb->from('metavox_file_gf_meta')
               ->where($qb->expr()->eq('groupfolder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)))
               ->andWhere($qb->expr()->in('file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));

            $result = $qb->executeQuery();
            try {
                // fetch(): fetchAssociative() only exists from NC 33.
                while ($row = $result->fetch()) {
                    $value = $row['field_value'];
                    $values[(int)$row['file_id']][(string)$row['field_name']] = $value === null ? null : (string)$value;
                }
            } finally {
                $result->closeCursor();
            }
        }
        return $values;
    }
}
