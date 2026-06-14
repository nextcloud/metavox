<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Service\SearchIndexService;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests buildIndexPayload — the single source of truth for search-index
 * content shared by the per-file (updateFileIndex) and bulk
 * (bulkUpdateFileIndex) paths. If these assertions hold, the two paths can
 * never index a file differently.
 */
class SearchIndexPayloadTest extends TestCase {

    private SearchIndexService $service;

    protected function setUp(): void {
        $this->service = new SearchIndexService(
            $this->createMock(IDBConnection::class),
            $this->createMock(ICacheFactory::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testBuildsSearchContentAndFieldData(): void {
        $payload = $this->service->buildIndexPayload([
            ['field_name' => 'status', 'value' => 'Concept'],
            ['field_name' => 'author', 'value' => 'Sam'],
        ]);

        $this->assertSame('Concept Sam', $payload['search_content']);
        $this->assertSame(
            ['status' => 'Concept', 'author' => 'Sam'],
            json_decode($payload['field_data'], true)
        );
    }

    public function testSkipsOnlyNullAndEmptyNotZero(): void {
        $payload = $this->service->buildIndexPayload([
            ['field_name' => 'status', 'value' => 'Concept'],
            ['field_name' => 'note', 'value' => ''],
            ['field_name' => 'tag', 'value' => '0'],
        ]);

        // Empty string is dropped, but '0' (unchecked checkbox / numeric zero)
        // MUST be kept — regression guard for the empty()-based data-loss bug.
        $this->assertSame('Concept 0', $payload['search_content']);
        $this->assertSame(
            ['status' => 'Concept', 'tag' => '0'],
            json_decode($payload['field_data'], true)
        );
    }

    public function testEmptyMetadataYieldsEmptyPayload(): void {
        $payload = $this->service->buildIndexPayload([]);
        $this->assertSame('', $payload['search_content']);
        $this->assertSame([], json_decode($payload['field_data'], true));
    }
}
