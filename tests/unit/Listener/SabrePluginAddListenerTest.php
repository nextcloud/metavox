<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Listener;

require_once __DIR__ . '/../../stubs/Dav.php';

use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\MetaVox\Dav\MetadataPropertyPlugin;
use OCA\MetaVox\Dav\SavedSearchReportPlugin;
use OCA\MetaVox\Listener\SabrePluginAddListener;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Sabre\DAV\Server;
use Sabre\HTTP\Request;

class SabrePluginAddListenerTest extends TestCase {

    private ContainerInterface&MockObject $container;

    protected function setUp(): void {
        $plugin = $this->createMock(MetadataPropertyPlugin::class);
        $plugin->method('getPluginName')->willReturn('metavox');
        $report = $this->createMock(SavedSearchReportPlugin::class);
        $report->method('getPluginName')->willReturn('metavox-search');
        $this->container = $this->createMock(ContainerInterface::class);
        $this->container->method('get')->willReturnMap([
            [MetadataPropertyPlugin::class, $plugin],
            [SavedSearchReportPlugin::class, $report],
        ]);
    }

    private function server(string $method, string $url, string $baseUri = '/remote.php/dav/'): Server {
        $server = new Server();
        $server->httpRequest = new Request($method, $url);
        $server->setBaseUri($baseUri);
        return $server;
    }

    private function handle(Server $server, string $plugin = 'metavox'): bool {
        (new SabrePluginAddListener($this->container))->handle(new SabrePluginAddEvent($server));
        return $server->getPlugin($plugin) !== null;
    }

    public function testAddedForFilePropfindAndProppatch(): void {
        $this->assertTrue($this->handle($this->server('PROPFIND', '/remote.php/dav/files/alice/Team')));
        $this->assertTrue($this->handle($this->server('PROPPATCH', '/remote.php/dav/files/alice/Team/a.pdf')));
    }

    public function testAddedBehindASubdirectory(): void {
        $this->assertTrue($this->handle($this->server('PROPFIND', '/nextcloud/remote.php/dav/files/alice', '/nextcloud/remote.php/dav/')));
    }

    public function testSavedSearchesForAReportOnFiles(): void {
        $server = $this->server('REPORT', '/remote.php/dav/files/alice');

        $this->assertTrue($this->handle($server, 'metavox-search'));
        $this->assertNull($server->getPlugin('metavox'));
        $this->assertFalse($this->handle($this->server('PROPFIND', '/remote.php/dav/files/alice'), 'metavox-search'));
    }

    public function testNotAddedForOtherMethods(): void {
        $this->container->expects($this->never())->method('get');

        foreach (['GET', 'PUT', 'SEARCH', 'MKCOL'] as $method) {
            $this->assertFalse($this->handle($this->server($method, '/remote.php/dav/files/alice/Team')), $method);
            $this->assertFalse($this->handle($this->server($method, '/remote.php/dav/files/alice/Team'), 'metavox-search'), $method);
        }
    }

    public function testNotAddedOutsideFilesOrOnLegacyWebdav(): void {
        $this->container->expects($this->never())->method('get');

        foreach (['calendars/alice/personal', 'photos/alice/albums', 'trashbin/alice/trash', 'versions/alice/versions/1', 'comments/files/12', ''] as $path) {
            $this->assertFalse($this->handle($this->server('PROPFIND', '/remote.php/dav/' . $path)), $path);
            $this->assertFalse($this->handle($this->server('REPORT', '/remote.php/dav/' . $path), 'metavox-search'), $path);
        }
        $this->assertFalse($this->handle($this->server('PROPFIND', '/remote.php/webdav/Team', '/remote.php/webdav/')));
        $this->assertFalse($this->handle($this->server('REPORT', '/remote.php/webdav/', '/remote.php/webdav/'), 'metavox-search'));
        $this->assertFalse($this->handle($this->server('REPORT', '/public.php/dav/files/token', '/public.php/dav/'), 'metavox-search'));
    }

    public function testIgnoresOtherEvents(): void {
        $this->container->expects($this->never())->method('get');

        (new SabrePluginAddListener($this->container))->handle(new Event());
    }

    public function testNeverBreaksTheRequest(): void {
        // The URL lies outside the base URI, so getRequestUri() throws.
        $server = $this->server('PROPFIND', '/index.php/apps/files');

        $this->assertFalse($this->handle($server));
    }
}
