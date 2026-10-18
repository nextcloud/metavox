<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Controller;

use OCA\MetaVox\Controller\UserFieldController;
use OCA\MetaVox\Service\ApiValidationException;
use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\FileAccessService;
use OCA\MetaVox\Service\FileReferenceService;
use OCA\MetaVox\Service\FilterService;
use OCA\MetaVox\Service\TeamFolderAccessService;
use OCA\MetaVox\Service\UserFieldService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/** Assigning fields to a team folder: a misspelled field_ids unassigned every field, now it is refused. */
class FieldAssignParamsTest extends TestCase {

    private array $params = [];
    /** @var list<array> the field ids each assignment got */
    private array $assigned = [];

    private function controller(): UserFieldController {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => $this->params[$key] ?? $default);
        $request->method('getParams')->willReturnCallback(fn () => $this->params);
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($this->createMock(IUser::class));
        $fields = $this->createMock(FieldService::class);
        $fields->method('setGroupfolderFields')->willReturnCallback(function (int $gf, array $ids): void {
            $this->assigned[] = $ids;
        });
        return new UserFieldController('metavox', $request, $this->createMock(UserFieldService::class), $fields,
            $this->createMock(FilterService::class), $session, $this->createMock(FileAccessService::class),
            $this->createMock(TeamFolderAccessService::class), $this->createMock(FileReferenceService::class));
    }

    public function testFieldIdsMustBeSent(): void {
        foreach ([['fieldIds' => [1, 2]], ['field_ids' => [1, 'x']]] as $params) {
            $this->params = $params;
            try {
                $this->controller()->setGroupfolderFields(7);
                $this->fail('Assigned with ' . json_encode($params));
            } catch (ApiValidationException $e) {
                $this->assertArrayHasKey('field_ids', $e->getErrors());
            }
        }
        $this->assertSame([], $this->assigned, 'no field was unassigned');
    }

    public function testAnEmptyListStillUnassignsAll(): void {
        $this->params = ['field_ids' => []];
        $this->controller()->setGroupfolderFields(7);
        $this->params = ['field_ids' => '3,4'];
        $this->controller()->setGroupfolderFields(7);

        $this->assertSame([[], [3, 4]], $this->assigned);
    }

    public function testEveryRouteThatAssignsRequiresTheList(): void {
        foreach (['FieldController', 'ApiFieldController', 'UserFieldController'] as $controller) {
            $source = file_get_contents(__DIR__ . "/../../../lib/Controller/$controller.php");
            preg_match('/function setGroupfolderFields\(.*?\n    \}/s', $source, $body);
            $this->assertStringContainsString("requireParam('field_ids')", $body[0] ?? '', $controller);
        }
    }
}
