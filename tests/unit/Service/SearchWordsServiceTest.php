<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Service\SearchWordsService;
use OCP\BackgroundJob\IJobList;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class SearchWordsServiceTest extends TestCase {

    /** @var string[] tables a DELETE ran on */
    private array $deletes = [];
    /** @var list<array> parameters of each INSERT */
    private array $inserts = [];
    /** @var list<list<array>> rows for each search query, in turn */
    private array $searchRows = [];
    private int $searchQueries = 0;
    /** @var string[] the columns a query is ordered by, in turn */
    private array $orders = [];

    public function testSplitGivesLowerCaseWordsOnce(): void {
        $this->assertSame(['klant', '042', 'project'], SearchWordsService::split('Klant 042 – project/KLANT'));
        $this->assertSame(['rood', 'groen'], SearchWordsService::split('Rood;#Groen'));
        $this->assertSame(['café', 'straße'], SearchWordsService::split('Café, Straße!'));
        $this->assertSame([], SearchWordsService::split(' -; '));
    }

    public function testDigitsStayText(): void {
        $this->assertSame(['12345', '007'], SearchWordsService::split('12345 007'));
    }

    public function testSplitIsBounded(): void {
        $this->assertSame([str_repeat('a', SearchWordsService::MAX_WORD_LENGTH)], SearchWordsService::split(str_repeat('a', 200)));

        $many = implode(' ', array_map(fn(int $i) => 'w' . $i, range(1, 500)));
        $this->assertCount(SearchWordsService::MAX_WORDS_PER_VALUE, SearchWordsService::split($many));
    }

    public function testStartIsTheFirstThreeLetters(): void {
        $this->assertSame('kla', SearchWordsService::start('klant'));
        $this->assertSame('ca', SearchWordsService::start('ca'));
        $this->assertSame('caf', SearchWordsService::start('café'));
        $this->assertSame('éi', SearchWordsService::start('éi'));
    }

    public function testDatesNumbersCheckboxesAndFileLinksAreNotSearched(): void {
        foreach (['date', 'number', 'checkbox', 'filelink'] as $type) {
            $this->assertFalse(SearchWordsService::isSearched($type), $type);
        }
        foreach (['text', 'textarea', 'select', 'multiselect', 'url', 'user'] as $type) {
            $this->assertTrue(SearchWordsService::isSearched($type), $type);
        }
    }

    public function testInsertSkipsWordsThatAreThere(): void {
        $service = $this->service([]);

        $mysql = $service->buildInsertSql(2, true);
        $this->assertStringStartsWith('INSERT INTO *PREFIX*metavox_search_words (groupfolder_id, field_id, file_id, word, word_start) VALUES', $mysql);
        $this->assertSame(2, substr_count($mysql, '(?, ?, ?, ?, ?)'));
        $this->assertStringEndsWith('ON DUPLICATE KEY UPDATE file_id = file_id', $mysql);

        $this->assertStringEndsWith('ON CONFLICT DO NOTHING', $service->buildInsertSql(1, false));
    }

    public function testASavedValueReplacesTheWordsOfThatField(): void {
        $service = $this->service([['id' => 3, 'field_name' => 'klant', 'field_type' => 'text']]);

        $service->replaceValues(5, [3 => [10 => 'Klant 042 klant', 11 => 'Klant 042 klant']]);

        $this->assertSame(['metavox_search_words'], $this->deletes);
        $this->assertSame([[
            5, 3, 10, 'klant', 'kla',
            5, 3, 10, '042', '042',
            5, 3, 10, SearchWordsService::valueKey(3, 'Klant 042 klant'), '=',
            5, 3, 11, 'klant', 'kla',
            5, 3, 11, '042', '042',
            5, 3, 11, SearchWordsService::valueKey(3, 'Klant 042 klant'), '=',
        ]], $this->inserts);
    }

    public function testEachFileAndFieldGetsTheWordsOfItsOwnValue(): void {
        $service = $this->service([
            ['id' => 3, 'field_name' => 'klant', 'field_type' => 'text'],
            ['id' => 4, 'field_name' => 'status', 'field_type' => 'select'],
        ]);

        $service->replaceValues(5, [3 => [10 => 'Rood', 11 => 'Groen'], 4 => [10 => 'Klaar']]);

        // One delete per field, only for the files that field was saved for.
        $this->assertSame(['metavox_search_words', 'metavox_search_words'], $this->deletes);
        $this->assertSame([[
            5, 3, 10, 'rood', 'roo',
            5, 3, 10, SearchWordsService::valueKey(3, 'Rood'), '=',
            5, 3, 11, 'groen', 'gro',
            5, 3, 11, SearchWordsService::valueKey(3, 'Groen'), '=',
            5, 4, 10, 'klaar', 'kla',
            5, 4, 10, SearchWordsService::valueKey(4, 'Klaar'), '=',
        ]], $this->inserts);
    }

    /** A date has no words to search, but the filter finds it by its whole value. */
    public function testADateIsFoundWholeNotByWords(): void {
        $service = $this->service([['id' => 4, 'field_name' => 'due', 'field_type' => 'date']]);

        $service->replaceValues(5, [4 => [10 => '2025-04-01']]);

        $this->assertSame(['metavox_search_words'], $this->deletes);
        $this->assertSame([[5, 4, 10, SearchWordsService::valueKey(4, '2025-04-01'), '=']], $this->inserts);
    }

    public function testEachItemOfAListIsFoundOnItsOwn(): void {
        $service = $this->service([['id' => 6, 'field_name' => 'tags', 'field_type' => 'multiselect']]);

        $service->replaceValues(5, [6 => [10 => 'Rood;# Groen;#Rood']]);

        $this->assertSame([[
            5, 6, 10, 'rood', 'roo',
            5, 6, 10, 'groen', 'gro',
            5, 6, 10, SearchWordsService::valueKey(6, 'Rood'), '=',
            5, 6, 10, SearchWordsService::valueKey(6, 'Groen'), '=',
        ]], $this->inserts);
    }

    public function testTheValuesAFilterFindsAFileBy(): void {
        $this->assertSame(['Rood', 'Groen'], SearchWordsService::valueItems('multiselect', 'Rood;# Groen;#;#Rood'));
        $this->assertSame([' Klant 7 '], SearchWordsService::valueItems('text', ' Klant 7 '));
        $this->assertSame([], SearchWordsService::valueItems('text', '  '));
        $this->assertSame([], SearchWordsService::valueItems('filelink', '12:;#34:'));
        // Per field: the same value in another field is another key.
        $this->assertNotSame(SearchWordsService::valueKey(3, 'Rood'), SearchWordsService::valueKey(4, 'Rood'));
    }

    public function testTheIndexIsCompleteUnlessAFillIsBusy(): void {
        $stored = 'g1';
        $config = $this->createMock(IAppConfig::class);
        $config->method('getValueString')->willReturnCallback(function () use (&$stored) {
            return $stored;
        });
        $config->expects($this->once())->method('deleteKey')->with('metavox', 'search_words_filling')
            ->willReturnCallback(function () use (&$stored): void {
                $stored = '';
            });
        $service = new SearchWordsService($this->createMock(IDBConnection::class), $this->createMock(IJobList::class), $config);

        $this->assertFalse($service->isComplete());
        $this->assertTrue($service->isCurrentFill('g1'));
        $this->assertFalse($service->isCurrentFill('old'), 'a fill started again since');
        // An older fill that finishes says nothing about this one.
        $service->fillDone('old');
        $this->assertFalse($service->isComplete());
        $service->fillDone('g1');
        $this->assertTrue($service->isComplete());
    }

    /** With values stored, the filter reads them until the job is through; without, the index is complete. */
    public function testARebuildMarksTheIndexBusyWhenThereIsSomethingToFill(): void {
        foreach ([[1200, true], [0, false]] as [$total, $busy]) {
            $config = $this->createMock(IAppConfig::class);
            $config->expects($this->once())->method('deleteKey')->with('metavox', 'search_words_filling');
            $generation = null;
            $config->expects($busy ? $this->once() : $this->never())->method('setValueString')
                ->willReturnCallback(function (string $app, string $key, string $value) use (&$generation): bool {
                    $generation = $value;
                    return true;
                });
            $jobs = $this->createMock(IJobList::class);
            $jobs->expects($this->once())->method('add')->willReturnCallback(function (string $class, array $argument) use (&$generation, $busy, $total): void {
                $this->assertSame($total, $argument['total']);
                $this->assertNotEmpty($argument['generation']);
                if ($busy) {
                    $this->assertSame($generation, $argument['generation']);
                }
            });
            $db = $this->createMock(IDBConnection::class);
            $db->method('getQueryBuilder')->willReturnCallback(function () use ($total) {
                $qb = $this->createMock(IQueryBuilder::class);
                foreach (['selectAlias', 'from'] as $method) {
                    $qb->method($method)->willReturnSelf();
                }
                $result = $this->createMock(IResult::class);
                $result->method('fetchOne')->willReturn((string)$total);
                $qb->method('executeQuery')->willReturn($result);
                return $qb;
            });

            (new SearchWordsService($db, $jobs, $config))->rebuildAll();
        }
    }

    public function testReindexTakesTheWordsFromWhatIsStored(): void {
        $service = $this->service(
            [
                ['id' => 1, 'field_name' => 'title', 'field_type' => 'text'],
                ['id' => 2, 'field_name' => 'links', 'field_type' => 'filelink'],
            ],
            [
                ['file_id' => 10, 'groupfolder_id' => 5, 'field_name' => 'title', 'field_value' => 'Jaarverslag 2024'],
                ['file_id' => 10, 'groupfolder_id' => 5, 'field_name' => 'links', 'field_value' => '12:'],
                ['file_id' => 10, 'groupfolder_id' => 5, 'field_name' => 'removed_field', 'field_value' => 'x'],
                ['file_id' => 11, 'groupfolder_id' => 6, 'field_name' => 'title', 'field_value' => null],
            ],
        );

        $service->reindexFiles([10, 11]);

        $this->assertSame(['metavox_search_words'], $this->deletes);
        $this->assertSame([[5, 1, 10, 'jaarverslag', 'jaa', 5, 1, 10, '2024', '202', 5, 1, 10, SearchWordsService::valueKey(1, 'Jaarverslag 2024'), '=']], $this->inserts);
    }

    public function testReindexOfUnknownFieldsDoesNothing(): void {
        $service = $this->service([['id' => 1, 'field_name' => 'title', 'field_type' => 'text']]);

        $service->reindexFiles([10], ['gone']);

        $this->assertSame([], $this->deletes);
        $this->assertSame([], $this->inserts);
    }

    public function testOneWordGivesItsFilesNewestFirst(): void {
        $service = $this->service([], [], [[
            ['groupfolder_id' => 5, 'file_id' => 10, 'field_id' => 1],
            ['groupfolder_id' => 5, 'file_id' => 30, 'field_id' => 1],
            ['groupfolder_id' => 5, 'file_id' => 30, 'field_id' => 2],
            ['groupfolder_id' => 5, 'file_id' => 20, 'field_id' => 1],
        ]]);

        $this->assertSame(['hits' => [['file_id' => 30, 'groupfolder_id' => 5], ['file_id' => 20, 'groupfolder_id' => 5]], 'cut' => false], $service->search('Klant', [5], 2));
        $this->assertSame(1, $this->searchQueries);
    }

    public function testAllWordsMustBeInTheSameField(): void {
        $service = $this->service([], [], [
            [['groupfolder_id' => 5, 'file_id' => 10, 'field_id' => 1], ['groupfolder_id' => 5, 'file_id' => 11, 'field_id' => 1]],
            [['groupfolder_id' => 5, 'file_id' => 10, 'field_id' => 1], ['groupfolder_id' => 5, 'file_id' => 11, 'field_id' => 2]],
        ]);

        $this->assertSame(['hits' => [['file_id' => 10, 'groupfolder_id' => 5]], 'cut' => false], $service->search('klant 042', [5], 50));
        $this->assertSame(2, $this->searchQueries);
    }

    public function testACommonWordIsCheckedOnlyForTheFilesOfTheRareOne(): void {
        $common = array_map(fn(int $i) => ['groupfolder_id' => 5, 'file_id' => 1000 + $i, 'field_id' => 1], range(1, SearchWordsService::CANDIDATES + 1));
        $service = $this->service([], [], [
            $common,
            [['groupfolder_id' => 5, 'file_id' => 7, 'field_id' => 1], ['groupfolder_id' => 5, 'file_id' => 8, 'field_id' => 1]],
            // The common word again, for files 7 and 8 only.
            [['groupfolder_id' => 5, 'file_id' => 8, 'field_id' => 1]],
        ]);

        // The rare word was read whole, so nothing is missing.
        $this->assertSame(['hits' => [['file_id' => 8, 'groupfolder_id' => 5]], 'cut' => false], $service->search('klant 042', [5], 50));
        $this->assertSame(3, $this->searchQueries);
    }

    public function testEveryWordCutOffSaysFilesMayBeMissing(): void {
        $common = fn(int $from) => array_map(fn(int $i) => ['groupfolder_id' => 5, 'file_id' => $from + $i, 'field_id' => 1], range(1, SearchWordsService::CANDIDATES + 1));
        $service = $this->service([], [], [
            $common(1000),
            $common(9000),
            // The other word, for the first word's files: none.
            [],
        ]);

        $this->assertSame(['hits' => [], 'cut' => true], $service->search('dossier 100', [5], 50));
    }

    public function testTheNewestAreReadWithoutWordStartInTheOrder(): void {
        $service = $this->service([], [], [[['groupfolder_id' => 5, 'file_id' => 10, 'field_id' => 1]]]);
        $service->search('klant', [5], 50);

        // With it, MariaDB sorts every match of a common word before the limit.
        $this->assertSame(['groupfolder_id', 'word', 'file_id'], $this->orders);
    }

    public function testAFieldTakenOffTheTeamFolderIsNotFound(): void {
        $common = array_map(fn(int $i) => ['groupfolder_id' => 5, 'file_id' => 1000 + $i, 'field_id' => 1], range(1, SearchWordsService::CANDIDATES + 1));
        $service = $this->service([], [], [
            [['groupfolder_id' => 5, 'file_id' => 10, 'field_id' => 1], ['groupfolder_id' => 5, 'file_id' => 11, 'field_id' => 2], ['groupfolder_id' => 6, 'file_id' => 12, 'field_id' => 2]],
            [['groupfolder_id' => 5, 'file_id' => 20, 'field_id' => 2], ['groupfolder_id' => 6, 'file_id' => 21, 'field_id' => 2]],
            $common,
            // The common word for file 21 only: in field 2, which team folder 6 has.
            [['groupfolder_id' => 6, 'file_id' => 21, 'field_id' => 2]],
        ]);
        // Field 2 is on team folder 6, not on 5.
        $assigned = fn(int $groupfolderId, int $fieldId): bool => $fieldId === 1 || $groupfolderId === 6;

        $this->assertSame([['file_id' => 12, 'groupfolder_id' => 6], ['file_id' => 10, 'groupfolder_id' => 5]], $service->search('klant', [5, 6], 50, $assigned)['hits']);
        $this->assertSame([['file_id' => 21, 'groupfolder_id' => 6]], $service->search('dossier klant', [5, 6], 50, $assigned)['hits']);
    }

    public function testAWordWithoutFilesEndsTheSearch(): void {
        $service = $this->service([], [], [[]]);

        $this->assertSame(['hits' => [], 'cut' => false], $service->search('xyzzy klant', [5], 50));
        $this->assertSame(1, $this->searchQueries);
    }

    public function testNothingToLookForAsksNothing(): void {
        $db = $this->createMock(IDBConnection::class);
        $db->expects($this->never())->method('getQueryBuilder');
        $service = new SearchWordsService($db, $this->createMock(IJobList::class), $this->createMock(IAppConfig::class));

        $this->assertSame(['hits' => [], 'cut' => false], $service->search(' - ', [5], 50));
        $this->assertSame(['hits' => [], 'cut' => false], $service->search('klant', [], 50));
    }

    /**
     * @param list<array> $fields rows of metavox_gf_fields
     * @param list<array> $metadata rows of metavox_file_gf_meta
     * @param list<list<array>> $searchRows rows of metavox_search_words, per query
     */
    private function service(array $fields, array $metadata = [], array $searchRows = []): SearchWordsService {
        $this->deletes = [];
        $this->inserts = [];
        $this->searchRows = $searchRows;
        $this->searchQueries = 0;
        $db = $this->createMock(IDBConnection::class);
        $db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_POSTGRES);
        $db->method('getQueryBuilder')->willReturnCallback(fn() => $this->queryBuilder($fields, $metadata));
        $db->method('executeStatement')->willReturnCallback(function (string $sql, array $params): int {
            $this->inserts[] = $params;
            return 1;
        });
        return new SearchWordsService($db, $this->createMock(IJobList::class), $this->createMock(IAppConfig::class));
    }

    private function queryBuilder(array $fields, array $metadata): IQueryBuilder {
        $qb = $this->createMock(IQueryBuilder::class);
        $table = null;
        $qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
        foreach (['select', 'selectDistinct', 'where', 'andWhere', 'setMaxResults'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        foreach (['orderBy', 'addOrderBy'] as $method) {
            $qb->method($method)->willReturnCallback(function (string $column) use ($qb) {
                $this->orders[] = $column;
                return $qb;
            });
        }
        $qb->method('from')->willReturnCallback(function (string $from) use ($qb, &$table) {
            $table = $from;
            return $qb;
        });
        $qb->method('delete')->willReturnCallback(function (string $delete) use ($qb, &$table) {
            $table = $delete;
            return $qb;
        });
        $qb->method('executeQuery')->willReturnCallback(function () use (&$table, $fields, $metadata) {
            if ($table === SearchWordsService::TABLE) {
                $this->searchQueries++;
                $rows = array_shift($this->searchRows) ?? [];
            } else {
                $rows = $table === 'metavox_gf_fields' ? $fields : $metadata;
            }
            $result = $this->createMock(IResult::class);
            $result->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows, false]);
            return $result;
        });
        $qb->method('executeStatement')->willReturnCallback(function () use (&$table): int {
            $this->deletes[] = $table;
            return 1;
        });
        return $qb;
    }
}
