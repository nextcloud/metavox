<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Controller;

use OCA\MetaVox\Controller\BaseController;
use OCA\MetaVox\Controller\FieldController;
use OCP\AppFramework\Controller;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

/** The user picker finds whom you could share with: Nextcloud's own sharee search decides. */
class UserSearchTest extends TestCase {

    private ?array $asked = null;

    private function search(string $term, array $exact, array $wide): array {
        $collaborators = $this->createMock(ISearch::class);
        $collaborators->method('search')->willReturnCallback(function (...$args) use ($exact, $wide) {
            $this->asked = $args;
            return [['exact' => ['users' => $exact], 'users' => $wide], false];
        });
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(fn(string $key, $default = null) => $key === 'search' ? $term : $default);
        $me = $this->createMock(IUser::class);
        $me->method('getUID')->willReturn('alice');
        $me->method('getDisplayName')->willReturn('Alice Jansen');
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($me);

        // Built without its constructor: IRootFolder can't be mocked with the OCP stubs.
        $controller = (new \ReflectionClass(FieldController::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(Controller::class, 'request'))->setValue($controller, $request);
        (new \ReflectionProperty(BaseController::class, 'userSession'))->setValue($controller, $session);
        (new \ReflectionProperty(FieldController::class, 'collaboratorSearch'))->setValue($controller, $collaborators);
        return $controller->getUsers()->getData();
    }

    private static function sharee(string $uid, string $name): array {
        return ['label' => $name, 'value' => ['shareType' => IShare::TYPE_USER, 'shareWith' => $uid]];
    }

    public function testAsksNextcloudForUsersOnly(): void {
        $this->search(' bob ', [], []);

        $this->assertSame(['bob', [IShare::TYPE_USER], false, 25, 0], $this->asked);
    }

    public function testGivesWhatNextcloudFindsExactMatchesFirst(): void {
        $users = $this->search('bo', [self::sharee('bo', 'Bo')], [self::sharee('bob', 'Bob'), self::sharee('bo', 'Bo')]);

        $this->assertSame([['id' => 'bo', 'displayname' => 'Bo'], ['id' => 'bob', 'displayname' => 'Bob']], $users);
    }

    public function testOnlyWhatNextcloudFinds(): void {
        $this->assertSame([], $this->search('carol', [], []));
    }

    public function testYouCanPickYourself(): void {
        $this->assertSame([['id' => 'alice', 'displayname' => 'Alice Jansen']], $this->search('jans', [], []));
        $this->assertSame('alice', $this->search('', [], [self::sharee('bob', 'Bob')])[0]['id']);
    }

    public function testANumericUserIdStaysAString(): void {
        $this->assertSame([['id' => '123', 'displayname' => 'Nummer']], $this->search('nummer', [], [self::sharee('123', 'Nummer')]));
    }
}
