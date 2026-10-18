<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Listener;

use OCA\MetaVox\Listener\ApplyDefaultsListener;
use OCA\MetaVox\Service\DefaultsDiscovery;
use OCA\MetaVox\Service\DefaultsRequestCounter;
use OCA\MetaVox\Service\DefaultsService;
use OCA\MetaVox\Service\GroupfolderResolver;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\Node;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** A file moved into a team folder keeps its old id, so it gets its defaults here. */
class ApplyDefaultsListenerTest extends TestCase {

    /** team folder per node */
    private array $teamFolderOf = [];
    /** @var int[] files that got defaults */
    private array $applied = [];
    /** @var int[] team folders a sweep was started for */
    private array $swept = [];
    private bool $counterLeft = true;

    public function testAFileMovedInGetsTheDefaults(): void {
        $this->move($this->file(10, null), $this->file(10, 2));

        $this->assertSame([10], $this->applied);
    }

    public function testAMoveWithinTheTeamFolderChangesNothing(): void {
        $this->move($this->file(10, 2), $this->file(10, 2));

        $this->assertSame([], $this->applied);
        $this->assertSame([], $this->swept);
    }

    public function testAFolderMovedInStartsASweep(): void {
        $this->move($this->folder(20, 3), $this->folder(20, 2));

        $this->assertSame([2], $this->swept);
        $this->assertSame([], $this->applied);
    }

    public function testManyFilesInOneRequestGoToTheSweep(): void {
        $this->counterLeft = false;

        $this->move($this->file(10, null), $this->file(10, 2));

        $this->assertSame([], $this->applied);
        $this->assertSame([2], $this->swept);
    }

    public function testOutOfATeamFolderNothingHappens(): void {
        $this->move($this->file(10, 2), $this->file(10, null));

        $this->assertSame([], $this->applied);
        $this->assertSame([], $this->swept);
    }

    private function move(Node $source, Node $target): void {
        $resolver = $this->createMock(GroupfolderResolver::class);
        $resolver->method('getGroupfolderId')->willReturnCallback(fn (Node $node) => $this->teamFolderOf[spl_object_id($node)] ?? null);
        $defaults = $this->createMock(DefaultsService::class);
        $defaults->method('getFolderDefaults')->willReturn(['afdeling' => 'Sales']);
        $defaults->method('bulkInsertDefaults')->willReturnCallback(function (int $gf, array $ids): int {
            array_push($this->applied, ...$ids);
            return \count($ids);
        });
        $discovery = $this->createMock(DefaultsDiscovery::class);
        $discovery->method('startSweep')->willReturnCallback(function (int $gf): void {
            $this->swept[] = $gf;
        });
        $counter = $this->createMock(DefaultsRequestCounter::class);
        $counter->method('tryConsume')->willReturnCallback(fn () => $this->counterLeft);

        $listener = new ApplyDefaultsListener($defaults, $resolver, $counter, $discovery, $this->createMock(LoggerInterface::class));
        $listener->handle(new NodeRenamedEvent($source, $target));
    }

    private function file(int $id, ?int $teamFolder): File {
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn($id);
        $file->method('getType')->willReturn(FileInfo::TYPE_FILE);
        $this->teamFolderOf[spl_object_id($file)] = $teamFolder;
        return $file;
    }

    private function folder(int $id, ?int $teamFolder): Folder {
        $folder = $this->createMock(Folder::class);
        $folder->method('getId')->willReturn($id);
        $folder->method('getType')->willReturn(FileInfo::TYPE_FOLDER);
        $this->teamFolderOf[spl_object_id($folder)] = $teamFolder;
        return $folder;
    }
}
