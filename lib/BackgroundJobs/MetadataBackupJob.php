<?php

declare(strict_types=1);

namespace OCA\MetaVox\BackgroundJobs;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Daily automatic backup of all metadata tables.
 *
 * Gzip compressed, keyset paginated for performance with millions of rows.
 * Stores backups in appdata_xxx/metavox/backups/.
 * Keeps a rolling window of 7 backups.
 */
class MetadataBackupJob extends TimedJob {

    private const MAX_BACKUPS = 7;
    private const TABLES = ['metavox_gf_fields', 'metavox_gf_metadata', 'metavox_file_gf_meta'];
    private const CHUNK_SIZE = 5000;

    public function __construct(
        ITimeFactory $time,
        private readonly IDBConnection $db,
        private readonly IAppData $appData,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(86400);
    }

    protected function run(mixed $argument): void {
        try {
            $start = microtime(true);
            $backupDir = $this->ensureBackupDir();

            $filename = 'metavox_backup_' . date('Y-m-d') . '.json.gz';
            $path = $backupDir . '/' . $filename;

            $counts = $this->streamBackupToFile($path);
            $this->cleanupOldBackups($backupDir);

            $duration = round(microtime(true) - $start, 1);
            $size = $this->formatBytes(filesize($path));

            $this->logger->info('MetaVox backup created: {filename} ({size}, {duration}s) — {fields} fields, {gf_meta} gf metadata, {file_meta} file metadata', [
                'filename' => $filename,
                'size' => $size,
                'duration' => $duration,
                'fields' => $counts['metavox_gf_fields'],
                'gf_meta' => $counts['metavox_gf_metadata'],
                'file_meta' => $counts['metavox_file_gf_meta'],
            ]);
        } catch (\Exception $e) {
            $this->logger->error('MetaVox backup error', ['exception' => $e]);
        }
    }

    private function streamBackupToFile(string $path): array {
        $gz = gzopen($path, 'wb6');
        $counts = [];

        gzwrite($gz, '{"version":' . json_encode($this->getAppVersion()));
        gzwrite($gz, ',"created_at":' . json_encode(date('c')));

        foreach (self::TABLES as $table) {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('*', 'cnt'))->from($table);
            $counts[$table] = (int)$qb->executeQuery()->fetchOne();
        }

        gzwrite($gz, ',"counts":' . json_encode($counts));
        gzwrite($gz, ',"tables":{');

        $firstTable = true;
        foreach (self::TABLES as $table) {
            if (!$firstTable) {
                gzwrite($gz, ',');
            }
            $firstTable = false;

            gzwrite($gz, json_encode($table) . ':[');
            $this->streamTable($gz, $table);
            gzwrite($gz, ']');
        }

        gzwrite($gz, '}}');
        gzclose($gz);

        return $counts;
    }

    /**
     * Stream a table using keyset pagination (WHERE id > lastId)
     * instead of OFFSET for O(1) per chunk performance.
     */
    private function streamTable($gz, string $table): void {
        $lastId = 0;
        $firstRow = true;

        do {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
               ->from($table)
               ->where($qb->expr()->gt('id', $qb->createNamedParameter($lastId, IQueryBuilder::PARAM_INT)))
               ->orderBy('id', 'ASC')
               ->setMaxResults(self::CHUNK_SIZE);

            $result = $qb->executeQuery();
            $chunkSize = 0;

            while ($row = $result->fetch()) {
                if (!$firstRow) {
                    gzwrite($gz, ",\n");
                }
                $firstRow = false;
                gzwrite($gz, json_encode($row, JSON_UNESCAPED_UNICODE));
                $lastId = (int)$row['id'];
                $chunkSize++;
            }
            $result->closeCursor();
        } while ($chunkSize === self::CHUNK_SIZE);
    }

    private function ensureBackupDir(): string {
        try {
            $this->appData->getFolder('backups');
        } catch (NotFoundException) {
            $this->appData->newFolder('backups');
        }

        $dataDir = \OC::$server->getConfig()->getSystemValue('datadirectory');
        $instanceId = \OC::$server->getConfig()->getSystemValue('instanceid');
        $backupDir = $dataDir . '/appdata_' . $instanceId . '/metavox/backups';

        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0770, true);
        }

        return $backupDir;
    }

    private function cleanupOldBackups(string $dir): void {
        $files = array_merge(
            glob($dir . '/metavox_backup_*.json.gz') ?: [],
            glob($dir . '/metavox_backup_*.json') ?: []
        );
        sort($files);

        while (\count($files) > self::MAX_BACKUPS) {
            $oldest = array_shift($files);
            unlink($oldest);
        }
    }

    private function getAppVersion(): string {
        try {
            $appManager = \OC::$server->get(\OCP\App\IAppManager::class);
            return $appManager->getAppVersion('metavox');
        } catch (\Exception) {
            return 'unknown';
        }
    }

    private function formatBytes(int $bytes): string {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }
}
