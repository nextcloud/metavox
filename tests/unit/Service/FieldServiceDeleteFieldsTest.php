<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Service\ApiValidationException;
use OCA\MetaVox\Service\FieldService;
use PHPUnit\Framework\TestCase;

/** Deleting several fields goes field by field through deleteField, with its in-use check. */
class FieldServiceDeleteFieldsTest extends TestCase {

    public function testDeletesTheFreeOnesAndReportsTheRest(): void {
        $service = $this->getMockBuilder(FieldService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['deleteField'])
            ->getMock();
        $asked = [];
        $service->method('deleteField')->willReturnCallback(function (int $id) use (&$asked): bool {
            $asked[] = $id;
            if ($id === 4) {
                throw new ApiValidationException(['field' => 'Cannot delete (in use)']);
            }
            return $id === 3;
        });

        $result = $service->deleteFields(['3', 3, 4, 5]);

        $this->assertSame([3, 4, 5], $asked, 'each field once');
        $this->assertSame([3], $result['deleted']);
        $this->assertSame([['id' => 4, 'reason' => 'in_use'], ['id' => 5, 'reason' => 'not_deleted']], $result['skipped']);
    }
}
