<?php
declare(strict_types=1);

namespace OCA\MetaVox\BackgroundJobs;

use OCP\BackgroundJob\QueuedJob;
use OCA\MetaVox\Service\SearchIndexService;

class UpdateSearchIndex extends QueuedJob {
    
    protected function run($argument) {
        $fileId = $argument['file_id'] ?? null;
        
        if (!$fileId) {
            return;
        }

        try {
            $searchIndexService = \OC::$server->get(SearchIndexService::class);
            $searchIndexService->updateFileIndex((int)$fileId);
        } catch (\Exception $e) {
            error_log('MetaVox background job error: ' . $e->getMessage());
        }
    }
}