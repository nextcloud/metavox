<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Listener;

use OCA\MetaVox\Listener\PresenceKeepAliveListener;
use OCA\MetaVox\Service\PresenceService;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\User\Events\UserLiveStatusEvent;
use PHPUnit\Framework\TestCase;

/** MetaVox rides along on Nextcloud's own status heartbeat instead of sending one. */
class PresenceKeepAliveListenerTest extends TestCase {

    public function testTheStatusHeartbeatKeepsThePresenceAlive(): void {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $presence = $this->createMock(PresenceService::class);
        $presence->expects($this->once())->method('keepAlive')->with('alice');

        (new PresenceKeepAliveListener($presence))->handle(new UserLiveStatusEvent($user, 'away', 1_000_000));
    }

    public function testOtherEventsAreIgnored(): void {
        $presence = $this->createMock(PresenceService::class);
        $presence->expects($this->never())->method('keepAlive');

        (new PresenceKeepAliveListener($presence))->handle(new Event());
    }
}
