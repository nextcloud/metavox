<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class BackupController extends Controller {

    private const TABLES = ['metavox_gf_fields', 'metavox_gf_metadata', 'metavox_file_gf_meta'];
    private const CHUNK_SIZE = 5000;
    private const MAX_BACKUPS = 7;

    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IDBConnection $db,
        private readonly IAppData $appData,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

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

                // Read header
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
     * Trigger a manual backup (gzip compressed, streamed to disk).
     */
    public function trigger(): JSONResponse {
        try {
            $dir = $this->getBackupDir();
            $filename = 'metavox_backup_' . date('Y-m-d_H-i-s') . '.json.gz';
            $path = $dir . '/' . $filename;

            $counts = $this->streamBackupToFile($path);
            $this->cleanupOldBackups($dir);

            $this->logger->info('MetaVox backup created: {filename} ({size})', [
                'filename' => $filename,
                'size' => $this->formatBytes(filesize($path)),
            ]);

            return new JSONResponse([
                'success' => true,
                'filename' => $filename,
                'counts' => $counts,
                'size' => filesize($path),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('MetaVox backup failed', ['exception' => $e]);
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

    /**
     * Restore metadata from a backup file.
     * Supports both .json and .json.gz formats.
     */
    public function restore(): JSONResponse {
        $filename = $this->request->getParam('filename');
        if (!$filename || !preg_match('/^metavox_backup_[\w-]+\.json(\.gz)?$/', $filename)) {
            return new JSONResponse(['error' => 'Invalid filename'], 400);
        }

        try {
            $path = $this->getBackupDir() . '/' . $filename;
            if (!file_exists($path)) {
                return new JSONResponse(['error' => 'Backup not found'], 404);
            }

            $isGz = str_ends_with($path, '.gz');
            $this->db->beginTransaction();

            try {
                $restored = [];

                // Clear all tables first
                foreach (self::TABLES as $table) {
                    $qb = $this->db->getQueryBuilder();
                    $qb->delete($table);
                    $qb->executeStatement();
                    $restored[$table] = 0;
                }

                // Open file (gz or plain)
                if ($isGz) {
                    $fh = gzopen($path, 'r');
                } else {
                    $fh = fopen($path, 'r');
                }

                $currentTable = null;
                $readLine = $isGz
                    ? function() use ($fh) { return gzgets($fh); }
                    : function() use ($fh) { return fgets($fh); };
                $closeFh = $isGz
                    ? function() use ($fh) { gzclose($fh); }
                    : function() use ($fh) { fclose($fh); };

                while (($line = $readLine()) !== false) {
                    $line = trim($line);

                    // Detect table start
                    foreach (self::TABLES as $table) {
                        if (str_contains($line, '"' . $table . '":[')) {
                            $currentTable = $table;
                            $bracketPos = strpos($line, ':[');
                            if ($bracketPos !== false) {
                                $afterBracket = trim(substr($line, $bracketPos + 2));
                                if ($afterBracket && $afterBracket !== ']' && $afterBracket !== ']}' && $afterBracket !== ']}}') {
                                    $this->restoreRow($currentTable, $afterBracket, $restored);
                                }
                            }
                            continue 2;
                        }
                    }

                    // Detect table end
                    if ($currentTable && (str_starts_with($line, ']') || $line === ']' || $line === '],')) {
                        $currentTable = null;
                        continue;
                    }

                    // Process row
                    if ($currentTable && $line) {
                        $this->restoreRow($currentTable, $line, $restored);
                    }
                }

                $closeFh();
                $this->db->commit();

                $this->logger->info('MetaVox restore completed from {filename}', [
                    'filename' => $filename,
                    'fields' => $restored['metavox_gf_fields'],
                    'gf_meta' => $restored['metavox_gf_metadata'],
                    'file_meta' => $restored['metavox_file_gf_meta'],
                ]);

                return new JSONResponse([
                    'success' => true,
                    'restored' => $restored,
                ]);
            } catch (\Exception $e) {
                $this->db->rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            $this->logger->error('MetaVox restore failed', ['exception' => $e]);
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Parse and insert a single row from the backup file.
     * Validates column names against the known schema.
     */
    private function restoreRow(string $table, string $line, array &$restored): void {
        $line = rtrim($line, ',');
        if (!$line || $line === ']' || $line === ']}' || $line === ']}}') {
            return;
        }

        $row = json_decode($line, true);
        if (!$row || !is_array($row)) {
            return;
        }

        // Validate column names against known schema
        $allowedColumns = $this->getAllowedColumns($table);
        $row = array_intersect_key($row, array_flip($allowedColumns));
        if (empty($row)) {
            return;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->insert($table);
        $values = [];
        foreach ($row as $column => $value) {
            $values[$column] = $qb->createNamedParameter($value);
        }
        $qb->values($values);
        $qb->executeStatement();
        $restored[$table]++;
    }

    /**
     * Get allowed column names for a table (whitelist).
     */
    private function getAllowedColumns(string $table): array {
        static $schemas = [
            'metavox_gf_fields' => ['id', 'field_name', 'field_label', 'field_type', 'field_description', 'field_options', 'is_required', 'sort_order', 'scope', 'applies_to_groupfolder', 'created_at', 'updated_at'],
            'metavox_gf_metadata' => ['id', 'groupfolder_id', 'field_name', 'field_value', 'created_at', 'updated_at'],
            'metavox_file_gf_meta' => ['id', 'file_id', 'groupfolder_id', 'field_name', 'field_value', 'created_at', 'updated_at'],
        ];
        return $schemas[$table] ?? [];
    }

    /**
     * Stream all tables to a gzip compressed JSON file.
     */
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

    private function streamTable($gz, string $table): void {
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
            }
            $result->closeCursor();
        } while ($chunkSize === self::CHUNK_SIZE);
    }

    /**
     * Parse the JSON header to extract metadata.
     */
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
