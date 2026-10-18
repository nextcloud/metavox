<?php

declare(strict_types=1);

namespace OCA\MetaVox\Middleware;

use OCA\MetaVox\Access\Access;
use OCA\MetaVox\Access\AccessContext;
use OCA\MetaVox\Access\RequireAccess;
use OCA\MetaVox\Exception\MetadataAccessException;
use OCA\MetaVox\Service\FileAccessService;
use OCA\MetaVox\Service\TeamFolderAccessService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The one gate for HTTP: checks the #[RequireAccess] rule of a route before
 * the controller runs. Registered after ExceptionMiddleware, which turns a
 * refusal into a clean error response. A route without a rule is refused.
 */
final class AccessMiddleware extends Middleware {

    public function __construct(
        private readonly IRequest $request,
        private readonly IUserSession $userSession,
        private readonly FileAccessService $files,
        private readonly TeamFolderAccessService $teamFolders,
        private readonly AccessContext $context,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function beforeController(Controller $controller, string $methodName): void {
        $method = new \ReflectionMethod($controller, $methodName);
        $rules = $method->getAttributes(RequireAccess::class);
        $route = $controller::class . '::' . $methodName;
        if ($rules === []) {
            $this->logger->error('MetaVox: route without access rule {route}', ['route' => $route]);
            throw MetadataAccessException::forbidden();
        }
        if (count($rules) > 1) {
            $this->logger->error('MetaVox: route with more than one access rule {route}', ['route' => $route]);
            throw MetadataAccessException::forbidden();
        }

        $rule = $rules[0]->newInstance();
        // The admin split is Nextcloud's own: admin routes lack #[NoAdminRequired], the rest have it.
        $noAdminRequired = $method->getAttributes(NoAdminRequired::class) !== [];
        if (($rule->level === Access::NcAdmin) === $noAdminRequired) {
            $this->logger->error('MetaVox: access level and #[NoAdminRequired] disagree on {route}', ['route' => $route]);
            throw MetadataAccessException::forbidden();
        }

        $userId = $this->userSession->getUser()?->getUID() ?? throw MetadataAccessException::forbidden();
        $this->context->setUser($userId);

        match ($rule->level) {
            Access::NcAdmin => $this->teamFolders->isAdmin($userId) ?: throw MetadataAccessException::forbidden(),
            Access::LoggedIn => null,
            Access::TeamFolderMember => $this->teamFolders->isMember($userId, $this->id($rule->folder, $route)) ?: throw MetadataAccessException::notMember(),
            Access::CanUpdateTeamFolder => $this->requireTeamFolderUpdate($userId, $this->id($rule->folder, $route)),
            Access::MetaVoxManager => $this->requireManager($userId, $this->id($rule->folder, $route)),
            Access::CanRead => $this->context->setGroupfolder(
                $this->files->requireReadableFile($userId, $this->id($rule->file, $route), $this->optionalId($rule->folder, $route)),
            ),
            Access::CanUpdate => $this->context->setGroupfolder(
                $this->files->requireWritableFile($userId, $this->id($rule->file, $route), $this->optionalId($rule->folder, $route)),
            ),
            // The controller checks every file id; the gate only checks the folder, when there is one.
            Access::FileList => $rule->folder === null
                || $this->teamFolders->isMember($userId, $this->id($rule->folder, $route))
                || throw MetadataAccessException::notMember(),
        };
    }

    /** Not a member: 404, as if the team folder isn't there. A member who may only read it: 403. */
    private function requireTeamFolderUpdate(string $userId, int $groupfolderId): void {
        if (!$this->teamFolders->isMember($userId, $groupfolderId)) {
            throw MetadataAccessException::notMember();
        }
        if (!$this->teamFolders->canUpdateTeamFolder($userId, $groupfolderId)) {
            throw MetadataAccessException::teamFolderReadOnly();
        }
    }

    /** Not a member: 404, as if the team folder isn't there. A member without the right: 403. */
    private function requireManager(string $userId, int $groupfolderId): void {
        if (!$this->teamFolders->isMember($userId, $groupfolderId)) {
            throw MetadataAccessException::notMember();
        }
        if (!$this->teamFolders->canManageGroupfolder($userId, $groupfolderId)) {
            throw MetadataAccessException::notManager();
        }
    }

    /**
     * The id from the same parameter the controller gets, but stricter: only
     * positive whole numbers.
     */
    private function id(?string $param, string $route): int {
        if ($param === null) {
            $this->logger->error('MetaVox: access rule on {route} names no parameter for its level', ['route' => $route]);
            throw MetadataAccessException::forbidden();
        }
        $raw = $this->request->getParam($param);
        if ((is_int($raw) || (is_string($raw) && ctype_digit($raw))) && (int)$raw > 0) {
            return (int)$raw;
        }
        throw MetadataAccessException::badRequest();
    }

    private function optionalId(?string $param, string $route): ?int {
        return $param === null ? null : $this->id($param, $route);
    }
}
