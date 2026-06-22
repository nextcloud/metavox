<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Service\DefaultsService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the pure, DB-free logic of DefaultsService: the conflict-handling SQL
 * builder (both dialects) and chunk sizing. The query builders and execution
 * are covered by integration tests against a real DB on the NC test VM.
 */
class DefaultsServiceTest extends TestCase {

    private DefaultsService $service;

    protected function setUp(): void {
        $this->service = new DefaultsService(
            $this->createMock(IDBConnection::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testMysqlInsertUsesNoOpUpdateForDoNothing(): void {
        $sql = $this->service->buildBulkInsertSql(2, true);

        // Two row tuples.
        $this->assertSame(2, substr_count($sql, '(?, ?, ?, ?, ?, ?)'));
        // MySQL has no DO NOTHING: a no-op self-assignment preserves the
        // existing value — defaults must never overwrite.
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE file_id = file_id', $sql);
        $this->assertStringNotContainsString('field_value = VALUES', $sql);
    }

    public function testPostgresSqliteInsertUsesDoNothing(): void {
        $sql = $this->service->buildBulkInsertSql(3, false);

        $this->assertSame(3, substr_count($sql, '(?, ?, ?, ?, ?, ?)'));
        $this->assertStringContainsString(
            'ON CONFLICT (file_id, groupfolder_id, field_name) DO NOTHING',
            $sql
        );
        // Must not be a DO UPDATE — that would overwrite user-entered values.
        $this->assertStringNotContainsString('DO UPDATE', $sql);
    }

    public function testInsertTargetsFileMetadataTable(): void {
        $sql = $this->service->buildBulkInsertSql(1, false);
        $this->assertStringContainsString('INTO *PREFIX*metavox_file_gf_meta', $sql);
        $this->assertStringContainsString(
            '(file_id, groupfolder_id, field_name, field_value, created_at, updated_at)',
            $sql
        );
    }

    public function testRowChunkConstantStaysUnderParamLimit(): void {
        // 6 params per row; chunk * 6 must stay well under the conservative
        // ~65k driver parameter ceiling.
        $this->assertLessThan(65535, DefaultsService::INSERT_ROW_CHUNK * 6);
    }
}
