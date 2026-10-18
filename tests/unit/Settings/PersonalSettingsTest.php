<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Settings;

use OCA\MetaVox\Service\TeamFolderAccessService;
use OCA\MetaVox\Settings\Personal;
use OCA\MetaVox\Settings\PersonalSection;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/** The personal MetaVox settings are for admins and managers, by the same rule as the routes. */
class PersonalSettingsTest extends TestCase {

    private function session(string $uid): IUserSession {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);
        return $session;
    }

    private function access(): TeamFolderAccessService {
        $access = $this->createMock(TeamFolderAccessService::class);
        $access->method('managesAnyFolder')->willReturnCallback(fn ($uid) => $uid === 'manager');
        return $access;
    }

    public function testTheSectionShowsForManagersOnly(): void {
        $section = fn ($uid) => new PersonalSection($this->createMock(IL10N::class), $this->createMock(IURLGenerator::class), $this->access(), $this->session($uid));

        $this->assertSame('metavox-personal', $section('manager')->getID());
        $this->assertSame('metavox-personal-hidden', $section('member')->getID());
    }

    public function testThePageStaysEmptyForOthers(): void {
        $this->assertNull((new Personal($this->access(), $this->session('member')))->getForm());
    }
}
