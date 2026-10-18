<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Dav;

require_once __DIR__ . '/../../stubs/Dav.php';

use OCA\DAV\Connector\Sabre\Directory;
use OCA\DAV\Connector\Sabre\File as DavFile;
use OCA\MetaVox\Dav\CData;
use OCA\MetaVox\Dav\MetadataPropertyPlugin;
use OCA\MetaVox\Service\DavMetadataService;
use OCA\MetaVox\Service\GroupfolderResolver;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sabre\DAV\PropFind;
use Sabre\DAV\Server;
use Sabre\DAV\SimpleFile;

class MetadataPropertyPluginTest extends TestCase {

    private const PROPERTY = MetadataPropertyPlugin::PROPERTY;
    private const PATH = 'files/alice/HR';

    private ?IUser $user;
    private bool $appEnabled = true;
    private ?int $groupfolderId = 12;
    private int $factoryCalls = 0;

    private DavMetadataService&MockObject $service;
    private LoggerInterface&MockObject $logger;
    private Folder&MockObject $folder;
    private File&MockObject $file;

    protected function setUp(): void {
        $this->user = $this->createMock(IUser::class);
        $this->user->method('getUID')->willReturn('alice');
        $this->service = $this->createMock(DavMetadataService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->folder = $this->createMock(Folder::class);
        $this->file = $this->createMock(File::class);
    }

    private function plugin(): MetadataPropertyPlugin {
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturnCallback(fn () => $this->user);
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->willReturnCallback(fn (string $app) => $app === 'metavox' && $this->appEnabled);
        $resolver = $this->createMock(GroupfolderResolver::class);
        $resolver->method('getGroupfolderId')->willReturnCallback(fn () => $this->groupfolderId);

        return new MetadataPropertyPlugin($session, $apps, $resolver, function () {
            $this->factoryCalls++;
            return $this->service;
        }, $this->logger);
    }

    private function directory(?\Throwable $listingError = null): Directory {
        $child = $this->createMock(DavFile::class);
        $child->method('getId')->willReturn(101);
        $child->method('getNode')->willReturn($this->file);
        $directory = $this->createMock(Directory::class);
        $directory->method('getId')->willReturn(100);
        $directory->method('getNode')->willReturn($this->folder);
        if ($listingError !== null) {
            $directory->method('getChildren')->willThrowException($listingError);
        } else {
            $directory->method('getChildren')->willReturn([$child, new SimpleFile('plain.txt', '')]);
        }
        return $directory;
    }

    private function childNode(): DavFile {
        $child = $this->createMock(DavFile::class);
        $child->method('getId')->willReturn(101);
        return $child;
    }

    private function givesValues(): void {
        $this->service->method('buildForListing')->willReturn([100 => '{"v":1,"g":12}', 101 => '{"v":1,"g":12,"e":1}']);
    }

    /** Runs a Depth-1 listing the way Sabre does: the folder first, then each child at depth 0. */
    private function list(MetadataPropertyPlugin $plugin, Directory $directory, bool $preloadEvent, array $props = [self::PROPERTY]): array {
        $root = new PropFind(self::PATH, $props, 1);
        if ($preloadEvent) {
            $plugin->preloadCollection($root, $directory);
        }
        $plugin->propFind($root, $directory);
        $child = new PropFind(self::PATH . '/a.pdf', $props, 0);
        $plugin->propFind($child, $this->childNode());
        return [self::text($root->get(self::PROPERTY)), self::text($child->get(self::PROPERTY))];
    }

    private static function text(mixed $value): ?string {
        if ($value === null) {
            return null;
        }
        self::assertInstanceOf(CData::class, $value);
        return $value->text;
    }

    public function testRegistersItsEvents(): void {
        $server = new Server();

        $this->plugin()->initialize($server);

        $this->assertContains(self::PROPERTY, $server->protectedProperties);
        $this->assertCount(1, $server->listeners('preloadCollection'));
        $this->assertNotEmpty($server->listeners('propFind'));
        $this->assertSame([], $server->listeners('preloadProperties'));
    }

    public function testServesAListingViaPreloadCollection(): void {
        $this->givesValues();
        $this->service->expects($this->once())->method('buildForListing')->with('alice', $this->folder, [$this->file]);

        $this->assertSame(['{"v":1,"g":12}', '{"v":1,"g":12,"e":1}'], $this->list($this->plugin(), $this->directory(), true));
    }

    public function testServesAListingWithoutPreloadCollection(): void {
        // NC 31 has no preloadCollection event.
        $this->givesValues();

        $this->assertSame(['{"v":1,"g":12}', '{"v":1,"g":12,"e":1}'], $this->list($this->plugin(), $this->directory(), false));
    }

    public function testPreloadsOncePerFolder(): void {
        $this->givesValues();
        $this->service->expects($this->once())->method('buildForListing');
        $plugin = $this->plugin();
        $directory = $this->directory();
        $root = new PropFind(self::PATH, [self::PROPERTY], 1);

        $plugin->preloadCollection($root, $directory);
        $plugin->preloadCollection($root, $directory);
        $plugin->propFind($root, $directory);

        $this->assertSame(1, $this->factoryCalls);
    }

    public function testNothingWhenNotRequested(): void {
        $this->service->expects($this->never())->method('buildForListing');

        $this->assertSame([null, null], $this->list($this->plugin(), $this->directory(), true, ['{DAV:}getetag']));
        $this->assertSame(0, $this->factoryCalls);
    }

    public function testNothingForDepthZeroOrInfinity(): void {
        $this->service->expects($this->never())->method('buildForListing');
        $plugin = $this->plugin();

        foreach ([0, Server::DEPTH_INFINITY] as $depth) {
            $propFind = new PropFind(self::PATH, [self::PROPERTY], $depth);
            $plugin->preloadCollection($propFind, $this->directory());
            $plugin->propFind($propFind, $this->directory());
            $this->assertSame(404, $propFind->getStatus(self::PROPERTY));
        }
    }

    public function testNothingWithoutAUser(): void {
        $this->user = null;

        $this->assertSame([null, null], $this->list($this->plugin(), $this->directory(), true));
        $this->assertSame(0, $this->factoryCalls);
    }

    public function testNothingWhenTheAppIsDisabledForTheUser(): void {
        $this->appEnabled = false;

        $this->assertSame([null, null], $this->list($this->plugin(), $this->directory(), true));
        $this->assertSame(0, $this->factoryCalls);
    }

    public function testNothingOutsideATeamFolder(): void {
        $this->groupfolderId = null;

        $this->assertSame([null, null], $this->list($this->plugin(), $this->directory(), true));
        $this->assertSame(0, $this->factoryCalls);
    }

    public function testRefusedListingIsSkippedQuietly(): void {
        $this->logger->expects($this->once())->method('debug')->with('MetaVox: PROPFIND preload skipped');
        $this->logger->expects($this->never())->method('warning');

        $this->assertSame([null, null], $this->list($this->plugin(), $this->directory(new NotFoundException()), true));
    }

    public function testOtherErrorsServeNothing(): void {
        $this->service->method('buildForListing')->willThrowException(new \RuntimeException('connection lost'));
        $this->logger->expects($this->once())->method('warning');

        $this->assertSame([null, null], $this->list($this->plugin(), $this->directory(), true));
    }

    public function testIgnoresNodesOutsideTheFilesTree(): void {
        $propFind = new PropFind('trashbin/alice/trash/a.pdf', [self::PROPERTY], 1);

        $this->plugin()->propFind($propFind, new SimpleFile('a.pdf', ''));

        $this->assertSame(404, $propFind->getStatus(self::PROPERTY));
        $this->assertSame(0, $this->factoryCalls);
    }
}
