<?php

declare(strict_types=1);

namespace OCA\MetaVox\Listener;

use OCA\MetaVox\Flow\MetadataCheck;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IServerContainer;
use OCP\Util;
use OCP\WorkflowEngine\Events\RegisterChecksEvent;

/**
 * Listener to register MetaVox Flow checks with the Workflow Engine
 *
 * @template-implements IEventListener<RegisterChecksEvent>
 */
class RegisterFlowChecksListener implements IEventListener {

    public function __construct(
        private IServerContainer $container,
    ) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof RegisterChecksEvent) {
            return;
        }

        // Register the MetaVox metadata check
        $check = $this->container->get(MetadataCheck::class);
        $event->registerCheck($check);

        // Load the Flow UI script
        Util::addScript('metavox', 'metavox-flow');
    }
}
