<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Controller;

use OCA\MetaVox\Controller\PermissionController;
use OCA\MetaVox\Exception\RequestException;
use OCA\MetaVox\Service\PermissionService;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** A grant is revoked by the id in the URL, and only through the route of its holder. */
class PermissionRevokeTest extends TestCase {

    private array $where = [];

    private function service(int $deleted): PermissionService {
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturnCallback(fn($column, $value) => "$column = $value");
        $expr->method('isNotNull')->willReturnCallback(fn($column) => "$column IS NOT NULL");
        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('expr')->willReturn($expr);
        $qb->method('delete')->willReturnCallback(function ($table) use ($qb) {
            $this->where[] = "DELETE $table";
            return $qb;
        });
        $qb->method('createNamedParameter')->willReturnCallback(fn($value) => (string)$value);
        foreach (['where', 'andWhere'] as $method) {
            $qb->method($method)->willReturnCallback(function ($condition) use ($qb) {
                $this->where[] = $condition;
                return $qb;
            });
        }
        $qb->method('executeStatement')->willReturn($deleted);
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($qb);
        return new PermissionService($db, $this->createMock(IUserManager::class), $this->createMock(IGroupManager::class), $this->createMock(LoggerInterface::class));
    }

    private function controller(PermissionService $service): PermissionController {
        return new PermissionController('metavox', $this->createMock(IRequest::class), $service, $this->createMock(IGroupManager::class));
    }

    public function testAUserGrantIsRevokedByItsId(): void {
        $response = $this->controller($this->service(1))->revokeUserPermission(17);

        $this->assertSame(['success' => true], $response->getData());
        $this->assertSame(['DELETE metavox_permissions', 'id = 17', 'user_id IS NOT NULL'], $this->where);
    }

    public function testAGroupGrantOnlyThroughTheGroupRoute(): void {
        $this->controller($this->service(1))->revokeGroupPermission(17);

        $this->assertSame(['DELETE metavox_permissions', 'id = 17', 'group_id IS NOT NULL'], $this->where);
    }

    public function testAnUnknownIdIsNotFound(): void {
        try {
            $this->controller($this->service(0))->revokeUserPermission(99);
            $this->fail('no refusal');
        } catch (RequestException $e) {
            $this->assertSame(404, $e->getStatus());
            $this->assertSame('not_found', $e->getReason());
        }
    }
}
