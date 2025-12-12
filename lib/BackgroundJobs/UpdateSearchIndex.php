<?php

declare(strict_types=1);

namespace OCA\MetaVox\BackgroundJobs;

use OCA\MetaVox\Service\SearchIndexService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Background job to update the search index for a specific file
 */
class UpdateSearchIndex extends QueuedJob {
    public function __construct(
        ITimeFactory $time,
        private readonly SearchIndexService $searchIndexService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($time);
    }

    /**
     * @param array{file_id?: int|null} $argument
     */
    protected function run(mixed $argument): void {
        $fileId = $argument['file_id'] ?? null;

        if ($fileId === null) {
            $this->logger->warning('MetaVox: UpdateSearchIndex called without file_id', [
                'app' => 'metavox'
            ]);
            return;
        }

        try {
            $this->searchIndexService->updateFileIndex((int)$fileId);

            $this->logger->debug('MetaVox: Search index updated', [
                'file_id' => $fileId,
                'app' => 'metavox'
            ]);
        } catch (\Exception $e) {
            $this->logger->error('MetaVox: Background job error', [
                'file_id' => $fileId,
                'exception' => $e,
                'app' => 'metavox'
            ]);
        }
    }
}