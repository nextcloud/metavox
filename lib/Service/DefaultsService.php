<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Applies per-folder default values to file metadata.
 *
 * Defaults live on the assignment row (metavox_gf_assigns.default_value) and
 * apply only to file fields (metavox_gf_fields.applies_to_groupfolder = 0).
 *
 * This service is the data layer for the three-tier default-application
 * system (listener fast-path, discovery TimedJob, processing QueuedJob). It
 * deliberately does NOT touch push, cache or the search index: a bulk
 * backfill of millions of files must not flood the live-collaboration layer.
 * Search re-indexing is done in bulk by the caller; push and cache are left
 * to expire / lazy-fill on read.
 *
 * SQL string builders (buildDefaultsQuery, buildBulkInsertSql) are pure and
 * separated from execution so they can be unit-tested without a database.
 */
class DefaultsService {

    /** Max row tuples per INSERT statement — stays well under driver param limits. */
    public const INSERT_ROW_CHUNK = 500;

    /** @var array<int, array<string, string>> Request-scoped cache: gfId => [field_name => default_value] */
    private array $folderDefaultsCache = [];

    public function __construct(
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Get the file-field defaults configured for a groupfolder.
     *
     * @return array<string, string> field_name => default_value (only non-null defaults)
     */
    public function getFolderDefaults(int $groupfolderId): array {
        if (isset($this->folderDefaultsCache[$groupfolderId])) {
            return $this->folderDefaultsCache[$groupfolderId];
        }

        $qb = $this->buildDefaultsQuery($groupfolderId);
        $result = $qb->executeQuery();

        $defaults = [];
        while ($row = $result->fetch()) {
            $defaults[(string)$row['field_name']] = (string)$row['default_value'];
        }
        $result->closeCursor();

        $this->folderDefaultsCache[$groupfolderId] = $defaults;
        return $defaults;
    }

    /**
     * Defaults keyed by field id (not name) for the management UI, which works
     * in terms of field ids. Returns [fieldId => default_value] for file fields
     * in this folder that have a default set.
     *
     * @return array<int, string>
     */
    public function getFolderDefaultsByFieldId(int $groupfolderId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('a.field_id', 'a.default_value')
           ->from('metavox_gf_assigns', 'a')
           ->innerJoin('a', 'metavox_gf_fields', 'f', 'f.id = a.field_id')
           ->where($qb->expr()->eq('a.groupfolder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)))
           ->andWhere($qb->expr()->isNotNull('a.default_value'))
           ->andWhere($qb->expr()->eq('f.applies_to_groupfolder', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $byId = [];
        while ($row = $result->fetch()) {
            $byId[(int)$row['field_id']] = (string)$row['default_value'];
        }
        $result->closeCursor();

        return $byId;
    }

    /**
     * Set (or clear) the default value for a field in a groupfolder.
     *
     * This is additive to the assignment flow (setGroupfolderFields): the field
     * must already be assigned to the folder. Passing null clears the default.
     * Changing a default does NOT retroactively rewrite files that already have
     * a value — discovery only fills missing rows, and a changed default applies
     * to files that still lack the field. Returns false if the field is not a
     * file field assigned to this folder.
     */
    public function setFieldDefault(int $groupfolderId, int $fieldId, ?string $value): bool {
        // Guard: only file fields (applies_to_groupfolder = 0) may carry a
        // per-file default. Folder fields ignore it.
        $check = $this->db->getQueryBuilder();
        $check->select('a.id')
              ->from('metavox_gf_assigns', 'a')
              ->innerJoin('a', 'metavox_gf_fields', 'f', 'f.id = a.field_id')
              ->where($check->expr()->eq('a.groupfolder_id', $check->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)))
              ->andWhere($check->expr()->eq('a.field_id', $check->createNamedParameter($fieldId, IQueryBuilder::PARAM_INT)))
              ->andWhere($check->expr()->eq('f.applies_to_groupfolder', $check->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
        $result = $check->executeQuery();
        $assignId = $result->fetchOne();
        $result->closeCursor();

        if ($assignId === false) {
            return false;
        }

        $update = $this->db->getQueryBuilder();
        $update->update('metavox_gf_assigns')
               ->set('default_value', $update->createNamedParameter($value))
               ->where($update->expr()->eq('id', $update->createNamedParameter($assignId, IQueryBuilder::PARAM_INT)));
        $update->executeStatement();

        unset($this->folderDefaultsCache[$groupfolderId]);
        return true;
    }

    /**
     * Return the ids of all groupfolders that have at least one file-field
     * default configured. Drives the discovery job so it only scans folders
     * that can actually receive defaults.
     *
     * @return int[]
     */
    public function getGroupfoldersWithDefaults(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('a.groupfolder_id')
           ->from('metavox_gf_assigns', 'a')
           ->innerJoin('a', 'metavox_gf_fields', 'f', 'f.id = a.field_id')
           ->where($qb->expr()->isNotNull('a.default_value'))
           ->andWhere($qb->expr()->eq('f.applies_to_groupfolder', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $ids = [];
        while ($row = $result->fetch()) {
            $ids[] = (int)$row['groupfolder_id'];
        }
        $result->closeCursor();

        return $ids;
    }

    /**
     * Build the query that selects file-field defaults for a groupfolder.
     *
     * Joins assignments to field definitions and keeps only file fields
     * (applies_to_groupfolder = 0) that actually have a default set. Pure
     * builder — no execution — so it can be asserted in unit tests.
     */
    public function buildDefaultsQuery(int $groupfolderId): IQueryBuilder {
        $qb = $this->db->getQueryBuilder();
        $qb->select('f.field_name', 'a.default_value')
           ->from('metavox_gf_assigns', 'a')
           ->innerJoin('a', 'metavox_gf_fields', 'f', 'f.id = a.field_id')
           ->where($qb->expr()->eq('a.groupfolder_id', $qb->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)))
           ->andWhere($qb->expr()->isNotNull('a.default_value'))
           ->andWhere($qb->expr()->eq('f.applies_to_groupfolder', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
        return $qb;
    }

    /**
     * Find files in a groupfolder that are missing at least one default value.
     *
     * Uses keyset pagination (fileid > :afterFileId) rather than OFFSET so the
     * cost stays constant across millions of rows. A file "needs" defaults when
     * it has no metadata row for one of the default field names. A blanked cell
     * leaves an (empty) row behind, so it is NOT considered missing — this is
     * how user intent is protected from the backfill.
     *
     * @param string[] $defaultFieldNames the field names that have defaults
     * @return int[] file ids, ascending, up to $limit
     */
    public function findFilesMissingDefaults(int $groupfolderId, array $defaultFieldNames, int $limit, int $afterFileId): array {
        if (empty($defaultFieldNames)) {
            return [];
        }

        // A groupfolder's files live on a dedicated storage whose id is
        // "local::<datadir>/__groupfolders/<gfId>/". Within that storage the
        // files sit under the "files/" path prefix. IMPORTANT: the
        // "__groupfolders/<id>/" segment is part of the STORAGE id, NOT the
        // filecache.path (which is just "files/..."), so we must join
        // oc_storages and match the storage id — filtering on filecache.path
        // alone finds nothing.
        $storageIds = $this->getGroupfolderStorageIds($groupfolderId);
        if (empty($storageIds)) {
            return [];
        }

        // Directory mimetype id is looked up once and used as a plain NOT-equal
        // filter, avoiding correlated subqueries whose support varies across the
        // NC-supported DB engines. Defaults apply to files only, never folders.
        $dirMimeId = $this->getDirectoryMimetypeId();

        $qb = $this->db->getQueryBuilder();

        // A file is "missing defaults" when it lacks a metadata row for AT LEAST
        // ONE of the default fields — not just when it has none. A correlated
        // COUNT of how many of the default fields this file already has, compared
        // to the total number of default fields, captures that. (A plain
        // NOT EXISTS would treat a file that has one default field as fully done
        // and never apply the remaining defaults — the bug this replaces.)
        // The subquery's params are created on THIS builder so getSQL() yields
        // the right placeholders; NC's IExpressionBuilder has no exists()/lt-on-
        // subquery helpers, so this is a raw SQL fragment via createFunction().
        $missingSql = '(' . $this->buildMissingSubquery($qb, $groupfolderId, $defaultFieldNames)->getSQL() . ') < '
            . $qb->createNamedParameter(count($defaultFieldNames), IQueryBuilder::PARAM_INT);

        $qb->selectDistinct('fc.fileid')
           ->from('filecache', 'fc')
           ->where($qb->expr()->gt('fc.fileid', $qb->createNamedParameter($afterFileId, IQueryBuilder::PARAM_INT)))
           ->andWhere($qb->expr()->in('fc.storage', $qb->createNamedParameter($storageIds, IQueryBuilder::PARAM_INT_ARRAY)))
           ->andWhere($qb->expr()->like('fc.path', $qb->createNamedParameter('files/%')))
           ->andWhere($qb->createFunction($missingSql))
           ->orderBy('fc.fileid', 'ASC')
           ->setMaxResults($limit);

        if ($dirMimeId !== null) {
            $qb->andWhere($qb->expr()->neq('fc.mimetype', $qb->createNamedParameter($dirMimeId, IQueryBuilder::PARAM_INT)));
        }

        $result = $qb->executeQuery();
        $ids = [];
        while ($row = $result->fetch()) {
            $ids[] = (int)$row['fileid'];
        }
        $result->closeCursor();

        return $ids;
    }

    /**
     * Cheap "is the backfill done?" check for the status endpoint.
     *
     * Rather than counting every missing file across a potentially huge folder
     * on each poll, this asks for a single missing file. Empty result == the
     * folder is fully defaulted (for now). Uses the same keyset path so it is
     * O(index seek), not O(folder size).
     */
    public function hasMissingDefaults(int $groupfolderId): bool {
        $defaults = $this->getFolderDefaults($groupfolderId);
        if (empty($defaults)) {
            return false;
        }
        $found = $this->findFilesMissingDefaults($groupfolderId, array_keys($defaults), 1, 0);
        return !empty($found);
    }

    /**
     * Resolve the storage numeric_id(s) backing a groupfolder.
     *
     * A groupfolder's storage id ends in "/__groupfolders/<gfId>/", e.g.
     * "local::/var/www/html/data/__groupfolders/1/". The data dir prefix varies
     * per install, so we match on the suffix rather than hardcoding it. Cached
     * per request.
     *
     * @return int[]
     */
    private function getGroupfolderStorageIds(int $groupfolderId): array {
        if (isset($this->storageIdsCache[$groupfolderId])) {
            return $this->storageIdsCache[$groupfolderId];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('numeric_id')
           ->from('storages')
           ->where($qb->expr()->like('id', $qb->createNamedParameter('%/__groupfolders/' . $groupfolderId . '/')));

        $result = $qb->executeQuery();
        $ids = [];
        while ($row = $result->fetch()) {
            $ids[] = (int)$row['numeric_id'];
        }
        $result->closeCursor();

        $this->storageIdsCache[$groupfolderId] = $ids;
        return $ids;
    }

    /** @var array<int, int[]> request-scoped cache of gfId => storage numeric ids */
    private array $storageIdsCache = [];

    /**
     * Correlated subquery: how many of the default fields this file ALREADY has
     * a metadata row for, in this groupfolder. The caller compares this count to
     * the total number of default fields; a file with fewer is missing at least
     * one default. The unique key (file_id, groupfolder_id, field_name) means
     * each default field is counted at most once.
     */
    private function buildMissingSubquery(IQueryBuilder $parent, int $groupfolderId, array $defaultFieldNames): IQueryBuilder {
        $sub = $this->db->getQueryBuilder();
        $sub->select($sub->createFunction('COUNT(*)'))
            ->from('metavox_file_gf_meta', 'm')
            ->where($sub->expr()->eq('m.file_id', 'fc.fileid'))
            ->andWhere($sub->expr()->eq('m.groupfolder_id', $parent->createNamedParameter($groupfolderId, IQueryBuilder::PARAM_INT)))
            ->andWhere($sub->expr()->in('m.field_name', $parent->createNamedParameter($defaultFieldNames, IQueryBuilder::PARAM_STR_ARRAY)));
        return $sub;
    }

    /**
     * Resolve the numeric mimetype id for directories ('httpd/unix-directory'),
     * cached for the request. Returns null if the row is absent (then the
     * caller simply skips the folder filter). The id differs per installation,
     * so it must be looked up rather than hardcoded.
     */
    private ?int $directoryMimetypeId = null;
    private bool $directoryMimetypeResolved = false;

    private function getDirectoryMimetypeId(): ?int {
        if ($this->directoryMimetypeResolved) {
            return $this->directoryMimetypeId;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
           ->from('mimetypes')
           ->where($qb->expr()->eq('mimetype', $qb->createNamedParameter('httpd/unix-directory')));
        $result = $qb->executeQuery();
        $id = $result->fetchOne();
        $result->closeCursor();

        $this->directoryMimetypeId = ($id !== false) ? (int)$id : null;
        $this->directoryMimetypeResolved = true;
        return $this->directoryMimetypeId;
    }

    /**
     * Idempotently write defaults for the given files. Existing values (and
     * blanked-but-present rows) are never overwritten.
     *
     * @param int[] $fileIds
     * @param array<string, string> $defaults field_name => default_value
     * @return int number of rows attempted (file × field combinations)
     */
    public function bulkInsertDefaults(int $groupfolderId, array $fileIds, array $defaults): int {
        if (empty($fileIds) || empty($defaults)) {
            return 0;
        }

        $now = date('Y-m-d H:i:s');
        $isMysql = $this->db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySqlPlatform;

        // Build (fileId, fieldName, value) tuples, chunked to stay under param limits.
        $tuples = [];
        foreach ($fileIds as $fileId) {
            foreach ($defaults as $fieldName => $value) {
                $tuples[] = [(int)$fileId, (string)$fieldName, (string)$value];
            }
        }

        $total = 0;
        foreach (array_chunk($tuples, self::INSERT_ROW_CHUNK) as $chunk) {
            $sql = $this->buildBulkInsertSql(count($chunk), $isMysql);
            $params = [];
            foreach ($chunk as [$fileId, $fieldName, $value]) {
                array_push($params, $fileId, $groupfolderId, $fieldName, $value, $now, $now);
            }
            try {
                $this->db->executeStatement($sql, $params);
                $total += count($chunk);
            } catch (\Exception $e) {
                // Idempotent + safety-net: let discovery retry next run rather
                // than aborting the whole batch.
                $this->logger->error('MetaVox: bulkInsertDefaults chunk failed', [
                    'groupfolderId' => $groupfolderId,
                    'exception' => $e,
                ]);
            }
        }

        return $total;
    }

    /**
     * Build a multi-row INSERT with DO NOTHING semantics on conflict.
     *
     * DO NOTHING (not DO UPDATE) is essential: defaults must never overwrite an
     * existing value. MySQL has no native DO NOTHING, so we use a no-op
     * self-assignment in ON DUPLICATE KEY UPDATE. Pure builder — unit-tested
     * for both dialects.
     */
    public function buildBulkInsertSql(int $rowCount, bool $isMysql): string {
        $placeholders = implode(', ', array_fill(0, $rowCount, '(?, ?, ?, ?, ?, ?)'));
        $head = "INSERT INTO *PREFIX*metavox_file_gf_meta "
              . "(file_id, groupfolder_id, field_name, field_value, created_at, updated_at) "
              . "VALUES $placeholders ";

        if ($isMysql) {
            // No-op update = effectively DO NOTHING, keeps existing value.
            return $head . "ON DUPLICATE KEY UPDATE file_id = file_id";
        }

        return $head . "ON CONFLICT (file_id, groupfolder_id, field_name) DO NOTHING";
    }
}
