<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Controller;

use OCA\MetaVox\Controller\ApiViewController;
use OCA\MetaVox\Controller\ViewController;
use OCA\MetaVox\Exception\RequestException;
use OCA\MetaVox\Service\ApiValidationException;
use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\PresenceService;
use OCA\MetaVox\Service\TeamFolderAccessService;
use OCA\MetaVox\Service\UserFieldService;
use OCA\MetaVox\Service\ViewService;
use OCA\MetaVox\Tests\Pot;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** Saving a view through the web route and through OCS: malformed input is a 400, never a 500. */
class ViewSaveTest extends TestCase {

    /** @var array<string, mixed> */
    private array $params = [];

    private ViewService&MockObject $views;

    private const STORED = [
        'id' => 5,
        'columns' => [['field_id' => 12, 'field_name' => 'status', 'format' => [['op' => 'empty', 'tone' => 'warning']]]],
    ];

    protected function setUp(): void {
        $this->views = $this->createMock(ViewService::class);
        $this->views->method('getView')->willReturn(self::STORED);
        $this->params = ['name' => 'Werk', 'columns' => [['field_id' => 12, 'field_name' => 'status']], 'filters' => []];
    }

    private function request(): IRequest {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => $this->params[$key] ?? $default);
        $request->method('getParams')->willReturnCallback(fn () => $this->params);
        return $request;
    }

    private function userSession(): IUserSession {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);
        return $session;
    }

    private function web(): ViewController {
        return new ViewController(
            'metavox',
            $this->request(),
            $this->createMock(FieldService::class),
            $this->createMock(PresenceService::class),
            $this->createMock(UserFieldService::class),
            $this->views,
            $this->userSession(),
            $this->createMock(TeamFolderAccessService::class),
        );
    }

    private function ocs(): ApiViewController {
        return new ApiViewController(
            'metavox',
            $this->request(),
            $this->createMock(FieldService::class),
            $this->views,
            $this->userSession(),
            $this->createMock(TeamFolderAccessService::class),
        );
    }

    private function save(string $surface, string $action): void {
        $controller = $surface === 'web' ? $this->web() : $this->ocs();
        $action === 'create' ? $controller->createView(2) : $controller->updateView(2, 5);
    }

    /** The message of a RequestException, or the keys of an ApiValidationException; null when saved. */
    private function refusal(callable $save): ?string {
        try {
            $save();
            return null;
        } catch (RequestException $e) {
            $this->assertSame(400, $e->getStatus());
            return $e->getMessage();
        } catch (ApiValidationException $e) {
            return implode(',', array_keys($e->getErrors()));
        }
    }

    public static function refusedInput(): array {
        return [
            'name as a list' => [['name' => ['x']], 'View name is required'],
            'name as a number' => [['name' => 42], 'View name is required'],
            'name missing' => [['name' => null], 'View name is required'],
            'name empty' => [['name' => ''], 'View name is required'],
            'name blank' => [['name' => '   '], 'View name is required'],
            'name of ideographic spaces' => [['name' => "\u{3000}"], 'View name is required'],
            'name too long' => [['name' => str_repeat('é', 101)], 'A view name can have at most 100 characters'],
            'name with NUL' => [['name' => "a\0b"], 'name'],
            'name broken UTF-8' => [['name' => "\xFF"], 'name'],
            'columns as text' => [['columns' => 'x'], 'columns'],
            'columns as a number' => [['columns' => 5], 'columns'],
            'columns null' => [['columns' => null], 'columns'],
            'filters as text' => [['filters' => 'x'], 'filters'],
            'filters null' => [['filters' => null], 'filters'],
            'sort_field as a list' => [['sort_field' => ['x']], 'sort_field'],
            'sort_field as a number' => [['sort_field' => 3], 'sort_field'],
            'sort_field too long' => [['sort_field' => str_repeat('a', 101)], 'sort_field'],
            'sort_field with NUL' => [['sort_field' => "a\0"], 'sort_field'],
            'sort_order as a list' => [['sort_order' => ['asc']], 'sort_order'],
            'sort_order as a number' => [['sort_order' => 1], 'sort_order'],
            'sort_order unknown' => [['sort_order' => 'descending'], 'sort_order'],
        ];
    }

    #[DataProvider('refusedInput')]
    public function testRefusedInput(array $params, string $expected): void {
        foreach (['web', 'ocs'] as $surface) {
            foreach (['create', 'update'] as $action) {
                $this->setUp();
                $this->params = array_merge($this->params, $params);
                $this->views->expects($this->never())->method('createView');
                $this->views->expects($this->never())->method('updateView');
                $this->assertSame($expected, $this->refusal(fn () => $this->save($surface, $action)), "$surface $action");
            }
        }
    }

    public function testRefusedPosition(): void {
        foreach (['abc', 1.5, 2147483648, '2147483648', -1, ['x']] as $position) {
            foreach (['web', 'ocs'] as $surface) {
                $this->setUp();
                $this->params['position'] = $position;
                $this->views->expects($this->never())->method('updateView');
                $this->assertSame('position', $this->refusal(fn () => $this->save($surface, 'update')), "$surface " . json_encode($position));
            }
        }
    }

    public function testAcceptedInput(): void {
        $accepted = [
            [['name' => '0'], fn (array $a) => $a[2] === '0'],
            [['name' => str_repeat('é', 100)], fn (array $a) => $a[2] === str_repeat('é', 100)],
            [['sort_order' => 'ASC', 'sort_field' => 'status'], fn (array $a) => $a[6] === 'status' && $a[7] === 'ASC'],
            [['sort_order' => 'desc'], fn (array $a) => $a[7] === 'desc'],
            [['sort_order' => ''], fn (array $a) => $a[7] === ''],
        ];
        foreach ($accepted as [$params, $check]) {
            foreach (['web', 'ocs'] as $surface) {
                $this->setUp();
                $this->params = array_merge($this->params, $params);
                $this->views->expects($this->once())->method('updateView')
                    ->willReturnCallback(function (...$args) use ($check, $surface, $params) {
                        $this->assertTrue($check($args), $surface . ' ' . json_encode($params));
                        return self::STORED;
                    });
                $this->save($surface, 'update');
            }
        }
    }

    public function testMissingColumnsAndFiltersAreEmptyAsBefore(): void {
        foreach (['web', 'ocs'] as $surface) {
            $this->setUp();
            unset($this->params['columns'], $this->params['filters']);
            $this->views->expects($this->once())->method('createView')
                ->with(2, 'Werk', false, [], [], null, null)
                ->willReturn(self::STORED);
            $this->save($surface, 'create');
        }
    }

    public function testPositionAsDigitsIsANumber(): void {
        foreach (['web', 'ocs'] as $surface) {
            $this->setUp();
            $this->params['position'] = '3';
            $this->views->expects($this->once())->method('updateView')
                ->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), 3)
                ->willReturn(self::STORED);
            $this->save($surface, 'update');
        }
    }

    /** What "Save filters" sends when it updates a view. */
    public function testTheSaveFiltersPayloadIsAccepted(): void {
        foreach (['web', 'ocs'] as $surface) {
            $this->setUp();
            $this->params = [
                'name' => 'Werk',
                'is_default' => true,
                'columns' => [['field_id' => 12, 'field_name' => 'status', 'field_label' => 'Status', 'field_type' => 'select', 'field_options' => ['A', 'B'], 'visible' => true, 'filterable' => true]],
                'filters' => ['12' => ['A']],
                'sort_field' => null,
                'sort_order' => null,
            ];
            $this->views->expects($this->once())->method('updateView')->willReturn(self::STORED);
            $this->save($surface, 'update');
        }
    }

    public function testReorderNeedsIds(): void {
        foreach (['web', 'ocs'] as $surface) {
            foreach ([[], ['abc'], 'x', null, [0, -1]] as $ids) {
                $this->setUp();
                $this->params = ['view_ids' => $ids];
                $this->views->expects($this->never())->method('reorderViews');
                $controller = $surface === 'web' ? $this->web() : $this->ocs();
                $this->assertSame('view_ids', $this->refusal(fn () => $controller->reorderViews(2)), "$surface " . json_encode($ids));
            }
            $this->setUp();
            $this->params = ['view_ids' => [3, '4']];
            $this->views->expects($this->once())->method('reorderViews')->with(2, [3, 4]);
            ($surface === 'web' ? $this->web() : $this->ocs())->reorderViews(2);
        }
    }

    public function testMessagesAreTranslated(): void {
        $msgids = Pot::msgids();
        foreach (['View name is required', 'A view name can have at most 100 characters'] as $message) {
            $this->assertArrayHasKey($message, $msgids, $message);
        }
    }

    /** The stored columns go along, so a column sent without rules keeps them. */
    public function testUpdatePassesTheStoredColumns(): void {
        foreach (['web', 'ocs'] as $surface) {
            $this->setUp();
            $this->views->expects($this->once())->method('updateView')
                ->with(5, 2, 'Werk', false, $this->params['columns'], [], null, null, $this->identicalTo(self::STORED['columns']), null)
                ->willReturn(self::STORED);
            $surface === 'web' ? $this->web()->updateView(2, 5) : $this->ocs()->updateView(2, 5);
        }
    }
}
