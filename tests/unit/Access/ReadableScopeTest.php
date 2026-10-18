<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Access;

use OCA\MetaVox\Access\ReadableScope;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

class ReadableScopeTest extends TestCase {

    private function sql(ReadableScope $scope, ?array &$params = null): ?string {
        $params = [];
        $types = [];
        return $scope->sql('m.file_id', $params, $types);
    }

    public function testEverythingAddsNoCondition(): void {
        $this->assertTrue(ReadableScope::everything()->isEverything());
        $this->assertNull($this->sql(ReadableScope::everything(), $params));
        $this->assertSame([], $params);
    }

    public function testAForbiddenPathHidesItselfAndEverythingBelow(): void {
        $sql = $this->sql(ReadableScope::exceptPaths([['storage' => 8, 'path' => 'files/HR']]), $params);

        $this->assertSame("(m.file_id NOT IN (SELECT fileid FROM *PREFIX*filecache WHERE (storage = ? AND (path = ? OR path LIKE ? ESCAPE '!'))))", $sql);
        $this->assertSame([8, 'files/HR', 'files/HR/%'], $params);
    }

    public function testWildcardsInAPathAreTakenLiterally(): void {
        $this->sql(ReadableScope::exceptPaths([['storage' => 8, 'path' => 'files/50%_off!']]), $params);

        $this->assertSame('files/50!%!_off!!/%', $params[2]);
    }

    public function testHiddenFilesAreLeftOutById(): void {
        $sql = $this->sql(ReadableScope::exceptFiles([7, '9', 7]), $params);

        $this->assertSame('(m.file_id NOT IN (?, ?))', $sql);
        $this->assertSame([7, 9], $params);
    }

    public function testAQueryBuilderGetsTheSameCondition(): void {
        $n = 0;
        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('getTableName')->with('filecache')->willReturn('`*PREFIX*filecache`');
        $qb->method('createNamedParameter')->willReturnCallback(function () use (&$n) {
            return ':p' . ++$n;
        });
        $qb->expects($this->once())->method('andWhere')
            ->with("(m.file_id NOT IN (SELECT fileid FROM `*PREFIX*filecache` WHERE (storage = :p1 AND (path = :p2 OR path LIKE :p3 ESCAPE '!'))))");

        ReadableScope::exceptPaths([['storage' => 8, 'path' => 'files/HR']])->restrict($qb, 'm.file_id');
    }

    /** For a query that joins the file's row anyway: no subquery. */
    public function testOnTheJoinedRowAForbiddenPathIsLeftOutDirectly(): void {
        $n = 0;
        $params = [];
        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('createNamedParameter')->willReturnCallback(function ($value) use (&$n, &$params) {
            $params[] = $value;
            return ':p' . ++$n;
        });

        $sql = ReadableScope::exceptPaths([['storage' => 8, 'path' => 'files/HR'], ['storage' => 9, 'path' => 'files/IT']])->onRow($qb, 'f');

        $this->assertSame("(NOT ((f.storage = :p1 AND (f.path = :p2 OR f.path LIKE :p3 ESCAPE '!')) OR (f.storage = :p4 AND (f.path = :p5 OR f.path LIKE :p6 ESCAPE '!'))))", $sql);
        $this->assertSame([8, 'files/HR', 'files/HR/%', 9, 'files/IT', 'files/IT/%'], $params);
    }

    public function testOnTheJoinedRowHiddenFilesGoById(): void {
        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('createNamedParameter')->willReturn(':p');

        $this->assertSame('(f.fileid NOT IN (:p))', ReadableScope::exceptFiles([7])->onRow($qb, 'f'));
        $this->assertNull(ReadableScope::everything()->onRow($qb, 'f'));
    }

    public function testEverythingLeavesAQueryBuilderAlone(): void {
        $qb = $this->createMock(IQueryBuilder::class);
        $qb->expects($this->never())->method('andWhere');

        ReadableScope::everything()->restrict($qb, 'm.file_id');
    }

    public function testTheCacheKeyTellsScopesApart(): void {
        $hr = ReadableScope::exceptPaths([['storage' => 8, 'path' => 'files/HR']]);

        $this->assertSame('all', ReadableScope::everything()->key());
        $this->assertSame($hr->key(), ReadableScope::exceptPaths([['storage' => 8, 'path' => 'files/HR']])->key());
        $this->assertNotSame($hr->key(), ReadableScope::exceptPaths([['storage' => 8, 'path' => 'files/IT']])->key());
        $this->assertNotSame($hr->key(), ReadableScope::exceptFiles([7])->key());
    }
}
