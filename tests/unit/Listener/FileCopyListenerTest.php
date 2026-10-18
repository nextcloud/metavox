<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Listener;

use OCA\MetaVox\Listener\FileCopyListener;
use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\GroupfolderResolver;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** A copy gets the original's values, read and saved per block. */
class FileCopyListenerTest extends TestCase {

    /** team folder per node id */
    private array $folderOf = [];
    /** stored values: [file id, field name, value] */
    private array $stored = [];
    /** @var list<array> what was cleared and saved */
    private array $cleared = [];
    private array $saved = [];
    private int $reads = 0;

    public function testAFolderCopyMatchesFilesOnTheirPath(): void {
        $source = $this->folder(1, 'Project', [
            $this->file(2, 'a.txt'),
            $this->folder(3, 'Sub', [$this->file(4, 'b.txt'), $this->file(5, 'leeg.txt')]),
        ]);
        $target = $this->folder(11, 'Project (copy)', [
            $this->file(12, 'a.txt'),
            $this->folder(13, 'Sub', [$this->file(14, 'b.txt'), $this->file(15, 'leeg.txt')]),
        ]);
        $this->folderOf = [1 => 7, 2 => 7, 3 => 7, 4 => 7, 5 => 7, 11 => 7, 12 => 7, 13 => 7, 14 => 7, 15 => 7];
        $this->stored = [[1, 'klant', 'Map'], [2, 'klant', 'A'], [2, 'status', 'Open'], [4, 'klant', 'B']];

        $this->copy($source, $target);

        $this->assertSame(1, $this->reads);
        $this->assertSame([[7, [11, 12, 14]]], $this->cleared);
        $this->assertSame([[7, [11 => ['klant' => 'Map'], 12 => ['klant' => 'A', 'status' => 'Open'], 14 => ['klant' => 'B']]]], $this->saved);
    }

    public function testAFileCopyIsTheSameWithOneFile(): void {
        $this->folderOf = [2 => 7, 12 => 7];
        $this->stored = [[2, 'klant', 'A']];

        $this->copy($this->file(2, 'a.txt'), $this->file(12, 'a (copy).txt'));

        $this->assertSame([[7, [12 => ['klant' => 'A']]]], $this->saved);
    }

    public function testNothingGoesToAnotherTeamFolder(): void {
        $this->folderOf = [2 => 7, 12 => 8];
        $this->stored = [[2, 'klant', 'A']];

        $this->copy($this->file(2, 'a.txt'), $this->file(12, 'a.txt'));

        $this->assertSame(0, $this->reads);
        $this->assertSame([], $this->saved);
    }

    public function testATeamFolderInsideTheCopyKeepsItsOwnValues(): void {
        // Sub is another team folder mounted inside; its files stay out.
        $source = $this->folder(1, 'Project', [$this->file(2, 'a.txt'), $this->folder(3, 'Sub', [$this->file(4, 'b.txt')])]);
        $target = $this->folder(11, 'Copy', [$this->file(12, 'a.txt'), $this->folder(13, 'Sub', [$this->file(14, 'b.txt')])]);
        $this->folderOf = [1 => 7, 2 => 7, 3 => 9, 4 => 9, 11 => 7, 12 => 7, 13 => 7, 14 => 7];
        $this->stored = [[2, 'klant', 'A'], [4, 'klant', 'B']];

        $this->copy($source, $target);

        $this->assertSame([[7, [12 => ['klant' => 'A']]]], $this->saved);
    }

    public function testWithoutValuesNothingIsCleared(): void {
        $this->folderOf = [2 => 7, 12 => 7];

        $this->copy($this->file(2, 'a.txt'), $this->file(12, 'a.txt'));

        $this->assertSame([], $this->cleared);
        $this->assertSame([], $this->saved);
    }

    private function copy(Node $source, Node $target): void {
        $resolver = $this->createMock(GroupfolderResolver::class);
        $resolver->method('getGroupfolderId')->willReturnCallback(fn (Node $node) => $this->folderOf[$node->getId()] ?? null);

        $fields = $this->createMock(FieldService::class);
        $fields->method('clearFilesMetadata')->willReturnCallback(function (int $gf, array $ids): void {
            $this->cleared[] = [$gf, $ids];
        });
        $fields->method('saveValues')->willReturnCallback(function (int $gf, array $values): int {
            $this->saved[] = [$gf, $values];
            return 0;
        });

        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturnCallback(function () {
            $asked = [];
            $qb = $this->createMock(IQueryBuilder::class);
            foreach (['select', 'from', 'where', 'andWhere'] as $method) {
                $qb->method($method)->willReturnSelf();
            }
            $qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
            $qb->method('createNamedParameter')->willReturnCallback(function ($value) use (&$asked) {
                if (\is_array($value)) {
                    $asked = $value;
                }
                return ':p';
            });
            $qb->method('executeQuery')->willReturnCallback(function () use (&$asked) {
                $this->reads++;
                $rows = [];
                foreach ($this->stored as [$fileId, $field, $value]) {
                    if (\in_array($fileId, $asked, true)) {
                        $rows[] = ['file_id' => $fileId, 'field_name' => $field, 'field_value' => $value];
                    }
                }
                $result = $this->createMock(IResult::class);
                $result->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows, false]);
                return $result;
            });
            return $qb;
        });

        $listener = new FileCopyListener($fields, $db, $resolver, $this->createMock(LoggerInterface::class));
        $listener->handle(new NodeCopiedEvent($source, $target));
    }

    private function file(int $id, string $name): File {
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn($id);
        $file->method('getName')->willReturn($name);
        return $file;
    }

    /** @param Node[] $children */
    private function folder(int $id, string $name, array $children): Folder {
        $folder = $this->createMock(Folder::class);
        $folder->method('getId')->willReturn($id);
        $folder->method('getName')->willReturn($name);
        $folder->method('getDirectoryListing')->willReturn($children);
        return $folder;
    }
}
