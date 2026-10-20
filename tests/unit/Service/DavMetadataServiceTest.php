<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Dav\MetadataPropertyPayload;
use OCA\MetaVox\Service\AccountService;
use OCA\MetaVox\Service\DavMetadataService;
use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\FileAccess;
use OCA\MetaVox\Service\GroupfolderResolver;
use OCA\MetaVox\Service\FileAccessService;
use OCA\MetaVox\Service\MetadataValueReader;
use OCA\MetaVox\Service\PresenceService;
use OCP\Files\Mount\IMountPoint;
use OCP\Files\Node;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DavMetadataServiceTest extends TestCase {

    private const TEAM_FOLDER = 12;

    private FileAccessService&MockObject $access;
    private GroupfolderResolver&MockObject $resolver;
    private MetadataValueReader&MockObject $reader;
    private PresenceService&MockObject $presence;

    /** @var array<int, ?FileAccess> what primeFromNode returns per file id */
    private array $verdicts = [];

    protected function setUp(): void {
        $this->access = $this->createMock(FileAccessService::class);
        $this->access->method('primeFromNode')->willReturnCallback(fn (string $uid, Node $node) => $this->verdicts[$node->getId()] ?? null);
        $this->access->expects($this->never())->method('getFileAccess');
        $this->resolver = $this->createMock(GroupfolderResolver::class);
        $this->resolver->method('getGroupfolderId')->willReturn(self::TEAM_FOLDER);
        $this->reader = $this->createMock(MetadataValueReader::class);
        $this->presence = $this->createMock(PresenceService::class);
    }

    private function service(): DavMetadataService {
        return new DavMetadataService($this->access, $this->resolver, $this->reader, $this->presence,
            $this->createMock(FieldService::class), $this->createMock(AccountService::class));
    }

    private function node(int $id, string $path, string $mountPoint = '/alice/files/HR/'): Node {
        $mount = $this->createMock(IMountPoint::class);
        $mount->method('getMountPoint')->willReturn($mountPoint);
        $node = $this->createMock(Node::class);
        $node->method('getId')->willReturn($id);
        $node->method('getPath')->willReturn($path);
        $node->method('getMountPoint')->willReturn($mount);
        return $node;
    }

    public function testMixedListing(): void {
        $folder = $this->node(100, '/alice/files/HR/Projects');
        $children = [
            $this->node(101, '/alice/files/HR/Projects/rw.pdf'),
            $this->node(102, '/alice/files/HR/Projects/ro.pdf'),
            $this->node(103, '/alice/files/HR/Projects/Nested', '/alice/files/HR/Projects/Nested/'),
            $this->node(104, '/alice/files/HR/Projects/refused.pdf'),
            $this->node(105, '/alice/files/HR/Projects/elsewhere.pdf'),
        ];
        $this->verdicts = [
            100 => new FileAccess(self::TEAM_FOLDER, true),
            101 => new FileAccess(self::TEAM_FOLDER, true),
            102 => new FileAccess(self::TEAM_FOLDER, false),
            105 => new FileAccess(99, true),
        ];
        $this->reader->expects($this->once())->method('readValues')
            ->with(self::TEAM_FOLDER, [100, 101, 102], MetadataPropertyPayload::MAX_CHARS + 1)
            ->willReturn([101 => ['status' => 'Draft'], 102 => ['status' => 'Final']]);

        $properties = $this->service()->buildForListing('alice', $folder, $children);

        $this->assertSame([
            103 => '{"v":1}',
            100 => '{"v":1,"g":12,"e":1}',
            101 => '{"v":1,"g":12,"e":1,"f":{"status":"Draft"}}',
            102 => '{"v":1,"g":12,"f":{"status":"Final"}}',
        ], $properties);
    }

    public function testTeamFolderRootOnlyGetsItsId(): void {
        // primeFromNode gives no verdict for mount roots.
        $folder = $this->node(100, '/alice/files/HR', '/alice/files/HR/');
        $this->verdicts = [101 => new FileAccess(self::TEAM_FOLDER, true)];
        $this->reader->method('readValues')->willReturn([]);

        $properties = $this->service()->buildForListing('alice', $folder, [$this->node(101, '/alice/files/HR/a.pdf')]);

        $this->assertSame('{"v":1,"g":12}', $properties[100]);
        $this->assertSame('{"v":1,"g":12,"e":1}', $properties[101]);
    }

    public function testListingATeamFolderMarksItsGridOpen(): void {
        $this->presence->expects($this->once())->method('register')->with(self::TEAM_FOLDER, 'alice');

        $this->service()->buildForListing('alice', $this->node(100, '/alice/files/HR', '/alice/files/HR/'), []);
    }

    public function testNothingOutsideTheUsersFiles(): void {
        $this->reader->expects($this->never())->method('readValues');
        $this->presence->expects($this->never())->method('register');

        $this->assertSame([], $this->service()->buildForListing('alice', $this->node(100, '/alice/files_trashbin/files/HR'), []));
        $this->assertSame([], $this->service()->buildForListing('alice', $this->node(100, '/bob/files/HR'), []));
    }

    public function testNothingOutsideATeamFolder(): void {
        $this->resolver = $this->createMock(GroupfolderResolver::class);
        $this->resolver->method('getGroupfolderId')->willReturn(null);
        $this->reader->expects($this->never())->method('readValues');
        $this->presence->expects($this->never())->method('register');

        $this->assertSame([], $this->service()->buildForListing('alice', $this->node(100, '/alice/files/Personal'), []));
    }

    public function testNoQueryWithoutReadableNodes(): void {
        $this->reader->expects($this->never())->method('readValues');

        $properties = $this->service()->buildForListing('alice', $this->node(100, '/alice/files/HR', '/alice/files/HR/'), []);

        $this->assertSame([100 => '{"v":1,"g":12}'], $properties);
    }

    public function testBigListingsAreReadAndEncodedPerChunk(): void {
        $mount = $this->createMock(IMountPoint::class);
        $mount->method('getMountPoint')->willReturn('/alice/files/HR/');
        $children = [];
        foreach (range(1, 1500) as $id) {
            $node = $this->createMock(Node::class);
            $node->method('getId')->willReturn($id);
            $node->method('getPath')->willReturn("/alice/files/HR/Big/$id.txt");
            $node->method('getMountPoint')->willReturn($mount);
            $children[] = $node;
            $this->verdicts[$id] = new FileAccess(self::TEAM_FOLDER, false);
        }
        $this->verdicts[100000] = new FileAccess(self::TEAM_FOLDER, false);
        $calls = [];
        $this->reader->method('readValues')->willReturnCallback(function (int $gf, array $ids) use (&$calls) {
            $calls[] = $ids;
            return array_combine($ids, array_map(fn ($id) => ['n' => (string)$id], $ids));
        });

        $properties = $this->service()->buildForListing('alice', $this->node(100000, '/alice/files/HR/Big'), $children);

        $this->assertSame([1000, 501], array_map('count', $calls));
        $this->assertCount(1501, $properties);
        $this->assertSame('{"v":1,"g":12,"f":{"n":"1234"}}', $properties[1234]);
        $this->assertSame('{"v":1,"g":12,"f":{"n":"100000"}}', $properties[100000]);
    }

    public function testAFailingLaterChunkServesNothing(): void {
        $mount = $this->createMock(IMountPoint::class);
        $mount->method('getMountPoint')->willReturn('/alice/files/HR/');
        $children = [];
        foreach (range(1, 1200) as $id) {
            $node = $this->createMock(Node::class);
            $node->method('getId')->willReturn($id);
            $node->method('getPath')->willReturn("/alice/files/HR/Big/$id.txt");
            $node->method('getMountPoint')->willReturn($mount);
            $children[] = $node;
            $this->verdicts[$id] = new FileAccess(self::TEAM_FOLDER, true);
        }
        $this->reader->method('readValues')->willReturnOnConsecutiveCalls([], $this->throwException(new \RuntimeException('connection lost')));

        $this->expectException(\RuntimeException::class);
        $this->service()->buildForListing('alice', $this->node(100000, '/alice/files/HR/Big', '/alice/files/HR/Big/'), $children);
    }

    public function testReaderErrorsAreThrown(): void {
        $this->verdicts = [100 => new FileAccess(self::TEAM_FOLDER, true)];
        $this->reader->method('readValues')->willThrowException(new \RuntimeException('connection lost'));

        $this->expectException(\RuntimeException::class);
        $this->service()->buildForListing('alice', $this->node(100, '/alice/files/HR/Projects'), []);
    }
}
