<?php

declare(strict_types=1);

namespace OCA\MetaVox\Listener;

use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\MetaVox\Dav\MetadataPropertyPlugin;
use OCA\MetaVox\Dav\SavedSearchReportPlugin;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;

/**
 * Adds MetaVox's plugins to NC's DAV server, only for files under
 * remote.php/dav: the metadata plugin for PROPFIND/PROPPATCH, the saved
 * searches for REPORT. NC never fires this event for public links.
 *
 * @template-implements IEventListener<SabrePluginAddEvent>
 */
class SabrePluginAddListener implements IEventListener {

    public function __construct(
        private readonly ContainerInterface $container,
    ) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof SabrePluginAddEvent) {
            return;
        }
        $server = $event->getServer();
        try {
            $plugin = match ($server->httpRequest->getMethod()) {
                'PROPFIND', 'PROPPATCH' => MetadataPropertyPlugin::class,
                'REPORT' => SavedSearchReportPlugin::class,
                default => null,
            };
            if ($plugin === null
                || !str_ends_with($server->getBaseUri(), '/remote.php/dav/')
                || !str_starts_with($server->getRequestUri(), 'files/')) {
                return;
            }
            $server->addPlugin($this->container->get($plugin));
        } catch (\Throwable) {
            // Never break a DAV request over metadata.
        }
    }
}
