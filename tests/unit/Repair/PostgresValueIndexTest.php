<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Repair;

use OCA\MetaVox\Repair\PostgresValueIndex;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/** On PostgreSQL the filter index holds the start of a value, so a long value can be saved. */
class PostgresValueIndexTest extends TestCase {

    /** @var list<string> */
    private array $statements = [];

    private function repair(string $provider, string|false $definition): PostgresValueIndex {
        $db = $this->createMock(IDBConnection::class);
        $db->method('getDatabaseProvider')->willReturn($provider);
        $result = $this->createMock(IResult::class);
        $result->method('fetchOne')->willReturn($definition);
        $db->method('executeQuery')->willReturn($result);
        $db->method('executeStatement')->willReturnCallback(function (string $sql): int {
            $this->statements[] = $sql;
            return 0;
        });
        return new PostgresValueIndex($db);
    }

    public function testTheWholeValueIndexIsReplaced(): void {
        $made = $this->repair(IDBConnection::PLATFORM_POSTGRES,
            'CREATE INDEX idx_gf_file_meta_filter ON public.oc_metavox_file_gf_meta USING btree (groupfolder_id, field_name, field_value)')->ensure();

        $this->assertTrue($made);
        $this->assertSame([
            'DROP INDEX IF EXISTS idx_gf_file_meta_filter',
            'CREATE INDEX idx_gf_file_meta_filter ON *PREFIX*metavox_file_gf_meta (groupfolder_id, field_name, LEFT(field_value, 100))',
        ], $this->statements);
    }

    public function testAMissingIndexIsMade(): void {
        $this->assertTrue($this->repair(IDBConnection::PLATFORM_POSTGRES, false)->ensure());
        $this->assertCount(2, $this->statements);
    }

    public function testTheStartIndexStays(): void {
        $made = $this->repair(IDBConnection::PLATFORM_POSTGRES,
            'CREATE INDEX idx_gf_file_meta_filter ON public.oc_metavox_file_gf_meta USING btree (groupfolder_id, field_name, "left"(field_value, 100))')->ensure();

        $this->assertFalse($made);
        $this->assertSame([], $this->statements);
    }

    public function testOtherDatabasesKeepTheirIndex(): void {
        foreach ([IDBConnection::PLATFORM_MYSQL, IDBConnection::PLATFORM_SQLITE] as $provider) {
            $this->assertFalse($this->repair($provider, false)->ensure(), $provider);
        }
        $this->assertSame([], $this->statements);
    }
}
