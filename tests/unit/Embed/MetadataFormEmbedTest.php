<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Embed;

use OCA\MetaVox\Embed\MetadataFormEmbed;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

class MetadataFormEmbedTest extends TestCase {

    public function testAvailableForTheGivenUser(): void {
        $user = $this->createMock(IUser::class);
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->with('alice')->willReturn($user);
        $apps = $this->createMock(IAppManager::class);
        $apps->expects($this->once())->method('isEnabledForUser')->with('metavox', $user)->willReturn(false);

        $this->assertFalse((new MetadataFormEmbed($apps, $users))->isAvailable('alice'));
    }

    public function testWithoutAUserItAsksForTheCurrentOne(): void {
        $apps = $this->createMock(IAppManager::class);
        $apps->expects($this->once())->method('isEnabledForUser')->with('metavox', null)->willReturn(true);

        $this->assertTrue((new MetadataFormEmbed($apps, $this->createMock(IUserManager::class)))->isAvailable());
    }
}
