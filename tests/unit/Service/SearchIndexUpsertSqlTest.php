<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Service\SearchIndexService;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the pure dual-dialect upsert builder for the bulk search-index write.
 * Relies on the UNIQUE(file_id, user_id) index (migration 0020) being present
 * at runtime; here we only assert the generated SQL shape per dialect.
 */
class SearchIndexUpsertSqlTest extends TestCase {

    private SearchIndexService $service;

    protected function setUp(): void {
        $this->service = new SearchIndexService(
            $this->createMock(IDBConnection::class),
            $this->createMock(ICacheFactory::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testMysqlUpsertRefreshesContent(): void {
        $sql = $this->service->buildSearchUpsertSql(2, true);

        $this->assertSame(2, substr_count($sql, '(?, ?, ?, ?, ?, ?)'));
        $this->assertStringContainsString('INTO *PREFIX*metavox_search_index', $sql);
        // Re-indexing should reflect latest metadata: DO UPDATE, not DO NOTHING.
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql);
        $this->assertStringContainsString('search_content = VALUES(search_content)', $sql);
        $this->assertStringContainsString('field_data = VALUES(field_data)', $sql);
    }

    public function testPostgresSqliteUpsertTargetsUniqueKey(): void {
        $sql = $this->service->buildSearchUpsertSql(3, false);

        $this->assertSame(3, substr_count($sql, '(?, ?, ?, ?, ?, ?)'));
        // Conflict target must match the new unique index exactly.
        $this->assertStringContainsString('ON CONFLICT (file_id, user_id) DO UPDATE SET', $sql);
        $this->assertStringContainsString('search_content = EXCLUDED.search_content', $sql);
        $this->assertStringNotContainsString('DO NOTHING', $sql);
    }

    public function testColumnOrderMatchesParamPacking(): void {
        // The INSERT column list must be in the order the params are pushed:
        // fileId, userId, storageId, searchContent, fieldData, updated_at.
        $sql = $this->service->buildSearchUpsertSql(1, false);
        $this->assertStringContainsString(
            '(file_id, user_id, storage_id, search_content, field_data, updated_at)',
            $sql
        );
    }
}
