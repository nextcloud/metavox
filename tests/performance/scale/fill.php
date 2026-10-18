<?php

declare(strict_types=1);

// Starts filling MetaVox's search index for the seeded world: this version
// queues its fill job (setup.sh runs it), 2.2.3 and older fill their own
// index right here, file by file in chunks, as their save did.

require '/var/www/html/lib/base.php';

use OCP\IDBConnection;

if (class_exists(\OCA\MetaVox\Service\SearchWordsService::class)) {
    \OCP\Server::get(\OCA\MetaVox\Service\SearchWordsService::class)->rebuildAll();
    exit(0);
}

$db = \OCP\Server::get(IDBConnection::class);
$index = \OCP\Server::get(\OCA\MetaVox\Service\SearchIndexService::class);
$after = 0;
$done = 0;
do {
    $ids = $db->executeQuery('SELECT DISTINCT file_id FROM *PREFIX*metavox_file_gf_meta WHERE file_id > ? ORDER BY file_id LIMIT 1000', [$after])
        ->fetchAll(\PDO::FETCH_COLUMN);
    if ($ids !== []) {
        $index->bulkUpdateFileIndex(array_map('intval', $ids));
        $after = (int)end($ids);
        $done += count($ids);
        if ($done % 100000 === 0) {
            echo date('H:i:s ') . "$done files\n";
        }
    }
} while (count($ids) === 1000);
echo "old search index: $done files\n";
