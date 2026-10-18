<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Service\MetadataVersions;
use OCA\MetaVox\Service\ApiFieldService;
use OCA\MetaVox\Service\FieldService;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** What an API caller gets back per file when a batch does not fully work. */
class ApiFieldServiceBatchTest extends TestCase {

    private FieldService $fields;
    /** @var string[] transaction calls */
    private array $tx = [];

    protected function setUp(): void {
        $this->fields = $this->createMock(FieldService::class);
    }

    public function testASavedFileLooksAsItAlwaysDid(): void {
        $this->fields->expects($this->once())->method('saveValuesPerFile')->with(5, [10 => ['klant' => 'A', 'status' => 'B']])->willReturn([]);

        $results = $this->service()->batchUpdateFileMetadata([['file_id' => 10, 'groupfolder_id' => 5, 'metadata' => ['klant' => 'A', 'Status' => 'B']]]);

        $this->assertSame([['file_id' => 10, 'success' => true, 'fields_updated' => 2]], $results);
    }

    public function testEachTeamFolderIsSavedInOneGo(): void {
        $saved = [];
        $this->fields->method('saveValuesPerFile')->willReturnCallback(function (int $gf, array $values) use (&$saved): array {
            $saved[$gf] = $values;
            return [];
        });

        $results = $this->service()->batchUpdateFileMetadata([
            ['file_id' => 10, 'groupfolder_id' => 5, 'metadata' => ['klant' => 'A']],
            ['file_id' => 20, 'groupfolder_id' => 6, 'metadata' => ['klant' => 'B']],
            ['file_id' => 11, 'groupfolder_id' => 5, 'metadata' => ['status' => 'C']],
            ['file_id' => 10, 'groupfolder_id' => 5, 'metadata' => ['klant' => 'D']],
        ]);

        // The later item for the same file wins.
        $this->assertSame([5 => [10 => ['klant' => 'D'], 11 => ['status' => 'C']], 6 => [20 => ['klant' => 'B']]], $saved);
        $this->assertSame([10, 20, 11, 10], array_column($results, 'file_id'));
    }

    public function testAFailedSaveNamesTheFieldsOfThatFile(): void {
        $this->fields->method('saveValuesPerFile')->willReturnCallback(function (int $gf): array {
            if ($gf === 6) {
                throw new \RuntimeException('SQLSTATE[08006]: connection lost');
            }
            return [];
        });

        $results = $this->service()->batchUpdateFileMetadata([
            ['file_id' => 10, 'groupfolder_id' => 5, 'metadata' => ['klant' => 'A']],
            ['file_id' => 20, 'groupfolder_id' => 6, 'metadata' => ['klant' => 'A', 'Status' => 'B', 'onbekend' => 'x']],
            ['groupfolder_id' => 6, 'metadata' => ['klant' => 'A']],
        ]);

        // The failed team folder is rolled back alone, inside saveValuesPerFile.
        $this->assertSame(['begin', 'commit'], $this->tx);
        $this->assertSame([
            ['file_id' => 10, 'success' => true, 'fields_updated' => 1],
            [
                'file_id' => 20,
                'success' => false,
                'fields_updated' => 0,
                'error' => 'Could not save the metadata of this file',
                'reason' => 'save_failed',
                'requestId' => 'req-1',
                'failed_fields' => ['klant', 'status'],
                'unknown_fields' => ['onbekend'],
            ],
            ['file_id' => null, 'success' => false, 'error' => 'Missing file_id', 'reason' => 'invalid_id'],
        ], $results);
    }

    public function testAFileTheDatabaseRefusesLeavesTheOtherFilesSaved(): void {
        $this->fields->method('saveValuesPerFile')->willReturn([11]);

        $results = $this->service()->batchUpdateFileMetadata([
            ['file_id' => 10, 'groupfolder_id' => 5, 'metadata' => ['klant' => 'A']],
            ['file_id' => 11, 'groupfolder_id' => 5, 'metadata' => ['klant' => 'B']],
        ]);

        $this->assertSame(['file_id' => 10, 'success' => true, 'fields_updated' => 1], $results[0]);
        $this->assertSame(['file_id' => 11, 'success' => false, 'fields_updated' => 0, 'error' => 'Could not save the metadata of this file',
            'reason' => 'save_failed', 'requestId' => 'req-1', 'failed_fields' => ['klant']], $results[1]);
    }

    public function testUnknownFieldNamesAreNamed(): void {
        $this->fields->method('saveValuesPerFile')->willReturn([]);

        $results = $this->service()->batchUpdateFileMetadata([['file_id' => 10, 'groupfolder_id' => 5, 'metadata' => ['klant' => 'A', 'klnat' => 'B']]]);

        $this->assertSame([['file_id' => 10, 'success' => true, 'fields_updated' => 1, 'unknown_fields' => ['klnat']]], $results);
    }

    public function testAnErrorShowsNoExceptionText(): void {
        $this->fields->method('saveValuesPerFile')->willThrowException(new \RuntimeException('SQLSTATE[54000]: index row size exceeds maximum for "oc_metavox_file_gf_meta"'));
        $this->fields->method('clearGroupfolderFileFields')->willThrowException(new \RuntimeException('SQLSTATE[40001]: deadlock in oc_metavox_search_words'));
        $service = $this->service();

        $update = $service->batchUpdateFileMetadata([['file_id' => 10, 'groupfolder_id' => 5, 'metadata' => ['klant' => str_repeat('x', 3000)]]]);
        $delete = $service->batchDeleteFileMetadata([['file_id' => 10, 'groupfolder_id' => 5, 'field_names' => ['klant']]]);

        $this->assertSame(['file_id' => 10, 'success' => false, 'fields_updated' => 0, 'error' => 'Could not save the metadata of this file', 'reason' => 'save_failed', 'requestId' => 'req-1', 'failed_fields' => ['klant']], $update[0]);
        $this->assertSame(['file_id' => 10, 'groupfolder_id' => 5, 'success' => false, 'error' => 'Could not delete the metadata of this file', 'reason' => 'delete_failed', 'requestId' => 'req-1'], $delete[0]);
        $this->assertStringNotContainsString('SQLSTATE', json_encode([$update, $delete]));
    }

    public function testDeletingAllWhenThatFailsSaysSo(): void {
        $this->fields->method('clearGroupfolderFileMetadata')->willReturn(false);

        $results = $this->service()->batchDeleteFileMetadata([['file_id' => 10, 'groupfolder_id' => 5, 'field_names' => null]]);

        $this->assertSame('delete_failed', $results[0]['reason']);
        $this->assertFalse($results[0]['success']);
    }

    public function testAnItemWithAValueItsFieldDoesNotTakeIsRefusedAndTheOthersSaved(): void {
        $this->fields->method('refusedValues')->willReturnCallback(fn (int $gf, array $values): array => [
            'refused' => isset($values[11]) ? [11 => ['status' => 'Choose one of the options of this field']] : [],
            'unused' => [],
        ]);
        $this->fields->expects($this->once())->method('saveValuesPerFile')->with(5, [10 => ['klant' => 'A']])->willReturn([]);

        $results = $this->service()->batchUpdateFileMetadata([
            ['file_id' => 10, 'groupfolder_id' => 5, 'metadata' => ['klant' => 'A']],
            ['file_id' => 11, 'groupfolder_id' => 5, 'metadata' => ['status' => 'Archived']],
        ]);

        $this->assertSame(['file_id' => 10, 'success' => true, 'fields_updated' => 1], $results[0]);
        $this->assertSame(['file_id' => 11, 'success' => false, 'fields_updated' => 0, 'error' => 'Validation failed',
            'reason' => 'invalid_input', 'fields' => ['status' => 'Choose one of the options of this field']], $results[1]);
    }

    public function testAFieldTheTeamFolderDoesNotUseIsNamedAndLeftOut(): void {
        $this->fields->method('refusedValues')->willReturn(['refused' => [], 'unused' => [10 => ['status']]]);
        $this->fields->expects($this->once())->method('saveValuesPerFile')->with(5, [10 => ['klant' => 'A']])->willReturn([]);

        $results = $this->service()->batchUpdateFileMetadata([['file_id' => 10, 'groupfolder_id' => 5, 'metadata' => ['klant' => 'A', 'status' => 'Open']]]);

        $this->assertSame([['file_id' => 10, 'success' => true, 'fields_updated' => 1, 'unused_fields' => ['status']]], $results);
    }

    public function testARequiredFieldIsNotDeleted(): void {
        $this->fields->method('requiredFieldNames')->willReturn(['code']);
        $this->fields->expects($this->never())->method('clearGroupfolderFileFields');

        $results = $this->service()->batchDeleteFileMetadata([['file_id' => 10, 'groupfolder_id' => 5, 'field_names' => ['klant', 'CODE']]]);

        $this->assertSame([['file_id' => 10, 'groupfolder_id' => 5, 'success' => false, 'error' => 'Validation failed',
            'reason' => 'invalid_input', 'fields' => ['code' => 'This field is required']]], $results);
    }

    public function testDeletingAllKeepsTheRequiredFields(): void {
        $this->fields->method('requiredFieldNames')->willReturn(['code']);
        $this->fields->expects($this->once())->method('clearGroupfolderFileMetadata')->with(5, 10, true)->willReturn(true);

        $results = $this->service()->batchDeleteFileMetadata([['file_id' => 10, 'groupfolder_id' => 5, 'field_names' => null]]);

        $this->assertSame([['file_id' => 10, 'groupfolder_id' => 5, 'success' => true, 'message' => 'All metadata deleted', 'kept_required_fields' => ['code']]], $results);
    }

    private function service(): ApiFieldService {
        $this->fields->method('getAllFields')->willReturn([['id' => 1, 'field_name' => 'klant'], ['id' => 2, 'field_name' => 'status']]);
        $request = $this->createMock(IRequest::class);
        $request->method('getId')->willReturn('req-1');
        $db = $this->createMock(IDBConnection::class);
        foreach (['beginTransaction' => 'begin', 'commit' => 'commit', 'rollBack' => 'rollBack'] as $method => $name) {
            $db->method($method)->willReturnCallback(function () use ($name): void {
                $this->tx[] = $name;
            });
        }
        return new ApiFieldService($db, $this->fields, $this->createMock(LoggerInterface::class), $request, $this->createMock(MetadataVersions::class));
    }
}
