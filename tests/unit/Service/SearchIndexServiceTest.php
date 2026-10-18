<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Access\ReadableScope;
use OCA\MetaVox\Access\VisibleFolder;
use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\GroupfolderRootService;
use OCA\MetaVox\Service\SearchIndexService;
use OCA\MetaVox\Service\SearchWordsService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\Files\IMimeTypeLoader;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The query's shape per field type and the pages; the SQL itself runs against
 * real databases in the access matrix (tests/integration/access).
 */
class SearchIndexServiceTest extends TestCase {

    /** @var list<array{0: string, 1: mixed}> expression calls: method and column */
    private array $expr = [];
    /** @var array<string, list<array<string, mixed>>> rows per table read */
    private array $rows = [];
    /** @var list<string> tables read, in order */
    private array $read = [];
    /** @var list<array{0: ?int, 1: ?int}> offset and limit of each query */
    private array $pages = [];
    private ?SearchWordsService $words = null;
    /** @var array<string, string> field types by name */
    private array $types = ['status' => 'select', 'tags' => 'multiselect'];
    /** @var list<array{storage: int, path: string}> the team folder's roots */
    private array $roots = [['storage' => 12, 'path' => 'files']];
    /** Whether the index holds every value, and the field definition the filter finds */
    private bool $complete = false;
    private ?array $field = null;
    /** @var list<array<string, mixed>> what the index statement returns */
    private array $indexRows = [];
    /** @var list<array{0: string, 1: array}> index statements run, with their parameters */
    private array $statements = [];
    private string $provider = IDBConnection::PLATFORM_MYSQL;
    /** @var string[] the tables joined, in turn */
    private array $joins = [];
    /** @var array<int, array<int, true>> field id => the team folders it is on */
    private array $fieldFolders = [];
    /** @var int[] the fields whose team folders were asked */
    private array $fieldFolderReads = [];
    /** @var string[] SQL functions the query was built with */
    private array $functions = [];

    public function testASingleValueFieldIsFoundByEqualityAlone(): void {
        $this->service()->searchByFieldValue('status', 'Concept;#Review', [$this->folder()], 10);

        $this->assertContains(['in', 'm.field_value'], $this->expr);
        $this->assertNotContains(['like', 'concat'], $this->expr);
        $this->assertNotContains(['in', 'func'], $this->expr);
    }

    /** PostgreSQL's filter index holds the start of each value (Version20250101000023): asked like that, it is used. */
    public function testOnPostgresTheStartOfTheValueIsComparedToo(): void {
        $this->provider = IDBConnection::PLATFORM_POSTGRES;

        $this->service()->searchByFieldValue('status', 'Concept', [$this->folder()], 10);

        $this->assertSame(['LEFT(m.field_value, ' . SearchIndexService::VALUE_INDEX_CHARS . ')'], $this->functions);
        $this->assertContains(['in', 'func'], $this->expr);
        $this->assertContains(['in', 'm.field_value'], $this->expr);
    }

    public function testAListFieldAlsoMatchesEachItem(): void {
        $this->service()->searchByFieldValue('tags', 'A;#C', [$this->folder()], 10);

        $this->assertSame(2, count(array_keys($this->expr, ['like', 'concat'], true)));
        $this->assertNotContains(['in', 'm.field_value'], $this->expr);
    }

    public function testAnUnknownFieldCountsAsAList(): void {
        $this->service()->searchByFieldValue('gone', 'A', [$this->folder()], 10);

        $this->assertContains(['like', 'concat'], $this->expr);
    }

    /** As in Nextcloud's own search: no index on the file table may decide the plan. */
    public function testTheFileTableNeverLeads(): void {
        $this->service()->searchByFieldValue('status', 'Concept', [$this->folder()], 10, 500);

        $this->assertContains(['add', 'm.file_id'], $this->expr);
        $this->assertContains(['lt', 'func'], $this->expr);
        $this->assertContains(['add', 'f.storage'], $this->expr);
        $this->assertNotContains(['eq', 'f.storage'], $this->expr);
    }

    public function testOneRowMoreSaysWhetherAnythingFollows(): void {
        $this->rows['metavox_file_gf_meta'] = $this->metaRows(4);

        $more = $this->service()->searchByFieldValue('status', 'Concept', [$this->folder()], 3);
        $this->rows['metavox_file_gf_meta'] = $this->metaRows(3);
        $end = $this->service()->searchByFieldValue('status', 'Concept', [$this->folder()], 3);

        $this->assertSame([4, 4], array_column($this->pages, 1));
        $this->assertSame([4, 3, 2], array_column($more['files'], 'id'));
        $this->assertSame(2, $more['next']);
        $this->assertCount(3, $end['files']);
        $this->assertNull($end['next']);
    }

    public function testAResultIsShownWhereTheUserSeesIt(): void {
        $this->rows['metavox_file_gf_meta'] = [['fileid' => 10, 'name' => 'a.pdf', 'path' => 'files/Docs/a.pdf', 'storage' => 12, 'mimetype' => 4, 'groupfolder_id' => 5, 'field_value' => 'Concept']];

        $file = $this->service()->searchByFieldValue('status', 'Concept', [$this->folder()], 10)['files'][0];

        $this->assertSame(['id' => 10, 'name' => 'a.pdf', 'path' => '/HR/Docs/a.pdf', 'mimetype' => 'application/pdf', 'groupfolder_id' => 5,
            'metadata' => ['status' => 'Concept'], 'checked' => true], $file);
    }

    public function testWhereGroupfoldersCantTellTheCallerChecks(): void {
        $this->rows['metavox_file_gf_meta'] = $this->metaRows(1);

        $file = $this->service()->searchByFieldValue('status', 'Concept', [new VisibleFolder(5, 'HR', null)], 10)['files'][0];

        $this->assertFalse($file['checked']);
    }

    public function testNoTeamFoldersAsksNothing(): void {
        $page = $this->service()->searchByFieldValue('status', 'Concept', [], 10);

        $this->assertSame(['files' => [], 'next' => null], $page);
        $this->assertSame([], $this->read);
    }

    public function testFreeTextGoesOnBeforeAFile(): void {
        // Matches 100..71, newest first; 98 and 97 are not seen by the user.
        $this->words = $this->createMock(SearchWordsService::class);
        $this->words->expects($this->once())->method('search')->willReturn(['hits' => array_map(fn (int $id) => ['file_id' => $id, 'groupfolder_id' => 5], range(100, 71)), 'cut' => false]);
        $this->rows['filecache'] = array_map(fn (int $id) => ['fileid' => $id, 'name' => "f$id", 'path' => "files/f$id", 'storage' => 12, 'mimetype' => 4],
            array_diff(range(100, 71), [98, 97]));
        $service = $this->service();

        $first = $service->searchFreeText('klant', [$this->folder()], 3);
        $second = $service->searchFreeText('klant', [$this->folder()], 3, $first['next']);
        $end = $service->searchFreeText('klant', [$this->folder()], 50, 73);

        $this->assertSame([100, 99, 96], array_column($first['files'], 'id'));
        $this->assertSame(96, $first['next']);
        $this->assertSame([95, 94, 93], array_column($second['files'], 'id'));
        $this->assertSame([72, 71], array_column($end['files'], 'id'));
        $this->assertNull($end['next']);
    }

    public function testFreeTextCountsOnlyFieldsTheTeamFolderHas(): void {
        $this->fieldFolders = [1 => [5 => true], 2 => [6 => true]];
        $asked = null;
        $this->words = $this->createMock(SearchWordsService::class);
        $this->words->expects($this->once())->method('search')
            ->willReturnCallback(function (string $term, array $ids, int $limit, callable $assigned) use (&$asked) {
                $asked = [$assigned(5, 1), $assigned(5, 2), $assigned(6, 2), $assigned(5, 1)];
                return ['hits' => [], 'cut' => false];
            });

        $this->service()->searchFreeText('klant', [$this->folder()], 10);

        $this->assertSame([true, false, true, true], $asked);
        // Once per field, from FieldService's cache.
        $this->assertSame([1, 2], $this->fieldFolderReads);
    }

    public function testTheSublineShowsOnlyFieldsTheTeamFolderHas(): void {
        $this->words = $this->createMock(SearchWordsService::class);
        $this->words->method('search')->willReturn(['hits' => [['file_id' => 100, 'groupfolder_id' => 5]], 'cut' => false]);
        $this->rows['filecache'] = [['fileid' => 100, 'name' => 'f100', 'path' => 'files/f100', 'storage' => 12, 'mimetype' => 4]];

        $found = $this->service()->searchFreeText('klant', [$this->folder()], 10);

        $this->assertSame([100], array_column($found['files'], 'id'));
        $this->assertContains('metavox_gf_assigns', $this->joins);
        $this->assertContains(['eq', 'a.field_id'], $this->expr);
        $this->assertContains(['eq', 'a.groupfolder_id'], $this->expr);
    }

    public function testTooCommonWordsAreSaidOnlyWhereNothingIsHidden(): void {
        $this->words = $this->createMock(SearchWordsService::class);
        $this->words->method('search')->willReturn(['hits' => [], 'cut' => true]);
        $service = $this->service();

        $this->assertTrue($service->searchFreeText('dossier 100', [$this->folder()], 10)['incomplete']);
        // Where ACL hides files, it would tell that hidden files hold the words.
        $acl = new VisibleFolder(7, 'Legal', ReadableScope::exceptPaths([['storage' => 12, 'path' => 'files/geheim']]));
        $this->assertFalse($service->searchFreeText('dossier 100', [$this->folder(), $acl], 10)['incomplete']);
        $this->assertFalse($service->searchFreeText('dossier 100', [new VisibleFolder(8, 'Oud', null)], 10)['incomplete']);
    }

    public function testWordsReadWholeAreNotIncomplete(): void {
        $this->words = $this->createMock(SearchWordsService::class);
        $this->words->method('search')->willReturn(['hits' => [], 'cut' => false]);

        $this->assertFalse($this->service()->searchFreeText('klant', [$this->folder()], 10)['incomplete']);
    }

    /** groupfolders before 19.1.10 doesn't record the root: each file is looked up, as before. */
    public function testWithoutARecordedRootEachFileIsLookedUp(): void {
        $this->roots = [];
        $this->rows['metavox_file_gf_meta'] = $this->metaRows(1);

        $file = $this->service()->searchByFieldValue('status', 'Concept', [$this->folder()], 10)['files'][0];

        $this->assertFalse($file['checked']);
        $this->assertNotContains(['like', 'f.path'], $this->expr);
    }

    /** Every part walks the index newest first and stops one past the page. */
    public function testTheFilterReadsTheNewestFilesOfEachTeamFolderFromTheIndex(): void {
        $this->useIndex('select');
        $this->indexRows = [$this->indexRow(30, 5), $this->indexRow(20, 8), $this->indexRow(25, 5), $this->indexRow(10, 8)];
        $this->rows['metavox_file_gf_meta'] = array_map(fn (int $id) => ['file_id' => $id, 'groupfolder_id' => $id % 10 === 0 && $id !== 30 ? 8 : 5, 'field_value' => 'Concept'], [30, 25, 20]);

        $page = $this->service()->searchByFieldValue('status', 'Concept', [$this->folder(), new VisibleFolder(8, 'IT', ReadableScope::everything())], 3);

        [[$sql, $params]] = $this->statements;
        $this->assertSame(1, substr_count($sql, ' UNION ALL '));
        $this->assertSame(2, substr_count($sql, 'ORDER BY w.file_id DESC LIMIT 4'));
        $this->assertContains(SearchWordsService::valueKey(7, 'Concept'), $params);
        $this->assertContains(SearchWordsService::VALUE_MARK, $params);
        $this->assertSame([30, 25, 20], array_column($page['files'], 'id'));
        $this->assertSame(['status' => 'Concept'], $page['files'][0]['metadata']);
        $this->assertSame(20, $page['next']);
    }

    public function testEveryPartGoesOnBeforeTheLastFileShown(): void {
        $this->useIndex('select');

        $this->service()->searchByFieldValue('status', 'Concept', [$this->folder(), new VisibleFolder(8, 'IT', ReadableScope::everything())], 10, 500);

        $this->assertSame(2, substr_count($this->statements[0][0], 'w.file_id < '));
        $this->assertContains(500, $this->statements[0][1]);
    }

    /** A file with both asked items comes from both parts: once on the page (review 09-10). */
    public function testAFileWithTwoAskedItemsIsShownOnce(): void {
        $this->useIndex('multiselect');
        $this->indexRows = [$this->indexRow(30, 5), $this->indexRow(30, 5), $this->indexRow(29, 5)];
        $this->rows['metavox_file_gf_meta'] = [['file_id' => 30, 'groupfolder_id' => 5, 'field_value' => 'A;#C'], ['file_id' => 29, 'groupfolder_id' => 5, 'field_value' => 'C']];

        $page = $this->service()->searchByFieldValue('status', 'A;#C', [$this->folder()], 2);

        $this->assertSame([30, 29], array_column($page['files'], 'id'));
        $this->assertNull($page['next']);
    }

    /**
     * A team folder with two roots gives an OR, which Nextcloud writes without
     * outer brackets: it must stay inside its part (review 09-10).
     */
    public function testTwoRootsStayInsideTheirPart(): void {
        $this->useIndex('select');
        $this->roots = [['storage' => 12, 'path' => 'files'], ['storage' => 13, 'path' => 'files']];

        $this->service()->searchByFieldValue('status', 'Concept', [$this->folder()], 10);

        $this->assertStringContainsString(' AND (((eq) AND (like)) OR ((eq) AND (like))) ORDER BY', $this->statements[0][0]);
    }

    public function testManyTeamFoldersAreSpreadOverStatements(): void {
        $this->useIndex('select');
        $folders = array_map(fn (int $id) => new VisibleFolder($id, "M$id", ReadableScope::everything()), range(1, 1000));

        $this->service()->searchByFieldValue('status', 'Concept', $folders, 10);

        $this->assertCount(2, $this->statements);
        $this->assertSame(1000, array_sum(array_map(fn (array $s) => substr_count($s[0], 'ORDER BY w.file_id DESC'), $this->statements)));
    }

    /** SQLite is for testing (SqliteCheck): the plain query, which needs no long statement. */
    public function testSqliteKeepsThePlainQuery(): void {
        $this->useIndex('select');
        $this->provider = IDBConnection::PLATFORM_SQLITE;
        $this->rows['metavox_file_gf_meta'] = $this->metaRows(1);

        $page = $this->service()->searchByFieldValue('status', 'Concept', [$this->folder()], 10);

        $this->assertSame([], $this->statements);
        $this->assertSame([1], array_column($page['files'], 'id'));
    }

    public function testAListFieldLooksUpEachItemAsked(): void {
        $this->useIndex('multiselect');

        $this->service()->searchByFieldValue('status', 'A;#C', [$this->folder()], 10);

        $this->assertSame(1, substr_count($this->statements[0][0], ' UNION ALL '));
        $this->assertContains(SearchWordsService::valueKey(7, 'A'), $this->statements[0][1]);
        $this->assertContains(SearchWordsService::valueKey(7, 'C'), $this->statements[0][1]);
    }

    /** The index says where to look; the stored value decides. */
    public function testAFileWhoseValueChangedSinceIsLeftOut(): void {
        $this->useIndex('select');
        $this->indexRows = [$this->indexRow(30, 5)];
        $this->rows['metavox_file_gf_meta'] = [['file_id' => 30, 'groupfolder_id' => 5, 'field_value' => 'Review']];

        $this->assertSame([], $this->service()->searchByFieldValue('status', 'Concept', [$this->folder()], 10)['files']);
    }

    public function testUntilTheIndexIsCompleteTheMetadataItselfIsRead(): void {
        $this->useIndex('select');
        $this->complete = false;
        $this->rows['metavox_file_gf_meta'] = $this->metaRows(1);

        $page = $this->service()->searchByFieldValue('status', 'Concept', [$this->folder()], 10);

        $this->assertSame([], $this->statements);
        $this->assertSame([1], array_column($page['files'], 'id'));
    }

    public function testFileLinksAreNotInTheIndex(): void {
        $this->useIndex('filelink');
        $this->rows['metavox_file_gf_meta'] = $this->metaRows(1);

        $page = $this->service()->searchByFieldValue('status', '12:', [$this->folder()], 10);

        $this->assertSame([], $this->statements);
        $this->assertSame([1], array_column($page['files'], 'id'));
    }

    private function useIndex(string $type): void {
        $this->complete = true;
        $this->field = ['id' => 7, 'field_name' => 'status', 'field_type' => $type];
    }

    /** @return array<string, mixed> */
    private function indexRow(int $id, int $groupfolderId): array {
        return ['fileid' => $id, 'groupfolder_id' => $groupfolderId, 'name' => "f$id", 'path' => "files/f$id", 'storage' => 12, 'mimetype' => 4];
    }

    private function folder(): VisibleFolder {
        return new VisibleFolder(5, 'HR', ReadableScope::everything());
    }

    /** @return list<array<string, mixed>> */
    private function metaRows(int $n): array {
        return array_map(fn (int $i) => ['fileid' => $i, 'name' => "f$i", 'path' => "files/f$i", 'storage' => 12, 'mimetype' => 4, 'groupfolder_id' => 5, 'field_value' => 'Concept'], range($n, 1));
    }

    private function service(): SearchIndexService {
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturnCallback(fn () => $this->queryBuilder());
        $db->method('escapeLikeParameter')->willReturnArgument(0);
        $db->method('getDatabaseProvider')->willReturnCallback(fn () => $this->provider);
        $db->method('executeQuery')->willReturnCallback(function (string $sql, array $params) {
            $this->statements[] = [$sql, array_values($params)];
            $result = $this->createMock(IResult::class);
            $result->method('fetchAll')->willReturn($this->indexRows);
            return $result;
        });

        $roots = $this->createMock(GroupfolderRootService::class);
        $roots->method('getRoots')->willReturnCallback(fn () => $this->roots);
        $roots->method('pathInFolder')->willReturnCallback(fn (int $id, int $storage, string $path) => $storage === 12 && str_starts_with($path, 'files/') ? substr($path, 6) : null);

        $mime = $this->createMock(IMimeTypeLoader::class);
        $mime->method('getMimetypeById')->willReturn('application/pdf');

        $fields = $this->createMock(FieldService::class);
        $fields->method('fieldType')->willReturnCallback(fn (string $name) => $this->types[$name] ?? null);
        $fields->method('fieldByName')->willReturnCallback(fn () => $this->field);
        $fields->method('foldersOfField')->willReturnCallback(function (int $fieldId): array {
            $this->fieldFolderReads[] = $fieldId;
            return $this->fieldFolders[$fieldId] ?? [];
        });
        $words = $this->words ?? $this->createMock(SearchWordsService::class);
        $words->method('isComplete')->willReturnCallback(fn () => $this->complete);

        return new SearchIndexService($db, $this->createMock(LoggerInterface::class), $words, $roots, $mime, $fields);
    }

    private function queryBuilder(): IQueryBuilder {
        $qb = $this->createMock(IQueryBuilder::class);
        $table = null;
        $offset = null;
        $limit = null;

        $expr = $this->createMock(IExpressionBuilder::class);
        foreach (['in', 'eq', 'like', 'notIn', 'lt'] as $method) {
            $expr->method($method)->willReturnCallback(function ($column) use ($method) {
                $this->expr[] = [$method, $column instanceof IQueryFunction ? ($method === 'like' ? 'concat' : 'func') : $column];
                return $method;
            });
        }
        $expr->method('andX')->willReturnCallback(fn (...$parts) => new SqlParts('AND', $parts));
        $expr->method('orX')->willReturnCallback(fn (...$parts) => new SqlParts('OR', $parts));
        $qb->method('expr')->willReturn($expr);
        $params = [];
        $qb->method('createNamedParameter')->willReturnCallback(function ($value) use (&$params) {
            $params[] = $value;
            return ':p' . count($params);
        });
        $qb->method('getParameters')->willReturnCallback(function () use (&$params) {
            return $params;
        });
        $qb->method('getParameterTypes')->willReturn([]);
        $qb->method('getTableName')->willReturnArgument(0);
        $func = $this->createMock(IFunctionBuilder::class);
        $func->method('concat')->willReturn($this->createMock(IQueryFunction::class));
        $func->method('add')->willReturnCallback(function ($column) {
            $this->expr[] = ['add', $column];
            return $this->createMock(IQueryFunction::class);
        });
        $qb->method('func')->willReturn($func);
        $qb->method('createFunction')->willReturnCallback(function (string $call) {
            $this->functions[] = $call;
            return $this->createMock(IQueryFunction::class);
        });

        foreach (['select', 'where', 'andWhere', 'orderBy', 'addOrderBy'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('innerJoin')->willReturnCallback(function (string $from, string $join) use ($qb) {
            $this->joins[] = $join;
            return $qb;
        });
        $qb->method('from')->willReturnCallback(function (string $from) use ($qb, &$table) {
            $table = $from;
            return $qb;
        });
        $qb->method('setFirstResult')->willReturnCallback(function (int $n) use ($qb, &$offset) {
            $offset = $n;
            return $qb;
        });
        $qb->method('setMaxResults')->willReturnCallback(function (int $n) use ($qb, &$limit) {
            $limit = $n;
            return $qb;
        });
        $qb->method('executeQuery')->willReturnCallback(function () use (&$table, &$offset, &$limit) {
            $this->read[] = $table;
            $this->pages[] = [$offset, $limit];
            $rows = $this->rows[$table] ?? [];
            $result = $this->createMock(IResult::class);
            $result->method('fetch')->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));
            $result->method('fetchOne')->willReturn($rows === [] ? false : reset($rows[0]));
            return $result;
        });
        return $qb;
    }
}

/** A condition as NC's own CompositeExpression gives it, as SQL text. */
final class SqlParts implements ICompositeExpression {
    public function __construct(private string $type, private array $parts) {
    }

    public function addMultiple(array $parts = []): ICompositeExpression {
        array_push($this->parts, ...$parts);
        return $this;
    }

    public function add($part): ICompositeExpression {
        $this->parts[] = $part;
        return $this;
    }

    public function count(): int {
        return count($this->parts);
    }

    public function getType(): string {
        return $this->type;
    }

    /** As NC's own: each part in brackets, the whole not. */
    public function __toString(): string {
        if (count($this->parts) === 1) {
            return (string)$this->parts[0];
        }
        return '(' . implode(') ' . $this->type . ' (', array_map('strval', $this->parts)) . ')';
    }
}
