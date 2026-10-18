<?php

declare(strict_types=1);

// Query plans of MetaVox's queries on a real database. Reads a profiler export
// of run.mjs's requests, takes the queries MetaVox ran on its own tables, and
// asks the database how it would run them. Fails when a MetaVox table has no
// index the query can use: on MySQL/MariaDB a full scan without possible keys,
// on PostgreSQL a sequential scan even with sequential scans turned off.
// usage: php explain.php <nextcloud dir> <export.json>

require $argv[1] . '/lib/base.php';

use OCP\IDBConnection;

// Known, with the reason, matched on the SQL without quotes; held as they are.
const KNOWN_SCANS = [
    'SELECT * FROM oc_metavox_gf_fields ORDER BY sort_order' => 'all field definitions: a small table, read whole on purpose and cached',
    'SELECT field_name, field_label FROM oc_metavox_gf_fields' => 'the labels of all fields for search results: the same small table',
    'SELECT id, field_name, field_type FROM oc_metavox_gf_fields' => 'the type of every field, to know which values get search words: the same small table',
];

$db = \OCP\Server::get(IDBConnection::class);
$provider = $db->getDatabaseProvider();
if (!in_array($provider, [IDBConnection::PLATFORM_MYSQL, IDBConnection::PLATFORM_POSTGRES], true)) {
    echo "Query plans are checked on MySQL/MariaDB and PostgreSQL only, not on $provider.\n";
    exit(0);
}
$isMysql = $provider === IDBConnection::PLATFORM_MYSQL;
if (!$isMysql) {
    // Then a sequential scan in the plan means there is no index to use at all.
    $db->executeStatement('SET enable_seqscan = off');
}

$fromMetaVox = static fn(array $query): bool => array_filter($query['backtrace'] ?? [], static fn($f) => str_contains((string)($f['file'] ?? ''), '/apps/metavox/lib/')) !== [];

/** @return array<string, string> alias => table, for the MetaVox tables in the query */
function metavoxTables(string $sql): array {
    preg_match_all('/(?:FROM|JOIN)\s+[`"]?(\w*metavox_\w+)[`"]?(?:\s+(?:AS\s+)?[`"]?(\w+)[`"]?)?/i', $sql, $m, PREG_SET_ORDER);
    $tables = [];
    foreach ($m as $match) {
        $alias = $match[2] ?? '';
        if ($alias === '' || in_array(strtoupper($alias), ['WHERE', 'INNER', 'LEFT', 'JOIN', 'ON', 'GROUP', 'ORDER', 'LIMIT'], true)) {
            $alias = $match[1];
        }
        $tables[$alias] = $match[1];
    }
    return $tables;
}

$seen = [];
$problems = [];
$checked = 0;
foreach (json_decode(file_get_contents($argv[2]), true) as $profile) {
    foreach ($profile['collectors']['db']['queries'] ?? [] as $query) {
        $sql = (string)$query['sql'];
        $tables = metavoxTables($sql);
        if ($tables === [] || !$fromMetaVox($query) || !preg_match('/^\s*SELECT/i', $sql) || isset($seen[$sql])) {
            continue;
        }
        $seen[$sql] = true;
        $plain = str_replace(['`', '"'], '', $sql);
        if (array_filter(array_keys(KNOWN_SCANS), static fn(string $pattern): bool => str_contains($plain, $pattern)) !== []) {
            $knownCount = ($knownCount ?? 0) + 1;
            continue;
        }
        try {
            $plan = $db->executeQuery('EXPLAIN ' . $sql, $query['params'] ?? [], $query['types'] ?? [])->fetchAll();
        } catch (\Throwable $e) {
            $problems[] = "could not explain: " . substr($sql, 0, 160) . "\n      " . $e->getMessage();
            continue;
        }
        $checked++;
        $scans = [];
        foreach ($plan as $row) {
            if ($isMysql) {
                $table = $tables[$row['table'] ?? ''] ?? null;
                if ($table !== null && ($row['type'] ?? '') === 'ALL' && empty($row['possible_keys'])) {
                    $scans[] = $table;
                }
            } elseif (preg_match('/Seq Scan on (\w*metavox_\w+)/', (string)reset($row), $m)) {
                $scans[] = $m[1];
            }
        }
        if ($scans !== []) {
            $problems[] = 'no index for ' . implode(', ', array_unique($scans)) . ': ' . substr($sql, 0, 200);
        }
    }
}

// Background work the requests above don't do: the search for files without
// their defaults, one range of file ids. Here the file cache counts too: it is
// read by id range, and must not be scanned whole.
$folderId = (int)$db->executeQuery('SELECT folder_id FROM *PREFIX*group_folders ORDER BY folder_id')->fetchOne();
$discovery = $folderId > 0
    ? \OCP\Server::get(\OCA\MetaVox\Service\DefaultsService::class)->missingDefaultsQuery($folderId, ['file_gf_perf_text'], 250, 0, 10000)
    : null;
if ($discovery === null) {
    $problems[] = 'discovery of missing defaults: no team folder to explain it on';
} else {
    $sql = $discovery->getSQL();
    $plan = $db->executeQuery('EXPLAIN ' . $sql, $discovery->getParameters(), $discovery->getParameterTypes())->fetchAll();
    $checked++;
    $scans = [];
    foreach ($plan as $row) {
        if ($isMysql) {
            if (($row['type'] ?? '') === 'ALL' && empty($row['possible_keys'])) {
                $scans[] = (string)($row['table'] ?? '?');
            }
        } elseif (preg_match('/Seq Scan on (\w*(?:filecache|metavox_\w+))/', (string)reset($row), $m)) {
            $scans[] = $m[1];
        }
    }
    if ($scans !== []) {
        $problems[] = 'discovery of missing defaults scans ' . implode(', ', array_unique($scans)) . ': ' . substr($sql, 0, 200);
    }
}

echo "$checked MetaVox queries explained on " . ($isMysql ? 'MySQL/MariaDB' : 'PostgreSQL') . ', ' . ($knownCount ?? 0) . " known ones skipped.\n";
if ($problems !== []) {
    echo "\nQueries without a usable index:\n  - " . implode("\n  - ", $problems) . "\n";
    exit(1);
}
echo "OK: every MetaVox query can use an index.\n";
