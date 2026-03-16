<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\ICacheFactory;
use OCP\ICache;

class ViewService {

    private IDBConnection $db;
    private ICache $cache;

    public function __construct(IDBConnection $db, ICacheFactory $cacheFactory) {
        $this->db = $db;
        $this->cache = $cacheFactory->createDistributed('metavox');
    }

    public function getViewsForGroupfolder(int $gfId): array {
        $cacheKey = "gf_{$gfId}_views";
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('metavox_gf_views')
           ->where($qb->expr()->eq('gf_id', $qb->createNamedParameter($gfId, IQueryBuilder::PARAM_INT)))
           ->orderBy('id', 'ASC');

        $result = $qb->executeQuery();
        $views = [];
        while ($row = $result->fetch()) {
            $views[] = $this->rowToArray($row);
        }
        $result->closeCursor();

        // Enrich view columns with field data (field_type, field_options) from metavox_gf_fields
        $views = $this->enrichViewColumns($gfId, $views);

        $this->cache->set($cacheKey, $views, 600);
        return $views;
    }

    public function getView(int $viewId, int $gfId): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('metavox_gf_views')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($viewId, IQueryBuilder::PARAM_INT)))
           ->andWhere($qb->expr()->eq('gf_id', $qb->createNamedParameter($gfId, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        if (!$row) {
            return null;
        }

        $view = $this->rowToArray($row);
        $enriched = $this->enrichViewColumns($gfId, [$view]);
        return $enriched[0];
    }

    public function createView(
        int $gfId,
        string $name,
        bool $isDefault,
        array $columns,
        array $filters,
        ?string $sortField,
        ?string $sortOrder
    ): array {
        if ($isDefault) {
            $this->clearDefaultForGroupfolder($gfId);
        }

        $now = new \DateTime();
        $qb = $this->db->getQueryBuilder();
        $qb->insert('metavox_gf_views')
           ->values([
               'gf_id'      => $qb->createNamedParameter($gfId, IQueryBuilder::PARAM_INT),
               'name'       => $qb->createNamedParameter($name),
               'is_default' => $qb->createNamedParameter($isDefault ? 1 : 0, IQueryBuilder::PARAM_INT),
               'columns'    => $qb->createNamedParameter(json_encode($columns)),
               'filters'    => $qb->createNamedParameter(json_encode($filters)),
               'sort_field' => $qb->createNamedParameter($sortField),
               'sort_order' => $qb->createNamedParameter($sortOrder),
               'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE),
               'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE),
           ]);

        $qb->executeStatement();
        $newId = $this->db->lastInsertId('metavox_gf_views');

        $this->cache->remove("gf_{$gfId}_views");
        return $this->getView((int)$newId, $gfId);
    }

    public function updateView(
        int $viewId,
        int $gfId,
        string $name,
        bool $isDefault,
        array $columns,
        array $filters,
        ?string $sortField,
        ?string $sortOrder
    ): array {
        if ($isDefault) {
            $this->clearDefaultForGroupfolder($gfId, $viewId);
        }

        $now = new \DateTime();
        $qb = $this->db->getQueryBuilder();
        $qb->update('metavox_gf_views')
           ->set('name', $qb->createNamedParameter($name))
           ->set('is_default', $qb->createNamedParameter($isDefault ? 1 : 0, IQueryBuilder::PARAM_INT))
           ->set('columns', $qb->createNamedParameter(json_encode($columns)))
           ->set('filters', $qb->createNamedParameter(json_encode($filters)))
           ->set('sort_field', $qb->createNamedParameter($sortField))
           ->set('sort_order', $qb->createNamedParameter($sortOrder))
           ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE))
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($viewId, IQueryBuilder::PARAM_INT)))
           ->andWhere($qb->expr()->eq('gf_id', $qb->createNamedParameter($gfId, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();

        $this->cache->remove("gf_{$gfId}_views");
        return $this->getView($viewId, $gfId);
    }

    public function deleteView(int $viewId, int $gfId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('metavox_gf_views')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($viewId, IQueryBuilder::PARAM_INT)))
           ->andWhere($qb->expr()->eq('gf_id', $qb->createNamedParameter($gfId, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();
        $this->cache->remove("gf_{$gfId}_views");
    }

    /**
     * Remove references to deleted/unassigned fields from all views of a groupfolder.
     * Called after field assignments change.
     *
     * @param int   $gfId             The groupfolder ID
     * @param int[] $activeFieldIds   Field IDs still assigned to this groupfolder
     * @param string[] $activeFieldNames  Field names still assigned (for sort_field check)
     */
    public function pruneViewsForGroupfolder(int $gfId, array $activeFieldIds, array $activeFieldNames): void {
        $views = $this->getViewsForGroupfolder($gfId);

        foreach ($views as $view) {
            $changed = false;

            // Filter columns — keep only entries whose field_id is still active
            $newColumns = array_values(array_filter($view['columns'] ?? [], function ($c) use ($activeFieldIds) {
                return in_array((int)($c['field_id'] ?? 0), $activeFieldIds, true);
            }));
            if (count($newColumns) !== count($view['columns'] ?? [])) {
                $changed = true;
            }

            // Filter filters — keys are field_ids (stored as strings)
            $newFilters = array_filter($view['filters'] ?? [], function ($fieldId) use ($activeFieldIds) {
                return in_array((int)$fieldId, $activeFieldIds, true);
            }, ARRAY_FILTER_USE_KEY);
            if (count($newFilters) !== count($view['filters'] ?? [])) {
                $changed = true;
            }

            // Reset sort_field if its field_name is no longer active
            $sortField = $view['sort_field'] ?? null;
            if ($sortField !== null && $sortField !== '' && !in_array($sortField, $activeFieldNames, true)) {
                $sortField = null;
                $changed = true;
            }

            if ($changed) {
                $qb = $this->db->getQueryBuilder();
                $qb->update('metavox_gf_views')
                   ->set('columns', $qb->createNamedParameter(json_encode(array_values($newColumns))))
                   ->set('filters', $qb->createNamedParameter(json_encode((object)$newFilters)))
                   ->set('sort_field', $qb->createNamedParameter($sortField))
                   ->set('updated_at', $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE))
                   ->where($qb->expr()->eq('id', $qb->createNamedParameter($view['id'], IQueryBuilder::PARAM_INT)))
                   ->andWhere($qb->expr()->eq('gf_id', $qb->createNamedParameter($gfId, IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            }
        }

        $this->cache->remove("gf_{$gfId}_views");
    }

    /**
     * Enrich view columns with field metadata (field_type, field_options) from metavox_gf_fields.
     * Older views may not have these properties stored in their columns JSON.
     */
    private function enrichViewColumns(int $gfId, array $views): array {
        // Collect all field_ids referenced in view columns
        $fieldIds = [];
        foreach ($views as $view) {
            foreach ($view['columns'] ?? [] as $col) {
                if (isset($col['field_id'])) {
                    $fieldIds[(int)$col['field_id']] = true;
                }
            }
        }
        if (empty($fieldIds)) {
            return $views;
        }

        // Load field metadata in one query
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'field_type', 'field_options')
           ->from('metavox_gf_fields')
           ->where($qb->expr()->in('id', $qb->createNamedParameter(
               array_keys($fieldIds),
               IQueryBuilder::PARAM_INT_ARRAY
           )));
        $result = $qb->executeQuery();
        $fieldMap = [];
        while ($row = $result->fetch()) {
            $fieldMap[(int)$row['id']] = [
                'field_type' => $row['field_type'],
                'field_options' => $row['field_options'] ? json_decode($row['field_options'], true) : [],
            ];
        }
        $result->closeCursor();

        // Merge into view columns
        foreach ($views as &$view) {
            foreach ($view['columns'] as &$col) {
                $fid = (int)($col['field_id'] ?? 0);
                if (isset($fieldMap[$fid])) {
                    if (!isset($col['field_type']) || $col['field_type'] === '') {
                        $col['field_type'] = $fieldMap[$fid]['field_type'];
                    }
                    if (!isset($col['field_options']) || empty($col['field_options'])) {
                        $col['field_options'] = $fieldMap[$fid]['field_options'];
                    }
                }
            }
            unset($col);
        }
        unset($view);

        return $views;
    }

    private function clearDefaultForGroupfolder(int $gfId, ?int $excludeViewId = null): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('metavox_gf_views')
           ->set('is_default', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
           ->where($qb->expr()->eq('gf_id', $qb->createNamedParameter($gfId, IQueryBuilder::PARAM_INT)))
           ->andWhere($qb->expr()->eq('is_default', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));

        if ($excludeViewId !== null) {
            $qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($excludeViewId, IQueryBuilder::PARAM_INT)));
        }

        $qb->executeStatement();
    }

    private function rowToArray(array $row): array {
        return [
            'id'         => (int)$row['id'],
            'gf_id'      => (int)$row['gf_id'],
            'name'       => $row['name'],
            'is_default' => (bool)$row['is_default'],
            'columns'    => $row['columns'] ? json_decode($row['columns'], true) : [],
            'filters'    => $row['filters'] ? json_decode($row['filters'], true) : [],
            'sort_field' => $row['sort_field'] ?? null,
            'sort_order' => $row['sort_order'] ?? null,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }
}
