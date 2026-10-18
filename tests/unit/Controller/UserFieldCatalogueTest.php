<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Controller;

use OCA\MetaVox\Controller\UserFieldController;
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

/** The field catalogue, and for the search picker the fields of the user's own team folders. */
class UserFieldCatalogueTest extends TestCase {

    /** @var array<string, mixed> */
    private array $params = [];

    /** @var list<?array> the folder ids each catalogue was asked for */
    private array $asked = [];

    private function controller(?array $catalogueFolders): UserFieldController {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => $this->params[$key] ?? $default);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);
        $fields = $this->createMock(FieldService::class);
        // The team folders alice is in.
        $fields->method('getGroupfolders')->with('alice')->willReturn([['id' => 5, 'mount_point' => 'HR'], ['id' => '6', 'mount_point' => 'Sales']]);
        $fields->method('getCatalogue')->willReturnCallback(function (?array $ids) {
            $this->asked[] = $ids;
            return [['field_name' => 'file_gf_status']];
        });
        $teamFolders = $this->createMock(TeamFolderAccessService::class);
        $teamFolders->method('catalogueFolders')->willReturn($catalogueFolders);
        return new UserFieldController('metavox', $request, $this->createMock(UserFieldService::class), $fields,
            $this->createMock(FilterService::class), $session, $this->createMock(FileAccessService::class), $teamFolders,
            $this->createMock(FileReferenceService::class));
    }

    public function testTheCatalogueAsBefore(): void {
        // An admin or manager: every field.
        $this->controller(null)->getAllGroupfolderFields();

        $this->assertSame([null], $this->asked);
    }

    public function testForTheSearchOnlyTheirOwnTeamFolders(): void {
        // Admins and managers too: a search only reaches the team folders they are in.
        $this->params = ['scope' => 'member'];

        $answer = $this->controller(null)->getAllGroupfolderFields();

        $this->assertSame([[5, 6]], $this->asked);
        $this->assertSame([['field_name' => 'file_gf_status']], $answer->getData());
    }
}
