<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Controller;

use OCA\MetaVox\Controller\ReadsParams;
use OCA\MetaVox\Service\ApiValidationException;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/** Parameters are read strictly: what is not what it should be is a 400 that names it. */
class ReadsParamsTest extends TestCase {

    private function reader(array $params): object {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(fn (string $name, $default = null) => $params[$name] ?? $default);
        $request->method('getParams')->willReturn($params);
        return new class($request) {
            use ReadsParams {
                textParam as public;
                boolParam as public;
                intParam as public;
                getIdListParam as public;
                getStringListParam as public;
                mapParam as public;
                listParam as public;
                choiceParam as public;
                requireParam as public;
            }

            public function __construct(private IRequest $request) {
            }
        };
    }

    private function refusal(callable $read): array {
        try {
            $read();
        } catch (ApiValidationException $e) {
            return $e->getErrors();
        }
        $this->fail('Nothing was refused');
    }

    public function testIdsFromCommaStringOrList(): void {
        $this->assertSame([10, 11], $this->reader(['ids' => '10, 11,10,'])->getIdListParam('ids'));
        $this->assertSame([10, 11, 12], $this->reader(['ids' => [10, '11', ' 12 ']])->getIdListParam('ids'));
        $this->assertSame([5], $this->reader(['ids' => 5])->getIdListParam('ids'));
        $this->assertSame([], $this->reader([])->getIdListParam('ids'));
    }

    public function testAnIdThatIsNoneIsRefused(): void {
        foreach (['abc', -3, 0, '1.5', '1e3', [7], true, '9999999999'] as $junk) {
            $this->assertSame(['ids' => 'Enter a list of IDs'], $this->refusal(fn () => $this->reader(['ids' => [5, $junk]])->getIdListParam('ids')), json_encode($junk));
        }
        $this->assertSame(['ids' => 'This value is not valid'], $this->refusal(fn () => $this->reader(['ids' => ['a' => 1]])->getIdListParam('ids')));
    }

    public function testIdsThatMustBeThereAndNotTooMany(): void {
        $this->assertSame(['ids' => 'This field is required'], $this->refusal(fn () => $this->reader(['ids' => ' , '])->getIdListParam('ids', required: true)));
        $this->assertSame(['ids' => 'Too many IDs in one request'], $this->refusal(fn () => $this->reader(['ids' => '1,2,3'])->getIdListParam('ids', max: 2)));
        // Doubles count once.
        $this->assertSame([1, 2], $this->reader(['ids' => '1,2,1'])->getIdListParam('ids', required: true, max: 2));
    }

    public function testNames(): void {
        $this->assertSame(['status', 'owner'], $this->reader(['names' => ' status,owner,,status '])->getStringListParam('names'));
        $this->assertSame(['names' => 'Enter a list of names'], $this->refusal(fn () => $this->reader(['names' => ['status', 3]])->getStringListParam('names')));
    }

    public function testText(): void {
        $this->assertSame('Werk', $this->reader(['name' => ' Werk '])->textParam('name'));
        $this->assertNull($this->reader([])->textParam('name'));
        $this->assertSame(['name' => 'This field is required'], $this->refusal(fn () => $this->reader(['name' => '  '])->textParam('name', required: true)));
        $this->assertSame(['name' => 'This field is required'], $this->refusal(fn () => $this->reader([])->textParam('name', required: true)));
        $this->assertSame(['name' => 'Enter text'], $this->refusal(fn () => $this->reader(['name' => ['x']])->textParam('name')));
        $this->assertSame(['name' => 'This text is too long'], $this->refusal(fn () => $this->reader(['name' => str_repeat('x', 256)])->textParam('name')));
    }

    public function testYesOrNo(): void {
        foreach ([true, 1, '1', 'true', 'TRUE'] as $yes) {
            $this->assertTrue($this->reader(['on' => $yes])->boolParam('on', false), json_encode($yes));
        }
        foreach ([false, 0, '0', 'false', ''] as $no) {
            $this->assertFalse($this->reader(['on' => $no])->boolParam('on', true), json_encode($no));
        }
        $this->assertTrue($this->reader([])->boolParam('on', true));
        foreach (['on', 'yes', 2, ['x']] as $junk) {
            $this->assertSame(['on' => 'Use true or false'], $this->refusal(fn () => $this->reader(['on' => $junk])->boolParam('on', false)), json_encode($junk));
        }
    }

    public function testWholeNumbers(): void {
        $this->assertSame(12, $this->reader(['n' => '12'])->intParam('n'));
        $this->assertSame(12, $this->reader(['n' => 12])->intParam('n'));
        $this->assertNull($this->reader([])->intParam('n'));
        foreach (['12abc', 'abc', '', 0, 1.5, '-1'] as $junk) {
            $this->assertSame(['n' => 'Enter a whole number'], $this->refusal(fn () => $this->reader(['n' => $junk])->intParam('n', min: 1)), json_encode($junk));
        }
        $this->assertSame(['n' => 'This field is required'], $this->refusal(fn () => $this->reader([])->intParam('n', required: true)));
    }

    public function testFieldNamesWithValues(): void {
        $this->assertSame(['status' => 'Open'], $this->reader(['m' => ['status' => 'Open']])->mapParam('m'));
        $this->assertSame([], $this->reader([])->mapParam('m'));
        foreach (['x', ['Open'], 3] as $junk) {
            $this->assertSame(['m' => 'Send each field name with its value'], $this->refusal(fn () => $this->reader(['m' => $junk])->mapParam('m')), json_encode($junk));
        }
    }

    public function testListsAndChoices(): void {
        $this->assertSame([['a' => 1]], $this->reader(['l' => [['a' => 1]]])->listParam('l'));
        $this->assertSame(['l' => 'This value is not valid'], $this->refusal(fn () => $this->reader(['l' => 'x'])->listParam('l')));
        $this->assertSame(['l' => 'This field is required'], $this->refusal(fn () => $this->reader(['l' => []])->listParam('l', required: true)));
        $this->assertSame([], $this->reader([])->listParam('l'));
        $this->assertSame('desc', $this->reader(['o' => 'DESC'])->choiceParam('o', ['asc', 'desc'], 'asc'));
        $this->assertSame('asc', $this->reader([])->choiceParam('o', ['asc', 'desc'], 'asc'));
        $this->assertSame(['o' => 'This value is not valid'], $this->refusal(fn () => $this->reader(['o' => 'foo'])->choiceParam('o', ['asc', 'desc'])));
    }

    public function testALeftOutParameterThatMustBeThere(): void {
        $this->reader(['field_ids' => []])->requireParam('field_ids');
        $this->assertSame(['field_ids' => 'This field is required'], $this->refusal(fn () => $this->reader(['fieldIds' => [1]])->requireParam('field_ids')));
    }
}
