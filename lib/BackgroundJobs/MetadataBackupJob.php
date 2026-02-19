<?php

declare(strict_types=1);

namespace OCA\MetaVox\BackgroundJobs;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Daily automatic backup of all metadata tables to JSON.
 *
 * Streams rows directly to disk to handle millions of entries.
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
            $backupDir = $this->ensureBackupDir();

            $filename = 'metavox_backup_' . date('Y-m-d') . '.json';
            $path = $backupDir . '/' . $filename;

            $counts = $this->streamBackupToFile($path);

            $this->cleanupOldBackups($backupDir);

            $this->logger->info('MetaVox backup created: {filename} ({fields} fields, {gf_meta} groupfolder metadata, {file_meta} file metadata)', [
                'filename' => $filename,
                'fields' => $counts['metavox_gf_fields'],
                'gf_meta' => $counts['metavox_gf_metadata'],
                'file_meta' => $counts['metavox_file_gf_meta'],
            ]);
        } catch (\Exception $e) {
            $this->logger->error('MetaVox backup error', ['exception' => $e]);
        }
    }

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
     * Get the filesystem path to the backup directory.
     * Uses IAppData to resolve the path, then writes directly to disk.
     */
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
