<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\AppInfo;

use OCA\MetaVox\AppInfo\Application;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class RequestedDirTest extends TestCase {

    private function request(mixed $param, string $uri): IRequest {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->with('dir')->willReturn($param);
        $request->method('getRequestUri')->willReturn($uri);
        return $request;
    }

    public function testTakesTheDirParameter(): void {
        $this->assertSame('/MetaVoxTest/klein-50', Application::requestedDir($this->request('/MetaVoxTest/klein-50', '/index.php/apps/files/files')));
    }

    public function testFallsBackToTheRequestUri(): void {
        // Pretty URL through Apache's 404 fallback: no query parameters, the URI still has them.
        $uri = '/apps/files/files?dir=%2FMetaVoxTest%2Fklein-50&mvview=2';

        $this->assertSame('/MetaVoxTest/klein-50', Application::requestedDir($this->request(null, $uri)));
    }

    public function testIsEmptyWithoutADir(): void {
        $this->assertSame('', Application::requestedDir($this->request(null, '/apps/files/files')));
        $this->assertSame('', Application::requestedDir($this->request('', '/apps/files/files?view=files')));
    }

    public function testIgnoresADirThatIsNotAString(): void {
        $this->assertSame('', Application::requestedDir($this->request(['x'], '/apps/files/files?dir[]=x')));
    }
}
