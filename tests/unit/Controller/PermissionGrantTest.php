<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Controller;

use OCA\MetaVox\Controller\PermissionController;
use OCA\MetaVox\Service\ApiValidationException;
use OCA\MetaVox\Service\PermissionService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** A grant names someone and, or not, a team folder that exist; "0" or "abc" is no "all team folders". */
class PermissionGrantTest extends TestCase {

    private array $params = [];
    /** @var list<array{string, string, ?int}> */
    private array $granted = [];

    private function controller(): PermissionController {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(fn (string $name, $default = null) => $this->params[$name] ?? $default);
        $request->method('getParams')->willReturnCallback(fn () => $this->params);
        $service = $this->createMock(PermissionService::class);
        $service->method('grantUserPermission')->willReturnCallback(function (string $user, string $type, ?int $folder): void {
            $this->granted[] = [$user, $type, $folder];
        });
        return new PermissionController('metavox', $request, $service, $this->createMock(IGroupManager::class));
    }

    private function refusal(): array {
        try {
            $this->controller()->grantUserPermission();
        } catch (ApiValidationException $e) {
            return $e->getErrors();
        }
        $this->fail('The grant was accepted');
    }

    public function testWithoutATeamFolderItHoldsForAll(): void {
        $this->params = ['user_id' => 'anna', 'permission_type' => 'manage_fields'];
        $this->controller()->grantUserPermission();
        $this->params['groupfolder_id'] = '7';
        $this->controller()->grantUserPermission();

        $this->assertSame([['anna', 'manage_fields', null], ['anna', 'manage_fields', 7]], $this->granted);
    }

    public function testAnythingButATeamFolderIsRefused(): void {
        foreach ([0, '0', '', 'abc', '7abc', -1] as $folder) {
            $this->params = ['user_id' => 'anna', 'permission_type' => 'manage_fields', 'groupfolder_id' => $folder];
            $this->assertSame(['groupfolder_id' => 'Enter a whole number'], $this->refusal(), json_encode($folder));
        }
        $this->assertSame([], $this->granted, 'and no grant for all team folders');
    }

    public function testWhoAndWhatAreRequired(): void {
        $this->params = ['permission_type' => 'manage_fields'];
        $this->assertSame(['user_id' => 'This field is required'], $this->refusal());
        $this->params = ['user_id' => 'anna'];
        $this->assertSame(['permission_type' => 'This field is required'], $this->refusal());
        $this->params = ['user_id' => 'anna', 'permission_type' => 'admin'];
        $this->assertSame(['permission_type' => 'This value is not valid'], $this->refusal());
    }

    public function testTheServiceRefusesWhoOrWhatDoesNotExist(): void {
        $result = $this->createMock(IResult::class);
        $result->method('fetchOne')->willReturn(false);
        $qb = $this->createMock(IQueryBuilder::class);
        foreach (['select', 'from', 'where', 'andWhere'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
        $qb->method('executeQuery')->willReturn($result);
        $qb->expects($this->never())->method('insert');
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($qb);
        $users = $this->createMock(IUserManager::class);
        $users->method('userExists')->willReturn(false);
        $groups = $this->createMock(IGroupManager::class);
        $groups->method('groupExists')->willReturn(false);
        $service = new PermissionService($db, $users, $groups, $this->createMock(LoggerInterface::class));

        foreach ([
            [fn () => $service->grantUserPermission('nobody', 'manage_fields', 99), ['user_id' => 'Choose an existing account', 'groupfolder_id' => 'Choose an existing Team folder']],
            [fn () => $service->grantGroupPermission('nogroup', 'manage_fields'), ['group_id' => 'Choose an existing group']],
        ] as [$grant, $errors]) {
            try {
                $grant();
                $this->fail('A grant to nobody was accepted');
            } catch (ApiValidationException $e) {
                $this->assertSame($errors, $e->getErrors());
            }
        }
    }
}
