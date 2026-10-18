<?php

declare(strict_types=1);

namespace OCA\MetaVox\Access;

use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The files of a team folder a user may read, as a query condition: all of
 * them, all except what lies under forbidden paths, or all except a list of
 * hidden files. Built by FileAccessService::readableScope().
 */
final class ReadableScope {

    /**
     * @param list<array{storage: int, path: string}> $forbidden filecache paths the user can't read, with all below them
     * @param list<int> $hidden file ids the user can't read
     */
    private function __construct(
        private readonly array $forbidden,
        private readonly array $hidden,
    ) {
    }

    public static function everything(): self {
        return new self([], []);
    }

    /** @param list<array{storage: int, path: string}> $forbidden */
    public static function exceptPaths(array $forbidden): self {
        return new self(array_values($forbidden), []);
    }

    /** @param int[] $hidden */
    public static function exceptFiles(array $hidden): self {
        return new self([], array_values(array_unique(array_map('intval', $hidden))));
    }

    public function isEverything(): bool {
        return $this->forbidden === [] && $this->hidden === [];
    }

    /** For cache keys: the same query gives other results in another scope. */
    public function key(): string {
        return $this->isEverything() ? 'all' : md5(json_encode([$this->forbidden, $this->hidden]));
    }

    /** Keeps only readable files in a query builder query. */
    public function restrict(IQueryBuilder $qb, string $fileIdColumn): void {
        if ($this->isEverything()) {
            return;
        }
        $qb->andWhere($this->condition($fileIdColumn, $qb->getTableName('filecache'), '',
            fn ($value, $type) => $qb->createNamedParameter($value, $type)));
    }

    /**
     * The same on a filecache row the query joins anyway (alias.fileid,
     * .storage, .path), without a subquery. Null when all is readable.
     */
    public function onRow(IQueryBuilder $qb, string $alias): ?string {
        return $this->condition($alias . '.fileid', null, $alias . '.',
            fn ($value, $type) => $qb->createNamedParameter($value, $type));
    }

    /**
     * The same for hand-written SQL: a condition to AND into the WHERE clause,
     * its ? parameters appended to $params and $types. Null when all is readable.
     */
    public function sql(string $fileIdColumn, array &$params, array &$types): ?string {
        return $this->condition($fileIdColumn, '*PREFIX*filecache', '', function ($value, $type) use (&$params, &$types) {
            $params[] = $value;
            $types[] = $type === IQueryBuilder::PARAM_INT ? \PDO::PARAM_INT : \PDO::PARAM_STR;
            return '?';
        });
    }

    /** $filecache null: on the row itself, its columns prefixed with $prefix. */
    private function condition(string $column, ?string $filecache, string $prefix, callable $param): ?string {
        $parts = [];
        if ($this->hidden !== []) {
            $ids = array_map(fn (int $id) => $param($id, IQueryBuilder::PARAM_INT), $this->hidden);
            $parts[] = $column . ' NOT IN (' . implode(', ', $ids) . ')';
        }
        if ($this->forbidden !== []) {
            // Like groupfolders' own search filter: the path itself and everything below it.
            // '!' as escape character works the same on MySQL, PostgreSQL and SQLite.
            $paths = array_map(fn (array $f) => '(' . $prefix . 'storage = ' . $param($f['storage'], IQueryBuilder::PARAM_INT)
                . ' AND (' . $prefix . 'path = ' . $param($f['path'], IQueryBuilder::PARAM_STR)
                . ' OR ' . $prefix . 'path LIKE ' . $param(self::escapeLike($f['path']) . '/%', IQueryBuilder::PARAM_STR) . " ESCAPE '!'))",
                $this->forbidden);
            $parts[] = $filecache === null
                ? 'NOT (' . implode(' OR ', $paths) . ')'
                : $column . ' NOT IN (SELECT fileid FROM ' . $filecache . ' WHERE ' . implode(' OR ', $paths) . ')';
        }
        return $parts === [] ? null : '(' . implode(' AND ', $parts) . ')';
    }

    private static function escapeLike(string $value): string {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
