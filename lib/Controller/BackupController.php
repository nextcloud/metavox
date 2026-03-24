<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\ICacheFactory;
use OCP\ICache;
use OCP\IDBConnection;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class BackupController extends Controller {

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
        private readonly LoggerInterface $logger,
        ICacheFactory $cacheFactory,
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
    public function status(): JSONResponse {
        $status = $this->cache->get(self::STATUS_KEY);
        if (!$status) {
            return new JSONResponse(['status' => 'idle']);
        }
        return new JSONResponse($status);
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
    public function list(): JSONResponse {
        try {
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
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Download a backup file.
     */
    #[NoCSRFRequired]
    public function download() {
        $filename = $this->request->getParam('filename');
        if (!$filename || !preg_match('/^metavox_backup_[\w-]+\.json(\.gz)?$/', $filename)) {
            return new JSONResponse(['error' => 'Invalid filename'], 400);
        }

        try {
            $path = $this->getBackupDir() . '/' . $filename;
            if (!file_exists($path)) {
                return new JSONResponse(['error' => 'Backup not found'], 404);
            }

            $isGz = str_ends_with($filename, '.gz');
            $contentType = $isGz ? 'application/gzip' : 'application/json';

            header('Content-Type: ' . $contentType);
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    // ========================================
    // Backup (export)
    // ========================================

    /**
     * Trigger a manual backup with progress tracking.
     */
    public function trigger(): JSONResponse {
        try {
            // Prevent concurrent operations
            $currentStatus = $this->cache->get(self::STATUS_KEY);
            if ($currentStatus && $currentStatus['status'] === 'running') {
                return new JSONResponse(['error' => 'Another backup/restore operation is already running'], 409);
            }

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
                'error' => $e->getMessage(),
            ]);
            $this->logger->error('MetaVox backup failed', ['exception' => $e]);
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    // ========================================
    // Restore (import)
    // ========================================

    /**
     * Restore metadata from a backup file with progress tracking.
     */
    public function restore(): JSONResponse {
        $filename = $this->request->getParam('filename');
        if (!$filename || !preg_match('/^metavox_backup_[\w-]+\.json(\.gz)?$/', $filename)) {
            return new JSONResponse(['error' => 'Invalid filename'], 400);
        }

        try {
            // Prevent concurrent operations
            $currentStatus = $this->cache->get(self::STATUS_KEY);
            if ($currentStatus && $currentStatus['status'] === 'running') {
                return new JSONResponse(['error' => 'Another backup/restore operation is already running'], 409);
            }

            $path = $this->getBackupDir() . '/' . $filename;
            if (!file_exists($path)) {
                return new JSONResponse(['error' => 'Backup not found'], 404);
            }

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
                'error' => $e->getMessage(),
            ]);
            $this->logger->error('MetaVox restore failed', ['exception' => $e]);
            return new JSONResponse(['error' => $e->getMessage()], 500);
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
        $platform = $this->db->getDatabasePlatform();
        $isPostgres = $platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform;
        $q = $isPostgres ? '"' : '`';
        $prefix = \OC::$server->getConfig()->getSystemValue('dbtableprefix', 'oc_');

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

        $this->db->beginTransaction();

        try {
            while (($line = $readLine()) !== false) {
                $line = trim($line);

                // Detect table start
                foreach (self::TABLES as $table) {
                    if (str_contains($line, '"' . $table . '":[')) {
                        if (!empty($batch)) {
                            $this->flushBatch($currentTable, $batch, $batchColumns, $restored);
                            $totalProcessed += count($batch);
                            $batch = [];
                        }
                        $currentTable = $table;
                        $batchColumns = null;

                        $bracketPos = strpos($line, ':[');
                        if ($bracketPos !== false) {
                            $afterBracket = trim(substr($line, $bracketPos + 2));
                            if ($afterBracket && $afterBracket !== ']' && $afterBracket !== ']}' && $afterBracket !== ']}}') {
                                $row = $this->parseLine($currentTable, $afterBracket);
                                if ($row) {
                                    if ($batchColumns === null) $batchColumns = array_keys($row);
                                    $batch[] = $row;
                                }
                            }
                        }
                        continue 2;
                    }
                }

                // Detect table end
                if ($currentTable && (str_starts_with($line, ']') || $line === ']' || $line === '],')) {
                    if (!empty($batch)) {
                        $this->flushBatch($currentTable, $batch, $batchColumns, $restored);
                        $totalProcessed += count($batch);
                        $batch = [];
                    }
                    $currentTable = null;
                    $batchColumns = null;
                    continue;
                }

                // Process row
                if ($currentTable && $line) {
                    $row = $this->parseLine($currentTable, $line);
                    if ($row) {
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

    private function parseLine(string $table, string $line): ?array {
        $line = rtrim($line, ',');
        if (!$line || $line === ']' || $line === ']}' || $line === ']}}') {
            return null;
        }

        $row = json_decode($line, true);
        if (!$row || !is_array($row)) {
            return null;
        }

        $allowedColumns = $this->getAllowedColumns($table);
        $row = array_intersect_key($row, array_flip($allowedColumns));
        return !empty($row) ? $row : null;
    }

    private function flushBatch(string $table, array $batch, ?array $columns, array &$restored): void {
        if (empty($batch) || empty($columns)) return;

        $platform = $this->db->getDatabasePlatform();
        $prefix = \OC::$server->getConfig()->getSystemValue('dbtableprefix', 'oc_');
        $fullTable = $prefix . $table;
        $q = ($platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) ? '"' : '`';

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

        $dataDir = \OC::$server->getConfig()->getSystemValue('datadirectory');
        $instanceId = \OC::$server->getConfig()->getSystemValue('instanceid');
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
