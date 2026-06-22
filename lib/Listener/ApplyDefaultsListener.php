<?php

declare(strict_types=1);

namespace OCA\MetaVox\Listener;

use OCA\MetaVox\Service\DefaultsRequestCounter;
use OCA\MetaVox\Service\DefaultsService;
use OCA\MetaVox\Service\GroupfolderResolver;
use OCA\MetaVox\Service\SearchIndexService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\FileInfo;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * Fast-path tier of the default-value system: applies defaults to a newly
 * created file synchronously, so for normal uploads search is up to date
 * immediately rather than waiting up to one discovery cycle.
 *
 * This is purely an optimisation over DiscoverMissingDefaultsJob, which remains
 * the source of truth. Three safeguards keep it from ever harming the request:
 *
 *  - Threshold: a shared per-request counter (DefaultsRequestCounter) caps how
 *    many files this path handles per request. A bulk upload exceeds it quickly
 *    and the remaining files fall through to discovery — no synchronous storm.
 *  - Try/catch: any failure is swallowed and logged; discovery will reconcile.
 *  - Idempotent writes: bulkInsertDefaults uses DO NOTHING, so overlapping with
 *    discovery never duplicates or overwrites values.
 *
 * Like the jobs, it never emits push events. The search index is updated via
 * the bulk path (single file here) to share one code path with the backfill.
 *
 * @template-implements IEventListener<NodeCreatedEvent>
 */
class ApplyDefaultsListener implements IEventListener {

    public function __construct(
        private readonly DefaultsService $defaultsService,
        private readonly SearchIndexService $searchIndexService,
        private readonly GroupfolderResolver $groupfolderResolver,
        private readonly DefaultsRequestCounter $counter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if (!($event instanceof NodeCreatedEvent)) {
            return;
        }

        try {
            $node = $event->getNode();

            // Only files get per-file defaults, never folders.
            if ($node->getType() !== FileInfo::TYPE_FILE) {
                return;
            }

            // Defer to discovery once this request has handled enough files.
            if (!$this->counter->tryConsume()) {
                return;
            }

            $this->applyDefaults($node);
        } catch (\Exception $e) {
            // Best-effort: never let a default break an upload. Discovery is the
            // safety net and will fill anything this path missed.
            $this->logger->warning('MetaVox: ApplyDefaultsListener error', ['exception' => $e]);
        }
    }

    private function applyDefaults(Node $node): void {
        $groupfolderId = $this->groupfolderResolver->getGroupfolderId($node);
        if ($groupfolderId === null) {
            return;
        }

        $defaults = $this->defaultsService->getFolderDefaults($groupfolderId);
        if (empty($defaults)) {
            return;
        }

        $fileId = $node->getId();
        $written = $this->defaultsService->bulkInsertDefaults($groupfolderId, [$fileId], $defaults);

        if ($written > 0) {
            // Share the bulk index path with the backfill so content is built
            // identically. No push: a freshly uploaded file has no live viewers
            // waiting on its defaults.
            $this->searchIndexService->bulkUpdateFileIndex([$fileId]);
        }
    }
}
