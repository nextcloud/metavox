<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class ViewService {

    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    public function getViewsForGroupfolder(int $gfId): array {
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

        return $this->rowToArray($row);
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

        return $this->getView($viewId, $gfId);
    }

    public function deleteView(int $viewId, int $gfId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('metavox_gf_views')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($viewId, IQueryBuilder::PARAM_INT)))
           ->andWhere($qb->expr()->eq('gf_id', $qb->createNamedParameter($gfId, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();
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
