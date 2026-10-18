<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\AppInfo;

use OCA\MetaVox\AppInfo\Application;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/** The Files integration loads on Files pages only, not where a URL merely mentions Files. */
class FilesPageTest extends TestCase {

    private function request(string $uri, string|false $pathInfo = false, ?string $app = null): IRequest {
        $request = $this->createMock(IRequest::class);
        $request->method('getRequestUri')->willReturn($uri);
        $request->method('getPathInfo')->willReturn($pathInfo);
        $request->method('getParam')->with('app')->willReturn($app);
        return $request;
    }

    public static function filesPages(): array {
        return [
            'index.php' => ['/index.php/apps/files/files?dir=/MetaVoxTest'],
            'pretty URL' => ['/apps/files/'],
            'a file opened' => ['/index.php/apps/files/files/12352?dir=/MetaVoxTest&openfile=true'],
            'under a subpath' => ['/cloud/index.php/apps/files/'],
        ];
    }

    /** @dataProvider filesPages */
    public function testFilesPages(string $uri): void {
        $this->assertTrue(Application::isFilesPage($this->request($uri)));
    }

    public static function otherPages(): array {
        return [
            // Where Nextcloud sends a logged-out visitor of a Files link.
            'login back to Files' => ['/index.php/login?redirect_url=/index.php/apps/files/files?dir%3D/MetaVoxTest'],
            'login, pretty URL' => ['/index.php/login?redirect_url=/apps/files/'],
            'public link' => ['/index.php/s/aBcD1234'],
            'dashboard' => ['/index.php/apps/dashboard/'],
        ];
    }

    /** @dataProvider otherPages */
    public function testOtherPages(string $uri): void {
        $this->assertFalse(Application::isFilesPage($this->request($uri)));
    }

    public function testBehindAReverseProxyThePathInfoCounts(): void {
        $this->assertTrue(Application::isFilesPage($this->request('/proxied/', '/apps/files/')));
    }

    public function testTheLegacyAppParameterCounts(): void {
        $this->assertTrue(Application::isFilesPage($this->request('/index.php', false, 'files')));
        $this->assertFalse(Application::isFilesPage($this->request('/index.php', false, 'dashboard')));
    }
}
