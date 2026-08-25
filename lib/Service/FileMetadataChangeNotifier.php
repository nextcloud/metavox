<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCA\MetaVox\Event\FileMetadataChangedEvent;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Single place that builds and dispatches FileMetadataChangedEvent
 * (nextcloud/metavox#86), shared by FieldController (web UI) and
 * ApiFieldService (OCS API) so neither has to know the event's constructor
 * shape directly.
 */
class FileMetadataChangeNotifier {
    public function __construct(
        private IEventDispatcher $eventDispatcher,
    ) {
    }

    /**
     * @param list<string> $fieldNames
     */
    public function notify(int $fileId, int $groupfolderId, string $operation, array $fieldNames = []): void {
        $this->eventDispatcher->dispatchTyped(
            new FileMetadataChangedEvent($fileId, $groupfolderId, $operation, $fieldNames)
        );
    }
}
