<?php

declare(strict_types=1);

namespace OCA\MetaVox\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\StreamResponse;
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
     * List available backups.
     */
    public function list(): JSONResponse {
        try {
            $dir = $this->getBackupDir();
            $files = glob($dir . '/metavox_backup_*.json');
            rsort($files);

            $backups = [];
            foreach ($files as $filepath) {
                $filename = basename($filepath);
                $fh = fopen($filepath, 'r');
                // Read first 500 bytes to get header (version, created_at, counts)
                $header = fread($fh, 500);
                fclose($fh);

                // Extract counts from header JSON
                $meta = $this->parseHeader($header);

                $backups[] = [
                    'filename' => $filename,
                    'created_at' => $meta['created_at'] ?? null,
                    'version' => $meta['version'] ?? 'unknown',
                    'size' => filesize($filepath),
                    'counts' => $meta['counts'] ?? [],
                ];
            }

            return new JSONResponse(['backups' => $backups]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Trigger a manual backup (streams directly to disk).
     */
    public function trigger(): JSONResponse {
        try {
            $dir = $this->getBackupDir();
            $filename = 'metavox_backup_' . date('Y-m-d_H-i-s') . '.json';
            $path = $dir . '/' . $filename;

            $counts = $this->streamBackupToFile($path);
            $this->cleanupOldBackups($dir);

            $this->logger->info('MetaVox manual backup created: {filename}', ['filename' => $filename]);

            return new JSONResponse([
                'success' => true,
                'filename' => $filename,
                'counts' => $counts,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('MetaVox manual backup failed', ['exception' => $e]);
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Download a backup file directly from disk.
     *
     * @NoCSRFRequired
     */
    public function download() {
        $filename = $this->request->getParam('filename');
        if (!$filename || !preg_match('/^metavox_backup_[\w-]+\.json$/', $filename)) {
            return new JSONResponse(['error' => 'Invalid filename'], 400);
        }

        try {
            $path = $this->getBackupDir() . '/' . $filename;
            if (!file_exists($path)) {
                return new JSONResponse(['error' => 'Backup not found'], 404);
            }

            $response = new StreamResponse($path);
            $response->addHeader('Content-Type', 'application/json');
            $response->addHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
            return $response;
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Restore metadata from a backup file.
     * Reads the file line by line — each row is on its own line,
     * so only one row is in memory at a time.
     */
    public function restore(): JSONResponse {
        $filename = $this->request->getParam('filename');
        if (!$filename || !preg_match('/^metavox_backup_[\w-]+\.json$/', $filename)) {
            return new JSONResponse(['error' => 'Invalid filename'], 400);
        }

        try {
            $path = $this->getBackupDir() . '/' . $filename;
            if (!file_exists($path)) {
                return new JSONResponse(['error' => 'Backup not found'], 404);
            }

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

                // Read file line by line and restore
                $fh = fopen($path, 'r');
                $currentTable = null;

                while (($line = fgets($fh)) !== false) {
                    $line = trim($line);

                    // Detect table start: "metavox_gf_fields":[
                    foreach (self::TABLES as $table) {
                        if (str_contains($line, '"' . $table . '":[')) {
                            $currentTable = $table;
                            // The first row may be on the same line after the [
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

                fclose($fh);
                $this->db->commit();

                $this->logger->info('MetaVox restore completed from {filename}: {fields} fields, {gf_meta} groupfolder metadata, {file_meta} file metadata', [
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
     */
    private function restoreRow(string $table, string $line, array &$restored): void {
        // Strip trailing comma
        $line = rtrim($line, ',');
        if (!$line || $line === ']' || $line === ']}' || $line === ']}}') {
            return;
        }

        $row = json_decode($line, true);
        if (!$row || !is_array($row)) {
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
     * Stream all tables to a JSON file row by row.
     */
    private function streamBackupToFile(string $path): array {
        $fh = fopen($path, 'w');
        $counts = [];

        fwrite($fh, '{"version":' . json_encode($this->getAppVersion()));
        fwrite($fh, ',"created_at":' . json_encode(date('c')));

        foreach (self::TABLES as $table) {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('*', 'cnt'))->from($table);
            $counts[$table] = (int)$qb->executeQuery()->fetchOne();
        }

        fwrite($fh, ',"counts":' . json_encode($counts));
        fwrite($fh, ',"tables":{');

        $firstTable = true;
        foreach (self::TABLES as $table) {
            if (!$firstTable) {
                fwrite($fh, ',');
            }
            $firstTable = false;

            fwrite($fh, json_encode($table) . ':[');
            $this->streamTable($fh, $table);
            fwrite($fh, ']');
        }

        fwrite($fh, '}}');
        fclose($fh);

        return $counts;
    }

    private function streamTable($fh, string $table): void {
        $offset = 0;
        $firstRow = true;

        do {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
               ->from($table)
               ->setFirstResult($offset)
               ->setMaxResults(self::CHUNK_SIZE);

            $result = $qb->executeQuery();
            $chunkSize = 0;

            while ($row = $result->fetch()) {
                if (!$firstRow) {
                    fwrite($fh, ",\n");
                }
                $firstRow = false;
                fwrite($fh, json_encode($row, JSON_UNESCAPED_UNICODE));
                $chunkSize++;
            }
            $result->closeCursor();

            $offset += self::CHUNK_SIZE;
        } while ($chunkSize === self::CHUNK_SIZE);
    }

    /**
     * Parse the JSON header (first ~500 bytes) to extract metadata
     * without reading the entire file.
     */
    private function parseHeader(string $header): array {
        // The counts JSON ends with },"tables": — find that boundary
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

    /**
     * Get the filesystem path to the backup directory.
     */
    private function getBackupDir(): string {
        // Ensure folder exists in IAppData (creates DB entry)
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
        $files = glob($dir . '/metavox_backup_*.json');
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
}
