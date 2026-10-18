<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Service\ApiValidationException;
use OCA\MetaVox\Service\ViewService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/** A view with a rule the server refuses changes nothing, not even the default flag of other views. */
class ViewServiceTest extends TestCase {

    private const BAD = [['field_id' => 1, 'field_name' => 'f', 'format' => [['op' => 'nope', 'tone' => 'info']]]];

    private function service(): ViewService {
        $db = $this->createMock(IDBConnection::class);
        $db->expects($this->never())->method('getQueryBuilder');
        $factory = $this->createMock(ICacheFactory::class);
        $factory->method('createDistributed')->willReturn($this->createMock(ICache::class));
        return new ViewService($db, $factory);
    }

    public function testRefusedRulesTouchNoRowOnCreate(): void {
        $this->expectException(ApiValidationException::class);
        $this->service()->createView(2, 'Nieuw', true, self::BAD, [], null, null);
    }

    public function testRefusedRulesTouchNoRowOnUpdate(): void {
        $this->expectException(ApiValidationException::class);
        $this->service()->updateView(5, 2, 'Bestaand', true, self::BAD, [], null, null, []);
    }

    /** Values json_encode can't store used to become a view without columns, with a 200. */
    public function testAValueThatCannotBeStoredIsRefused(): void {
        $deep = 'x';
        for ($i = 0; $i < 70; $i++) {
            $deep = [$deep];
        }
        $cases = [
            'columns' => [[['field_id' => 1, 'width' => INF]], []],
            'columns ' => [[['field_id' => 1, 'field_label' => "\xFF"]], []],
            'columns  ' => [[['field_id' => 1, 'field_options' => $deep]], []],
            'filters' => [[], ['1' => [INF]]],
        ];
        foreach ($cases as $key => [$columns, $filters]) {
            foreach (['create', 'update'] as $action) {
                try {
                    $action === 'create'
                        ? $this->service()->createView(2, 'Nieuw', true, $columns, $filters, null, null)
                        : $this->service()->updateView(5, 2, 'Bestaand', true, $columns, $filters, null, null, []);
                    $this->fail("$action $key was stored");
                } catch (ApiValidationException $e) {
                    $this->assertSame([trim($key)], array_keys($e->getErrors()), "$action $key");
                }
            }
        }
    }
}
