<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCA\MetaVox\Access\Access;
use OCA\MetaVox\Access\RequireAccess;
use OCA\MetaVox\Exception\RequestException;
use OCA\MetaVox\L10n\Translatable;
use OCA\MetaVox\Service\BackupReader;
use OCA\MetaVox\Service\FileLinkPathCleanup;
use OCA\MetaVox\Service\MetadataVersions;
use OCA\MetaVox\Service\SearchWordsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\ICacheFactory;
use OCP\ICache;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class BackupController extends Controller {

    use ReadsParams;

    private const TABLES = ['metavox_gf_fields', 'metavox_gf_metadata', 'metavox_file_gf_meta'];
    private const CHUNK_SIZE = 5000;
    private const MAX_BACKUPS = 7;
    private const RESTORE_BATCH_SIZE = 1000;
    private const RESTORE_COMMIT_SIZE = 10000;
    private const STATUS_KEY = 'metavox_backup_status';

    private ICache $cache;

    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IDBConnection $db,
        private readonly IAppData $appData,
        private readonly IConfig $config,
        private readonly LoggerInterface $logger,
        ICacheFactory $cacheFactory,
        private readonly FileLinkPathCleanup $fileLinkCleanup,
        private readonly SearchWordsService $searchWords,
        private readonly MetadataVersions $versions,
    ) {
        parent::__construct($appName, $request);
        $this->cache = $cacheFactory->createDistributed('metavox')
            ?? $cacheFactory->createLocal('metavox');
    }

    // ========================================
    // Status endpoint (polled by frontend)
    // ========================================

    /**
     * Get current backup/restore operation status.
     */
    #[RequireAccess(Access::NcAdmin)]
    public function status(): JSONResponse {
        $status = $this->cache->get(self::STATUS_KEY);
        if (!$status) {
            return new JSONResponse(['status' => 'idle']);
        }
        return new JSONResponse($status);
    }

    /**
     * Refused before anything starts, so the one that runs keeps its status:
     * an error there would show it as failed, and let the next one start.
     *
     * @throws RequestException
     */
    private function refuseWhileRunning(): void {
        $current = $this->cache->get(self::STATUS_KEY);
        if (is_array($current) && ($current['status'] ?? null) === 'running') {
            // TRANSLATORS Error when a backup or restore is started while another one is still running
            throw RequestException::conflict(Translatable::t('A backup or restore is already running'));
        }
    }

    private function setStatus(array $status): void {
        $this->cache->set(self::STATUS_KEY, $status, 3600);
    }

    private function clearStatus(): void {
        $this->cache->remove(self::STATUS_KEY);
    }

    // ========================================
    // List & Download
    // ========================================

    /**
     * List available backups (supports both .json and .json.gz).
     */
    #[RequireAccess(Access::NcAdmin)]
    public function list(): JSONResponse {
        $dir = $this->getBackupDir();
        $files = array_merge(
            glob($dir . '/metavox_backup_*.json.gz') ?: [],
            glob($dir . '/metavox_backup_*.json') ?: []
        );
        rsort($files);

        $backups = [];
        foreach ($files as $filepath) {
            $filename = basename($filepath);
            $isGz = str_ends_with($filepath, '.gz');

            if ($isGz) {
                $gz = gzopen($filepath, 'r');
                $header = gzread($gz, 500);
                gzclose($gz);
            } else {
                $fh = fopen($filepath, 'r');
                $header = fread($fh, 500);
                fclose($fh);
            }

            $meta = $this->parseHeader($header);

            $backups[] = [
                'filename' => $filename,
                'created_at' => $meta['created_at'] ?? null,
                'version' => $meta['version'] ?? 'unknown',
                'size' => filesize($filepath),
                'counts' => $meta['counts'] ?? [],
                'compressed' => $isGz,
            ];
        }

        return new JSONResponse(['backups' => $backups]);
    }

    /**
     * Download a backup file.
     */
    #[NoCSRFRequired]
    #[RequireAccess(Access::NcAdmin)]
    public function download() {
        $filename = $this->textParam('filename') ?? '';
        if (!preg_match('/^metavox_backup_[\w-]+\.json(\.gz)?$/', $filename)) {
            // TRANSLATORS Error when a backup is asked for by a file name that no MetaVox backup can have
            throw RequestException::invalid(Translatable::t('This is no MetaVox backup file name'));
        }

        $path = $this->getBackupDir() . '/' . $filename;
        if (!file_exists($path)) {
            // TRANSLATORS Error when the backup file to download or restore no longer exists
            throw RequestException::notFound(Translatable::t('Backup not found'));
        }

        $isGz = str_ends_with($filename, '.gz');
        $contentType = $isGz ? 'application/gzip' : 'application/json';

        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    // ========================================
    // Backup (export)
    // ========================================

    /**
     * Trigger a manual backup with progress tracking.
     */
    #[RequireAccess(Access::NcAdmin)]
    public function trigger(): JSONResponse {
        $this->refuseWhileRunning();
        try {
            $dir = $this->getBackupDir();
            $filename = 'metavox_backup_' . date('Y-m-d_H-i-s') . '.json.gz';
            $path = $dir . '/' . $filename;

            // Get total counts for progress
            $counts = [];
            $totalRows = 0;
            foreach (self::TABLES as $table) {
                $qb = $this->db->getQueryBuilder();
                $qb->select($qb->func()->count('*', 'cnt'))->from($table);
                $counts[$table] = (int)$qb->executeQuery()->fetchOne();
                $totalRows += $counts[$table];
            }

            $this->setStatus([
                'status' => 'running',
                'operation' => 'export',
                'filename' => $filename,
                'progress' => 0,
                'total' => $totalRows,
                'table' => '',
                'started_at' => date('c'),
            ]);

            $start = microtime(true);
            $this->streamBackupToFile($path, $counts, $totalRows);
            $this->cleanupOldBackups($dir);
            $duration = round(microtime(true) - $start, 1);

            $this->setStatus([
                'status' => 'completed',
                'operation' => 'export',
                'filename' => $filename,
                'progress' => $totalRows,
                'total' => $totalRows,
                'duration' => $duration,
                'size' => filesize($path),
            ]);

            $this->logger->info('MetaVox backup created: {filename} ({size}, {duration}s)', [
                'filename' => $filename,
                'size' => $this->formatBytes(filesize($path)),
                'duration' => $duration,
            ]);

            return new JSONResponse([
                'success' => true,
                'filename' => $filename,
                'counts' => $counts,
                'size' => filesize($path),
                'duration' => $duration,
            ]);
        } catch (\Exception $e) {
            $this->setStatus([
                'status' => 'error',
                'operation' => 'export',
                'error' => 'Internal server error',
                // Read by the admin page, which says it in the reader's language.
                'reason' => 'internal_error',
            ]);
            throw $e;
        }
    }

    // ========================================
    // Restore (import)
    // ========================================

    /**
     * Restore metadata from a backup file with progress tracking.
     */
    #[RequireAccess(Access::NcAdmin)]
    public function restore(): JSONResponse {
        $filename = $this->textParam('filename') ?? '';
        if (!preg_match('/^metavox_backup_[\w-]+\.json(\.gz)?$/', $filename)) {
            throw RequestException::invalid(Translatable::t('This is no MetaVox backup file name'));
        }

        $this->refuseWhileRunning();
        $path = $this->getBackupDir() . '/' . $filename;
        if (!file_exists($path)) {
            throw RequestException::notFound(Translatable::t('Backup not found'));
        }

        try {

            // Read header to get total counts
            $isGz = str_ends_with($path, '.gz');
            if ($isGz) {
                $gz = gzopen($path, 'r');
                $header = gzread($gz, 500);
                gzclose($gz);
            } else {
                $fh = fopen($path, 'r');
                $header = fread($fh, 500);
                fclose($fh);
            }
            $meta = $this->parseHeader($header);
            $totalRows = 0;
            foreach (($meta['counts'] ?? []) as $count) {
                $totalRows += (int)$count;
            }

            $this->setStatus([
                'status' => 'running',
                'operation' => 'import',
                'filename' => $filename,
                'progress' => 0,
                'total' => $totalRows,
                'table' => 'preparing',
                'started_at' => date('c'),
            ]);

            $start = microtime(true);
            $restored = $this->doRestore($path, $isGz, $totalRows);
            // An older backup still has paths in its file links.
            $this->fileLinkCleanup->run();
            $this->searchWords->rebuildAll();
            $this->versions->bumpAll();
            $duration = round(microtime(true) - $start, 1);

            $restoredTotal = array_sum($restored);
            $this->setStatus([
                'status' => 'completed',
                'operation' => 'import',
                'filename' => $filename,
                'progress' => $restoredTotal,
                'total' => $totalRows,
                'duration' => $duration,
                'restored' => $restored,
            ]);

            $this->logger->info('MetaVox restore completed from {filename} in {duration}s', [
                'filename' => $filename,
                'duration' => $duration,
                'fields' => $restored['metavox_gf_fields'],
                'gf_meta' => $restored['metavox_gf_metadata'],
                'file_meta' => $restored['metavox_file_gf_meta'],
            ]);

            return new JSONResponse([
                'success' => true,
                'restored' => $restored,
                'duration' => $duration,
            ]);
        } catch (\Exception $e) {
            $this->setStatus([
                'status' => 'error',
                'operation' => 'import',
                'error' => 'Internal server error',
                // Read by the admin page, which says it in the reader's language.
                'reason' => 'internal_error',
            ]);
            throw $e;
        }
    }

    // ========================================
    // Internal: Backup (export) logic
    // ========================================

    private function streamBackupToFile(string $path, array $counts, int $totalRows): void {
        $gz = gzopen($path, 'wb6');
        $processed = 0;

        gzwrite($gz, '{"version":' . json_encode($this->getAppVersion()));
        gzwrite($gz, ',"created_at":' . json_encode(date('c')));
        gzwrite($gz, ',"counts":' . json_encode($counts));
        gzwrite($gz, ',"tables":{');

        $firstTable = true;
        foreach (self::TABLES as $table) {
            if (!$firstTable) {
                gzwrite($gz, ',');
            }
            $firstTable = false;

            gzwrite($gz, json_encode($table) . ':[');
            $processed = $this->streamTable($gz, $table, $processed, $totalRows);
            gzwrite($gz, ']');
        }

        gzwrite($gz, '}}');
        gzclose($gz);
    }

    private function streamTable($gz, string $table, int $processed, int $totalRows): int {
        $lastId = 0;
        $firstRow = true;

        do {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
               ->from($table)
               ->where($qb->expr()->gt('id', $qb->createNamedParameter($lastId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
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
                $processed++;
            }
            $result->closeCursor();

            // Update progress every chunk
            if ($chunkSize > 0) {
                $this->setStatus([
                    'status' => 'running',
                    'operation' => 'export',
                    'progress' => $processed,
                    'total' => $totalRows,
                    'table' => $table,
                    'started_at' => $this->cache->get(self::STATUS_KEY)['started_at'] ?? date('c'),
                ]);
            }
        } while ($chunkSize === self::CHUNK_SIZE);

        return $processed;
    }

    // ========================================
    // Internal: Restore (import) logic
    // ========================================

    private function doRestore(string $path, bool $isGz, int $totalRows): array {
        $restored = [];
        $dbProvider = $this->db->getDatabaseProvider();
        $isPostgres = $dbProvider === IDBConnection::PLATFORM_POSTGRES;
        $q = $isPostgres ? '"' : '`';
        $prefix = $this->config->getSystemValue('dbtableprefix', 'oc_');

        // TRUNCATE tables
        foreach (self::TABLES as $table) {
            $fullTable = $q . $prefix . $table . $q;
            try {
                $this->db->executeStatement("TRUNCATE TABLE {$fullTable}");
            } catch (\Exception $e) {
                $qb = $this->db->getQueryBuilder();
                $qb->delete($table);
                $qb->executeStatement();
            }
            $restored[$table] = 0;
        }

        // Disable indexes (MySQL only)
        if (!$isPostgres) {
            foreach (self::TABLES as $table) {
                $fullTable = $q . $prefix . $table . $q;
                try { $this->db->executeStatement("ALTER TABLE {$fullTable} DISABLE KEYS"); } catch (\Exception $e) {}
            }
        }

        // Open file
        if ($isGz) {
            $fh = gzopen($path, 'r');
        } else {
            $fh = fopen($path, 'r');
        }

        $readLine = $isGz
            ? function() use ($fh) { return gzgets($fh); }
            : function() use ($fh) { return fgets($fh); };
        $closeFh = $isGz
            ? function() use ($fh) { gzclose($fh); }
            : function() use ($fh) { fclose($fh); };

        $currentTable = null;
        $batch = [];
        $batchColumns = null;
        $uncommitted = 0;
        $totalProcessed = 0;
        $lines = (function () use ($readLine) {
            while (($line = $readLine()) !== false) {
                yield $line;
            }
        })();

        $this->db->beginTransaction();

        try {
            foreach (BackupReader::rows($lines, self::TABLES) as [$table, $data]) {
                if ($table !== $currentTable) {
                    if (!empty($batch)) {
                        $this->flushBatch($currentTable, $batch, $batchColumns, $restored);
                        $totalProcessed += count($batch);
                        $batch = [];
                    }
                    $currentTable = $table;
                    $batchColumns = null;
                }

                $row = $this->restorableColumns($table, $data);
                if ($row === null) {
                    continue;
                }
                if ($batchColumns === null) $batchColumns = array_keys($row);
                $batch[] = $row;
                $uncommitted++;

                if (count($batch) >= self::RESTORE_BATCH_SIZE) {
                    $this->flushBatch($currentTable, $batch, $batchColumns, $restored);
                    $totalProcessed += count($batch);
                    $batch = [];

                    // Update progress
                    $this->setStatus([
                        'status' => 'running',
                        'operation' => 'import',
                        'progress' => $totalProcessed,
                        'total' => $totalRows,
                        'table' => $currentTable,
                        'started_at' => $this->cache->get(self::STATUS_KEY)['started_at'] ?? date('c'),
                    ]);
                }

                if ($uncommitted >= self::RESTORE_COMMIT_SIZE) {
                    $this->db->commit();
                    $this->db->beginTransaction();
                    $uncommitted = 0;
                }
            }

            // Final flush
            if (!empty($batch) && $currentTable) {
                $this->flushBatch($currentTable, $batch, $batchColumns, $restored);
            }

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            $closeFh();
            if (!$isPostgres) {
                foreach (self::TABLES as $table) {
                    $fullTable = $q . $prefix . $table . $q;
                    try { $this->db->executeStatement("ALTER TABLE {$fullTable} ENABLE KEYS"); } catch (\Exception $e2) {}
                }
            }
            throw $e;
        }

        $closeFh();

        // Re-enable indexes (MySQL only)
        if (!$isPostgres) {
            foreach (self::TABLES as $table) {
                $fullTable = $q . $prefix . $table . $q;
                try {
                    $this->db->executeStatement("ALTER TABLE {$fullTable} ENABLE KEYS");
                } catch (\Exception $e) {}
            }
        }

        return $restored;
    }

    // ========================================
    // Internal: Helpers
    // ========================================

    /** The row with only the columns a restore may write, or null when none is left. */
    private function restorableColumns(string $table, array $row): ?array {
        $row = array_intersect_key($row, array_flip($this->getAllowedColumns($table)));
        return !empty($row) ? $row : null;
    }

    private function flushBatch(string $table, array $batch, ?array $columns, array &$restored): void {
        if (empty($batch) || empty($columns)) return;

        $dbProvider = $this->db->getDatabaseProvider();
        $prefix = $this->config->getSystemValue('dbtableprefix', 'oc_');
        $fullTable = $prefix . $table;
        $q = ($dbProvider === IDBConnection::PLATFORM_POSTGRES) ? '"' : '`';

        $colList = $q . implode("{$q},{$q}", $columns) . $q;
        $rowPlaceholder = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $placeholders = implode(',', array_fill(0, count($batch), $rowPlaceholder));

        $sql = "INSERT INTO {$q}{$fullTable}{$q} ({$colList}) VALUES {$placeholders}";

        $params = [];
        foreach ($batch as $row) {
            foreach ($columns as $col) {
                $params[] = $row[$col] ?? null;
            }
        }

        $this->db->executeStatement($sql, $params);
        $restored[$table] += count($batch);
    }

    private function getAllowedColumns(string $table): array {
        static $schemas = [
            'metavox_gf_fields' => ['id', 'field_name', 'field_label', 'field_type', 'field_description', 'field_options', 'is_required', 'sort_order', 'scope', 'applies_to_groupfolder', 'created_at', 'updated_at'],
            'metavox_gf_metadata' => ['id', 'groupfolder_id', 'field_name', 'field_value', 'created_at', 'updated_at'],
            'metavox_file_gf_meta' => ['id', 'file_id', 'groupfolder_id', 'field_name', 'field_value', 'created_at', 'updated_at'],
        ];
        return $schemas[$table] ?? [];
    }

    private function parseHeader(string $header): array {
        $tablesPos = strpos($header, ',"tables"');
        if ($tablesPos !== false) {
            $headerJson = substr($header, 0, $tablesPos) . '}';
            $meta = json_decode($headerJson, true);
            if ($meta) {
                return $meta;
            }
        }
        return [];
    }

    private function getBackupDir(): string {
        try {
            $this->appData->getFolder('backups');
        } catch (NotFoundException) {
            $this->appData->newFolder('backups');
        }

        $dataDir = $this->config->getSystemValue('datadirectory');
        $instanceId = $this->config->getSystemValue('instanceid');
        $dir = $dataDir . '/appdata_' . $instanceId . '/metavox/backups';

        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }

        return $dir;
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
            $appManager = \OCP\Server::get(\OCP\App\IAppManager::class);
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
