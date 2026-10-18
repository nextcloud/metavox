<?php

declare(strict_types=1);

namespace OCA\MetaVox\Listener;

use OCA\MetaVox\Service\PresenceService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserLiveStatusEvent;

/**
 * Nextcloud's status app sends a heartbeat every 5 minutes while a tab is
 * open. MetaVox rides along on it to keep the presence of that user alive,
 * instead of sending a heartbeat of its own.
 *
 * @template-implements IEventListener<UserLiveStatusEvent>
 */
class PresenceKeepAliveListener implements IEventListener {

    public function __construct(
        private readonly PresenceService $presence,
    ) {
    }

    public function handle(Event $event): void {
        if ($event instanceof UserLiveStatusEvent) {
            $this->presence->keepAlive($event->getUser()->getUID());
        }
    }
}
