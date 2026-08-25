<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Service\ApiFieldService;
use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\FileMetadataChangeNotifier;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests that ApiFieldService::saveGroupfolderFileMetadata() notifies
 * FileMetadataChangeNotifier (nextcloud/metavox#86) exactly when a known
 * field is actually written, listing every field written in one call.
 */
class ApiFieldServiceMetadataEventTest extends TestCase {

    private const GROUPFOLDER_FIELDS = [
        ['id' => 1, 'field_name' => 'source', 'field_type' => 'text'],
        ['id' => 2, 'field_name' => 'status', 'field_type' => 'text'],
    ];

    private FieldService $fieldService;
    private FileMetadataChangeNotifier $metadataChangeNotifier;
    private ApiFieldService $service;

    protected function setUp(): void {
        $this->fieldService = $this->createMock(FieldService::class);
        $this->fieldService->method('getFieldsByScope')->willReturn(self::GROUPFOLDER_FIELDS);

        $this->metadataChangeNotifier = $this->createMock(FileMetadataChangeNotifier::class);

        $this->service = new ApiFieldService(
            $this->createMock(IDBConnection::class),
            $this->fieldService,
            $this->metadataChangeNotifier,
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testNotifiesOnceListingAllFieldsWritten(): void {
        $this->fieldService->expects($this->exactly(2))->method('saveGroupfolderFileFieldValue');

        $this->metadataChangeNotifier->expects($this->once())
            ->method('notify')
            ->with(42, 7, 'updated', ['source', 'status']);

        $this->service->saveGroupfolderFileMetadata(7, 42, ['source' => 'IFOP', 'status' => 'validated']);
    }

    public function testDoesNotNotifyWhenNoKnownFieldWasWritten(): void {
        $this->fieldService->expects($this->never())->method('saveGroupfolderFileFieldValue');
        $this->metadataChangeNotifier->expects($this->never())->method('notify');

        $this->service->saveGroupfolderFileMetadata(7, 42, ['not_a_real_field' => 'x']);
    }

    public function testUnknownFieldsAreExcludedFromTheNotifiedList(): void {
        $this->metadataChangeNotifier->expects($this->once())
            ->method('notify')
            ->with(42, 7, 'updated', ['source']);

        $this->service->saveGroupfolderFileMetadata(7, 42, ['source' => 'IFOP', 'not_a_real_field' => 'x']);
    }
}
