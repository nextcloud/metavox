<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Event\FileMetadataChangedEvent;
use OCA\MetaVox\Service\FileMetadataChangeNotifier;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;

class FileMetadataChangeNotifierTest extends TestCase {

    public function testNotifyDispatchesAFileMetadataChangedEvent(): void {
        $eventDispatcher = $this->createMock(IEventDispatcher::class);
        $eventDispatcher->expects($this->once())
            ->method('dispatchTyped')
            ->with($this->callback(function (FileMetadataChangedEvent $event) {
                return $event->getFileId() === 42
                    && $event->getGroupfolderId() === 7
                    && $event->getOperation() === 'updated'
                    && $event->getFieldNames() === ['source'];
            }));

        (new FileMetadataChangeNotifier($eventDispatcher))->notify(42, 7, 'updated', ['source']);
    }

    public function testFieldNamesDefaultToEmptyList(): void {
        $eventDispatcher = $this->createMock(IEventDispatcher::class);
        $eventDispatcher->expects($this->once())
            ->method('dispatchTyped')
            ->with($this->callback(fn (FileMetadataChangedEvent $event) => $event->getFieldNames() === []));

        (new FileMetadataChangeNotifier($eventDispatcher))->notify(42, 7, 'deleted');
    }
}
