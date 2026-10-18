<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Service\FileAccess;
use OCA\MetaVox\Service\FileAccessService;
use OCA\MetaVox\Service\FileReferenceService;
use OCA\MetaVox\Service\GroupfolderRootService;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/** Which files a user may link to: the ones they may open in that team folder, as for its metadata. */
class FileLinkAccessTest extends TestCase {

    public function testOnlyFilesTheUserMayOpenInThatTeamFolder(): void {
        $access = $this->createMock(FileAccessService::class);
        $access->expects($this->once())->method('prime')->with('alice', [1, 2, 3]);
        $access->method('getFileAccess')->willReturnCallback(fn (string $user, int $id): FileAccess => match ($id) {
            1 => new FileAccess(22),
            // Hidden by ACL, or not found at all.
            2 => FileAccess::none(),
            // Readable, through another team folder.
            3 => new FileAccess(23),
        });
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->with(FileAccessService::class)->willReturn($access);
        $service = new FileReferenceService($this->createMock(IDBConnection::class), $this->createMock(IRootFolder::class),
            $this->createMock(LoggerInterface::class), $this->createMock(GroupfolderRootService::class),
            $this->createMock(IUserSession::class), $container);

        $this->assertSame([1], $service->linkableFiles('alice', [1, 2, 3], 22));
    }
}
