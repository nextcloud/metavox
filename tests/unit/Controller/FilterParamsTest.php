<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Controller;

use OCA\MetaVox\Access\ReadableScope;
use OCA\MetaVox\Controller\ApiFilterController;
use OCA\MetaVox\Exception\RequestException;
use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\FilterService;
use OCA\MetaVox\Service\FileAccessService;
use OCA\MetaVox\Service\ApiValidationException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** Malformed input to the filter endpoints is a 400 or ignored, never a 500. */
class FilterParamsTest extends TestCase {

    /** @var array<string, mixed> */
    private array $params = [];

    private FilterService&MockObject $filterService;
    private FieldService&MockObject $fieldService;

    protected function setUp(): void {
        $this->filterService = $this->createMock(FilterService::class);
        $this->fieldService = $this->createMock(FieldService::class);
        $this->fieldService->method('hasAccessToGroupfolder')->willReturn(true);
        $this->fieldService->method('getAssignedFieldsWithDataForGroupfolder')->willReturn([
            ['field_name' => 'status', 'field_type' => 'text'],
            ['field_name' => 'owner', 'field_type' => 'text'],
        ]);
    }

    private function request(): IRequest {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => $this->params[$key] ?? $default);
        return $request;
    }

    private function userSession(): IUserSession {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);
        return $session;
    }

    private function ocsController(): ApiFilterController {
        return new ApiFilterController(
            'metavox',
            $this->request(),
            $this->fieldService,
            $this->filterService,
            $this->userSession(),
            $this->access(),
        );
    }

    private function access(): FileAccessService {
        $access = $this->createMock(FileAccessService::class);
        $access->method('readableScope')->willReturn(ReadableScope::everything());
        return $access;
    }

    public function testParseFilters(): void {
        $this->assertSame([], ApiFilterController::parseFilters(null));
        $this->assertSame([], ApiFilterController::parseFilters(''));
        $this->assertSame(['status' => ['Draft', 0]], ApiFilterController::parseFilters('{"status":["Draft",0]}'));
        $this->assertSame(['123' => ['x']], ApiFilterController::parseFilters('{"123":["x"]}'));
    }

    public function testParseFiltersRejectsMalformedInput(): void {
        foreach (['{"status":"x"}', '{"status":[["x"]]}', '{"status":{"a":"x"}}', '"text"', '{broken', ['status' => ['x']]] as $input) {
            $this->assertNull(ApiFilterController::parseFilters($input), json_encode($input));
        }
    }

    public function testSortedFileIdsRejectsAFilterValueThatIsNoList(): void {
        $this->params = ['filters' => '{"status":"x"}'];
        $this->filterService->expects($this->never())->method('getSortedFilteredFileIds');

        $this->assertRefusedAsInvalid(fn () => $this->ocsController()->getSortedFileIds(2), 'filters');
    }

    public function testSortedFileIdsRejectsArraySortParams(): void {
        foreach (['sort_field', 'sort_order', 'sort_field_type'] as $param) {
            $this->params = ['sort_field' => 'status', $param => ['x']];

            $this->assertRefusedAsInvalid(fn () => $this->ocsController()->getSortedFileIds(2), $param);
        }
    }

    /** A 400 that names the parameter (ExceptionMiddleware answers it with reason invalid_input). */
    private function assertRefusedAsInvalid(callable $call, string $param): void {
        try {
            $call();
            $this->fail('Expected a refusal of ' . $param);
        } catch (ApiValidationException $e) {
            $this->assertArrayHasKey($param, $e->getErrors());
        }
    }

    public function testSortedFileIdsPassesValidFilters(): void {
        $this->params = ['filters' => '{"status":["Draft"]}'];
        $this->filterService->expects($this->once())->method('getSortedFilteredFileIds')
            ->with(2, $this->isInstanceOf(ReadableScope::class), null, 'asc', ['status' => ['Draft']], 'text', [])
            ->willReturn(['file_ids' => [10], 'total' => 1]);

        $this->assertSame(Http::STATUS_OK, $this->ocsController()->getSortedFileIds(2)->getStatus());
    }

    public function testFilterValuesRefuseAFieldNameThatIsNoName(): void {
        $this->params = ['field_names' => ['status', ['nested']]];
        $this->filterService->expects($this->never())->method('distinctValues');

        $this->assertRefusedAsInvalid(fn () => $this->ocsController()->getAllFilterValues(2), 'field_names');
    }

    public function testFilterValuesAcceptFieldNamesAsArray(): void {
        $this->params = ['field_names' => ['status']];
        $this->filterService->expects($this->once())->method('distinctValues')
            ->with(2, $this->isInstanceOf(ReadableScope::class), ['status'])
            ->willReturn(['status' => ['Draft']]);

        $response = $this->ocsController()->getAllFilterValues(2);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['status' => ['Draft']], $response->getData());
    }
}
