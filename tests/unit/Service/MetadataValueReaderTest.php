<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Service\MetadataValueReader;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class MetadataValueReaderTest extends TestCase {

    /** @var list<array<string, list<mixed>>> what each query was built with */
    private array $queries = [];

    /** @var list<list<array<string, mixed>>> rows per query, in order */
    private array $rows = [];

    private ?IResult $result = null;

    private ?\Exception $queryError = null;

    private function reader(): MetadataValueReader {
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturnCallback(fn () => $this->queryBuilder());
        return new MetadataValueReader($db);
    }

    private function queryBuilder(): IQueryBuilder {
        $index = count($this->queries);
        $this->queries[] = ['select' => [], 'addSelect' => [], 'selectAlias' => [], 'substring' => [], 'params' => [], 'eq' => [], 'in' => [], 'from' => []];
        $q = &$this->queries[$index];

        $qb = $this->createMock(IQueryBuilder::class);
        $record = function (string $method) use ($qb, &$q) {
            return function (...$args) use ($qb, &$q, $method) {
                $q[$method][] = $args;
                return $qb;
            };
        };
        foreach (['select', 'addSelect', 'selectAlias', 'from'] as $method) {
            $qb->method($method)->willReturnCallback($record($method));
        }
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('createNamedParameter')->willReturnCallback(function ($value, $type = IQueryBuilder::PARAM_STR) use (&$q) {
            $q['params'][] = [$value, $type];
            return ':p' . (count($q['params']) - 1);
        });

        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturnCallback(function (...$args) use (&$q) {
            $q['eq'][] = $args;
            return 'eq';
        });
        $expr->method('in')->willReturnCallback(function (...$args) use (&$q) {
            $q['in'][] = $args;
            return 'in';
        });
        $qb->method('expr')->willReturn($expr);

        $func = $this->createMock(IFunctionBuilder::class);
        $substring = $this->createMock(IQueryFunction::class);
        $func->method('substring')->willReturnCallback(function (...$args) use (&$q, $substring) {
            $q['substring'][] = $args;
            return $substring;
        });
        $qb->method('func')->willReturn($func);

        $qb->method('executeQuery')->willReturnCallback(function () use ($index) {
            if ($this->queryError !== null) {
                throw $this->queryError;
            }
            if ($this->result !== null) {
                return $this->result;
            }
            $result = $this->createMock(IResult::class);
            $result->method('fetch')->willReturnOnConsecutiveCalls(...array_merge($this->rows[$index] ?? [], [false]));
            return $result;
        });

        return $qb;
    }

    /** The value bound to a placeholder such as ':p2'. */
    private function param(array $query, string $placeholder): array {
        return $query['params'][(int)substr($placeholder, 2)];
    }

    public function testFiltersOnTeamFolderAndFileIds(): void {
        $this->reader()->readValues(7, [10, 11]);

        $query = $this->queries[0];
        $this->assertSame('metavox_file_gf_meta', $query['from'][0][0]);
        $this->assertSame('groupfolder_id', $query['eq'][0][0]);
        $this->assertSame([7, IQueryBuilder::PARAM_INT], $this->param($query, $query['eq'][0][1]));
        $this->assertSame('file_id', $query['in'][0][0]);
        $this->assertSame([[10, 11], IQueryBuilder::PARAM_INT_ARRAY], $this->param($query, $query['in'][0][1]));
    }

    public function testChunksUniqueIntIds(): void {
        $ids = array_merge(range(1, 2500), [5, '6', 7]);

        $this->reader()->readValues(7, $ids);

        $this->assertCount(3, $this->queries);
        $chunks = array_map(fn ($q) => $this->param($q, $q['in'][0][1])[0], $this->queries);
        $this->assertSame([1000, 1000, 500], array_map('count', $chunks));
        $this->assertSame(range(1, 2500), array_merge(...$chunks));
    }

    public function testNoIdsNoQuery(): void {
        $this->assertSame([], $this->reader()->readValues(7, []));
        $this->assertSame([], $this->queries);
    }

    public function testReadsFullValuesWithoutCap(): void {
        $this->reader()->readValues(7, [10]);

        $this->assertSame([['field_value']], $this->queries[0]['addSelect']);
        $this->assertSame([], $this->queries[0]['substring']);
    }

    public function testCutsValuesInSql(): void {
        $this->reader()->readValues(7, [10], 513);

        $query = $this->queries[0];
        $this->assertSame([], $query['addSelect']);
        [$column, $start, $length] = $query['substring'][0];
        $this->assertSame('field_value', $column);
        $this->assertSame([1, IQueryBuilder::PARAM_INT], $this->param($query, $start));
        $this->assertSame([513, IQueryBuilder::PARAM_INT], $this->param($query, $length));
        $this->assertSame('field_value', $query['selectAlias'][0][1]);
    }

    public function testKeepsEmptyAndNullValues(): void {
        $this->rows = [[
            ['file_id' => '10', 'field_name' => 'status', 'field_value' => 'Draft'],
            ['file_id' => '10', 'field_name' => 'note', 'field_value' => ''],
            ['file_id' => '11', 'field_name' => 'status', 'field_value' => null],
            ['file_id' => '11', 'field_name' => 'count', 'field_value' => 0],
        ]];

        $this->assertSame([
            10 => ['status' => 'Draft', 'note' => ''],
            11 => ['status' => null, 'count' => '0'],
        ], $this->reader()->readValues(7, [10, 11]));
    }

    public function testClosesCursorWhenFetchFails(): void {
        $this->result = $this->createMock(IResult::class);
        $this->result->method('fetch')->willThrowException(new \RuntimeException('connection lost'));
        $this->result->expects($this->once())->method('closeCursor');

        $this->expectException(\RuntimeException::class);
        $this->reader()->readValues(7, [10]);
    }

    public function testQueryErrorsPropagate(): void {
        $this->queryError = new \RuntimeException('table missing');

        $this->expectExceptionMessage('table missing');
        $this->reader()->readValues(7, [10]);
    }
}
