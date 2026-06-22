<?php

declare(strict_types=1);

namespace OCA\metavox\Migration;

use OCA\MetaVox\Service\FileReferenceService;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Convert legacy "File Link" values from a bare path to the stable
 * "<fileid>:<path>" format (issue #73).
 *
 * Before this migration, `filelink` fields stored the file PATH, which breaks
 * when the target is moved or renamed. We now key on the fileid (resolved from
 * filecache) and keep the path only as a display cache. A filelink field may
 * also hold several ';#'-joined references, but only legacy single bare-path
 * values exist at this point, so each row has exactly one token to convert.
 *
 * The conversion is:
 *  - idempotent: values already shaped "<digits>:..." are skipped, so the step
 *    is safe to re-run (it only retries the ones that didn't resolve before);
 *  - lossless on failure: a path that can't be resolved to a fileid is left
 *    untouched (still a bare path) and reported as a warning. Old code keeps
 *    working on those, and the next run can pick them up once resolvable.
 *
 * Path -> fileid uses the groupfolder storage suffix match (the same approach
 * as DefaultsService): the legacy value is a user-home-relative path whose
 * leading segment is the groupfolder's mount point, so we strip the mount
 * point to get the groupfolder-relative path before resolving.
 */
class Version20250101000021 extends SimpleMigrationStep {

    /** Process value rows in batches to keep memory bounded on huge folders. */
    private const BATCH = 1000;

    public function __construct(
        private IDBConnection $db,
        private FileReferenceService $fileReferenceService,
    ) {}

    /**
     * No schema change — field_value stays TEXT and field_type is free text.
     */
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options) {
        return null;
    }

    public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void {
        // Only run if the relevant tables exist (fresh installs have nothing
        // legacy to convert, but the tables are created by earlier migrations).
        $schema = $schemaClosure();
        if (!($schema instanceof ISchemaWrapper)
            || !$schema->hasTable('metavox_file_gf_meta')
            || !$schema->hasTable('metavox_gf_fields')) {
            return;
        }

        $fieldNames = $this->getSingleFilelinkFieldNames();
        if (empty($fieldNames)) {
            $output->info('MetaVox: no filelink fields, nothing to migrate.');
            return;
        }

        $mountPoints = $this->getGroupfolderMountPoints();

        $converted = 0;
        $stale = 0;
        $skipped = 0;
        $lastId = 0;

        while (true) {
            $rows = $this->fetchValueBatch($fieldNames, $lastId);
            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $lastId = (int)$row['id'];
                $value = (string)$row['field_value'];

                if ($value === '') {
                    continue;
                }
                if (FileReferenceService::isMigrated($value)) {
                    $skipped++;
                    continue;
                }

                $gfId = (int)$row['groupfolder_id'];
                $relPath = $this->toGroupfolderRelativePath($value, $gfId, $mountPoints);
                $fileId = $relPath !== null
                    ? $this->fileReferenceService->resolvePathToFileIdInGroupfolder($relPath, $gfId)
                    : null;

                if ($fileId === null) {
                    $stale++;
                    $output->warning(sprintf(
                        'MetaVox: could not resolve filelink path to fileid (row id=%d, gf=%d, value=%s) — left as-is.',
                        (int)$row['id'], $gfId, $value
                    ));
                    continue;
                }

                $this->updateValue((int)$row['id'], FileReferenceService::formatToken($fileId, $value));
                $converted++;
            }

            if (count($rows) < self::BATCH) {
                break;
            }
        }

        $output->info(sprintf(
            'MetaVox filelink migration: %d converted, %d already migrated, %d unresolved (left as path).',
            $converted, $skipped, $stale
        ));
    }

    /**
     * @return string[] field_name of every single-value filelink field
     */
    private function getSingleFilelinkFieldNames(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('field_name')
           ->from('metavox_gf_fields')
           ->where($qb->expr()->eq('field_type', $qb->createNamedParameter('filelink')));
        $result = $qb->executeQuery();
        $names = [];
        while ($row = $result->fetch()) {
            $names[] = (string)$row['field_name'];
        }
        $result->closeCursor();
        return $names;
    }

    /**
     * @param string[] $fieldNames
     * @return array<int, array{id: int, groupfolder_id: int, field_value: string}>
     */
    private function fetchValueBatch(array $fieldNames, int $afterId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'groupfolder_id', 'field_value')
           ->from('metavox_file_gf_meta')
           ->where($qb->expr()->in('field_name', $qb->createNamedParameter($fieldNames, IQueryBuilder::PARAM_STR_ARRAY)))
           ->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
           ->andWhere($qb->expr()->neq('field_value', $qb->createNamedParameter('')))
           ->orderBy('id', 'ASC')
           ->setMaxResults(self::BATCH);
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();
        return $rows;
    }

    private function updateValue(int $rowId, string $newValue): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('metavox_file_gf_meta')
           ->set('field_value', $qb->createNamedParameter($newValue))
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($rowId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /**
     * Strip the groupfolder mount point from a legacy user-relative path to get
     * a path relative to the groupfolder root, suitable for filecache lookup.
     *
     * Legacy values came from OC.dialogs.filepicker, which returns a path like
     * "/Projects/Docs/spec.pdf" where "Projects" is the groupfolder mount
     * point. We want "Docs/spec.pdf".
     *
     * @param array<int, string> $mountPoints gfId => mount_point
     */
    private function toGroupfolderRelativePath(string $value, int $groupfolderId, array $mountPoints): ?string {
        $path = '/' . ltrim($value, '/');
        $mount = $mountPoints[$groupfolderId] ?? null;
        if ($mount === null || $mount === '') {
            // No known mount point: best effort, try the path minus leading slash.
            return ltrim($path, '/') !== '' ? ltrim($path, '/') : null;
        }
        $prefix = '/' . trim($mount, '/') . '/';
        $pos = strpos($path, $prefix);
        if ($pos !== false) {
            return substr($path, $pos + strlen($prefix));
        }
        // Mount point not in the path — can't safely map it.
        return null;
    }

    /**
     * @return array<int, string> groupfolder id => mount_point
     */
    private function getGroupfolderMountPoints(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('folder_id', 'mount_point')
           ->from('group_folders');
        $result = $qb->executeQuery();
        $map = [];
        while ($row = $result->fetch()) {
            $map[(int)$row['folder_id']] = (string)$row['mount_point'];
        }
        $result->closeCursor();
        return $map;
    }
}
