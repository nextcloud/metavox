<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Middleware;

use OCA\MetaVox\Access\Access;
use OCA\MetaVox\Access\AccessContext;
use OCA\MetaVox\Access\RequireAccess;
use OCA\MetaVox\Exception\MetadataAccessException;
use OCA\MetaVox\Middleware\AccessMiddleware;
use OCA\MetaVox\Middleware\ExceptionMiddleware;
use OCA\MetaVox\Service\FileAccessService;
use OCA\MetaVox\Service\TeamFolderAccessService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** One method per level and per misconfiguration. */
final class AccessFixtureController extends Controller {
    #[RequireAccess(Access::NcAdmin)]
    public function admin(): void {}

    #[NoAdminRequired]
    #[RequireAccess(Access::LoggedIn, reason: 'only the caller\'s own data')]
    public function user(): void {}

    #[NoAdminRequired]
    #[RequireAccess(Access::TeamFolderMember, folder: 'groupfolderId')]
    public function member(): void {}

    #[NoAdminRequired]
    #[RequireAccess(Access::CanUpdateTeamFolder, folder: 'groupfolderId')]
    public function teamFolderWriter(): void {}

    #[NoAdminRequired]
    #[RequireAccess(Access::MetaVoxManager, folder: 'groupfolderId')]
    public function manager(): void {}

    #[NoAdminRequired]
    #[RequireAccess(Access::CanRead, file: 'fileId')]
    public function read(): void {}

    #[NoAdminRequired]
    #[RequireAccess(Access::CanRead, folder: 'groupfolderId', file: 'fileId')]
    public function readInFolder(): void {}

    #[NoAdminRequired]
    #[RequireAccess(Access::CanUpdate, folder: 'groupfolderId', file: 'fileId')]
    public function write(): void {}

    #[NoAdminRequired]
    #[RequireAccess(Access::FileList, folder: 'groupfolderId')]
    public function batchInFolder(): void {}

    #[NoAdminRequired]
    #[RequireAccess(Access::FileList)]
    public function batch(): void {}

    #[NoAdminRequired]
    public function unmarked(): void {}

    #[NoAdminRequired]
    #[RequireAccess(Access::LoggedIn, reason: 'a')]
    #[RequireAccess(Access::LoggedIn, reason: 'b')]
    public function twoRules(): void {}

    #[RequireAccess(Access::LoggedIn, reason: 'own data')]
    public function userWithoutNoAdminRequired(): void {}

    #[NoAdminRequired]
    #[RequireAccess(Access::NcAdmin)]
    public function adminWithNoAdminRequired(): void {}

    #[NoAdminRequired]
    #[RequireAccess(Access::TeamFolderMember)]
    public function memberWithoutParameter(): void {}
}

class AccessMiddlewareTest extends TestCase {

    private const FOLDER = 9;
    private const FILE = 42;

    private array $params = [];
    private ?string $user = null;
    private AccessContext $context;
    private LoggerInterface $logger;

    private function middleware(): AccessMiddleware {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(fn ($name) => $this->params[$name] ?? null);

        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturnCallback(function () {
            if ($this->user === null) {
                return null;
            }
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($this->user);
            return $user;
        });

        // The world: team folder 9 with file 42; editor may change it, reader only read it.
        $policy = $this->createMock(TeamFolderAccessService::class);
        $policy->method('isAdmin')->willReturnCallback(fn ($uid) => $uid === 'admin');
        // Admins pass every team folder, like the real policy.
        $policy->method('isMember')->willReturnCallback(fn ($uid, $gf) => $uid === 'admin' || ($gf === self::FOLDER && in_array($uid, ['member', 'manager', 'editor', 'reader'], true)));
        $policy->method('canManageGroupfolder')->willReturnCallback(fn ($uid, $gf) => $uid === 'admin' || ($gf === self::FOLDER && $uid === 'manager'));
        // The reader may only read the team folder.
        $policy->method('canUpdateTeamFolder')->willReturnCallback(fn ($uid, $gf) => $uid === 'admin' || ($gf === self::FOLDER && in_array($uid, ['member', 'manager', 'editor'], true)));
        $file = function (bool $write) {
            return function ($uid, $fileId, $gf) use ($write) {
                if ($fileId !== self::FILE || !in_array($uid, ['admin', 'editor', 'reader'], true)) {
                    throw MetadataAccessException::notFound();
                }
                if ($gf !== null && $gf !== self::FOLDER) {
                    throw MetadataAccessException::wrongGroupfolder();
                }
                if ($write && $uid === 'reader') {
                    throw MetadataAccessException::readOnly();
                }
                return self::FOLDER;
            };
        };
        $files = $this->createMock(FileAccessService::class);
        $files->method('requireReadableFile')->willReturnCallback($file(false));
        $files->method('requireWritableFile')->willReturnCallback($file(true));

        $this->context = new AccessContext();
        $this->logger = $this->createMock(LoggerInterface::class);
        return new AccessMiddleware($request, $session, $files, $policy, $this->context, $this->logger);
    }

    /** @return int|null the refusal status, null when the route may run */
    private function refusal(string $method, ?string $user, array $params = []): ?int {
        $this->user = $user;
        $this->params = $params;
        $middleware = $this->middleware();
        try {
            $middleware->beforeController(new AccessFixtureController('metavox', $this->createMock(IRequest::class)), $method);
            return null;
        } catch (MetadataAccessException $e) {
            return $e->getStatus();
        }
    }

    public static function levels(): array {
        $folder = ['groupfolderId' => '9'];
        $file = ['groupfolderId' => '9', 'fileId' => '42'];
        return [
            'admin route, admin' => ['admin', 'admin', [], null],
            'admin route, member' => ['admin', 'member', [], 403],
            'user route, outsider' => ['user', 'outsider', [], null],
            'member route, member' => ['member', 'member', $folder, null],
            // What you may not see is a 404, what you see but may not do a 403.
            'member route, outsider' => ['member', 'outsider', $folder, 404],
            'member route, other folder' => ['member', 'member', ['groupfolderId' => '10'], 404],
            // Views and the team folder's own metadata: write permission on the team folder, no grant.
            'team folder write route, member who may write' => ['teamFolderWriter', 'member', $folder, null],
            'team folder write route, reader' => ['teamFolderWriter', 'reader', $folder, 403],
            'team folder write route, outsider' => ['teamFolderWriter', 'outsider', $folder, 404],
            'team folder write route, admin' => ['teamFolderWriter', 'admin', $folder, null],
            'manager route, manager' => ['manager', 'manager', $folder, null],
            'manager route, member' => ['manager', 'member', $folder, 403],
            'manager route, outsider' => ['manager', 'outsider', $folder, 404],
            'manager route, admin' => ['manager', 'admin', $folder, null],
            'read, reader' => ['read', 'reader', ['fileId' => '42'], null],
            'read, invisible file' => ['read', 'reader', ['fileId' => '7'], 404],
            'read, outsider' => ['read', 'outsider', ['fileId' => '42'], 404],
            'read in folder, wrong folder' => ['readInFolder', 'reader', ['groupfolderId' => '3', 'fileId' => '42'], 400],
            'write, editor' => ['write', 'editor', $file, null],
            'write, read-only' => ['write', 'reader', $file, 403],
            'batch in folder, member' => ['batchInFolder', 'member', $folder, null],
            'batch in folder, outsider' => ['batchInFolder', 'outsider', $folder, 404],
            'batch without folder, outsider' => ['batch', 'outsider', [], null],
        ];
    }

    #[DataProvider('levels')]
    public function testLevel(string $method, string $user, array $params, ?int $expected): void {
        $this->assertSame($expected, $this->refusal($method, $user, $params));
    }

    public static function badIds(): array {
        return [
            'missing' => [[]],
            'zero' => [['groupfolderId' => '0']],
            'negative' => [['groupfolderId' => '-1']],
            'not a number' => [['groupfolderId' => '12abc']],
            'a list' => [['groupfolderId' => ['9']]],
        ];
    }

    #[DataProvider('badIds')]
    public function testRefusesABadId(array $params): void {
        $this->assertSame(400, $this->refusal('member', 'member', $params));
    }

    public function testKeepsWhatItCheckedForTheController(): void {
        $this->assertNull($this->refusal('read', 'reader', ['fileId' => '42']));

        $this->assertSame('reader', $this->context->getUserId());
        $this->assertSame(self::FOLDER, $this->context->getGroupfolderId());
    }

    public function testRefusesWithoutASessionUser(): void {
        $this->assertSame(403, $this->refusal('user', null));
    }

    public function testRefusesARouteWithoutRule(): void {
        $middleware = $this->middleware();
        $this->logger->expects($this->once())->method('error');
        $this->user = 'admin';

        try {
            $middleware->beforeController(new AccessFixtureController('metavox', $this->createMock(IRequest::class)), 'unmarked');
            $this->fail('Expected a refusal');
        } catch (MetadataAccessException $e) {
            $this->assertSame(403, $e->getStatus());
        }
    }

    public static function misconfigured(): array {
        return [
            'two rules' => ['twoRules'],
            'non-admin level without #[NoAdminRequired]' => ['userWithoutNoAdminRequired'],
            'admin level with #[NoAdminRequired]' => ['adminWithNoAdminRequired'],
            'level without its parameter' => ['memberWithoutParameter'],
        ];
    }

    #[DataProvider('misconfigured')]
    public function testRefusesAMisconfiguredRoute(string $method): void {
        $this->assertSame(403, $this->refusal($method, 'admin', ['groupfolderId' => '9']));
    }

    public function testARefusalBecomesAJsonErrorThroughExceptionMiddleware(): void {
        $this->user = 'outsider';
        $this->params = ['groupfolderId' => '9'];
        $controller = new AccessFixtureController('metavox', $this->createMock(IRequest::class));
        try {
            $this->middleware()->beforeController($controller, 'member');
            $this->fail('Expected a refusal');
        } catch (MetadataAccessException $e) {
            $response = (new ExceptionMiddleware($this->createMock(IRequest::class), $this->createMock(IConfig::class), $this->createMock(LoggerInterface::class), $this->translator()))
                ->afterException($controller, 'member', $e);
        }

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(404, $response->getStatus());
        $this->assertSame(['error' => 'Niet gevonden', 'reason' => 'not_found'], $response->getData());
    }

    private function translator(): IL10N {
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(fn (string $text) => ['Not found' => 'Niet gevonden'][$text] ?? $text);
        return $l;
    }

    public function testIsRegisteredAfterExceptionMiddleware(): void {
        $source = file_get_contents(__DIR__ . '/../../../lib/AppInfo/Application.php');
        $exception = strpos($source, 'registerMiddleware(ExceptionMiddleware::class)');
        $access = strpos($source, 'registerMiddleware(AccessMiddleware::class)');

        $this->assertNotFalse($exception);
        $this->assertNotFalse($access);
        // Refusals unwind from the gate into the earlier ExceptionMiddleware.
        $this->assertLessThan($access, $exception);
    }
}
