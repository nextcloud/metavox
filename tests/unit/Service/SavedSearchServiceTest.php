<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Service\ApiValidationException;
use OCA\MetaVox\Service\SavedSearchService;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SavedSearchServiceTest extends TestCase {

    /** @var array<string, string> user => stored preference */
    private array $stored = [];

    private function service(): SavedSearchService {
        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturnCallback(function (string $user, string $app, string $key, $default) {
            $this->assertSame(['metavox', 'saved_searches'], [$app, $key]);
            return $this->stored[$user] ?? $default;
        });
        $config->method('setUserValue')->willReturnCallback(function (string $user, string $app, string $key, $value) {
            $this->assertSame(['metavox', 'saved_searches'], [$app, $key]);
            $this->stored[$user] = $value;
        });
        return new SavedSearchService($config);
    }

    public function testNoneAtFirst(): void {
        $this->assertSame([], $this->service()->all('alice'));
        $this->assertNull($this->service()->get('alice', 1));
    }

    public function testAddsAndFindsAgain(): void {
        $added = $this->service()->add('alice', '  Status: Concept ', 'file_gf_status', ' Concept ', '7');

        $this->assertSame(['id' => 1, 'name' => 'Status: Concept', 'field' => 'file_gf_status', 'value' => 'Concept', 'groupfolderId' => 7], $added);
        $this->assertSame([$added], $this->service()->all('alice'));
        $this->assertSame($added, $this->service()->get('alice', 1));
    }

    public function testEachUserHasTheirOwn(): void {
        $this->service()->add('alice', 'Mine', 'file_gf_status', 'Concept', null);

        $this->assertSame([], $this->service()->all('bob'));
        $this->assertNull($this->service()->get('bob', 1));
        $this->assertFalse($this->service()->remove('bob', 1));
        $this->assertCount(1, $this->service()->all('alice'));
    }

    public function testWithoutATeamFolderSearchesThemAll(): void {
        $this->assertNull($this->service()->add('alice', 'All', 'file_gf_status', 'Concept', null)['groupfolderId']);
        $this->assertNull($this->service()->add('alice', 'All', 'file_gf_status', 'Concept', '')['groupfolderId']);
    }

    public function testALongFieldName(): void {
        $field = 'file_gf_' . str_repeat('a', 247);

        $this->assertSame($field, $this->service()->add('alice', 'Long', $field, 'A', null)['field']);
    }

    public function testAListValueStaysAsTheFilterSendsIt(): void {
        $added = $this->service()->add('alice', 'Tags', 'file_gf_tags', 'red;#blue', 3);

        $this->assertSame('red;#blue', $added['value']);
    }

    public function testIdsAreNeverHandedOutTwice(): void {
        $service = $this->service();
        $service->add('alice', 'One', 'file_gf_status', 'A', null);
        $service->add('alice', 'Two', 'file_gf_status', 'B', null);

        $this->assertTrue($service->remove('alice', 2));
        $this->assertSame(3, $service->add('alice', 'Three', 'file_gf_status', 'C', null)['id']);
        $this->assertSame([1, 3], array_column($service->all('alice'), 'id'));
    }

    public function testRemovesOnlyThatOne(): void {
        $service = $this->service();
        $service->add('alice', 'One', 'file_gf_status', 'A', null);
        $service->add('alice', 'Two', 'file_gf_status', 'B', null);

        $this->assertTrue($service->remove('alice', 1));
        $this->assertFalse($service->remove('alice', 1));
        $this->assertSame(['Two'], array_column($service->all('alice'), 'name'));
    }

    public function testAtMostFifty(): void {
        $service = $this->service();
        for ($i = 0; $i < SavedSearchService::MAX_SEARCHES; $i++) {
            $service->add('alice', "Search $i", 'file_gf_status', 'A', null);
        }

        try {
            $service->add('alice', 'One too many', 'file_gf_status', 'A', null);
            $this->fail('a 51st search was saved');
        } catch (ApiValidationException $e) {
            $this->assertSame(['searches'], array_keys($e->getErrors()));
        }
        $this->assertCount(SavedSearchService::MAX_SEARCHES, $service->all('alice'));
    }

    public static function invalidInput(): array {
        return [
            'no name' => [['name' => '  '], 'name'],
            'name not a string' => [['name' => ['x']], 'name'],
            'name too long' => [['name' => str_repeat('é', 101)], 'name'],
            'no field' => [['field' => ''], 'field'],
            'field with SQL' => [['field' => "status' OR 1=1"], 'field'],
            'field with a colon' => [['field' => 'a:b'], 'field'],
            'field too long' => [['field' => str_repeat('a', 256)], 'field'],
            'field with a newline' => [['field' => "file_gf_status\n"], 'field'],
            'name not UTF-8' => [['name' => "\xFF"], 'name'],
            'value not UTF-8' => [['value' => "Con\xC3cept"], 'value'],
            'field not a string' => [['field' => 5], 'field'],
            'no value' => [['value' => ' '], 'value'],
            'value not a string' => [['value' => ['Concept']], 'value'],
            'value too long' => [['value' => str_repeat('a', 1001)], 'value'],
            'team folder zero' => [['groupfolderId' => 0], 'groupfolderId'],
            'team folder negative' => [['groupfolderId' => '-1'], 'groupfolderId'],
            'team folder not a number' => [['groupfolderId' => 'x'], 'groupfolderId'],
            'team folder a list' => [['groupfolderId' => [1]], 'groupfolderId'],
        ];
    }

    #[DataProvider('invalidInput')]
    public function testRefusesInvalidInput(array $override, string $key): void {
        $input = $override + ['name' => 'Name', 'field' => 'file_gf_status', 'value' => 'Concept', 'groupfolderId' => 1];
        try {
            $this->service()->add('alice', $input['name'], $input['field'], $input['value'], $input['groupfolderId']);
            $this->fail('saved');
        } catch (ApiValidationException $e) {
            $this->assertSame([$key], array_keys($e->getErrors()));
        }
        $this->assertSame([], $this->stored);
    }

    public function testLeavesOutWhatIsNotASearch(): void {
        $this->stored['alice'] = json_encode(['next' => 4, 'searches' => [
            ['id' => 1, 'name' => 'Good', 'field' => 'file_gf_status', 'value' => 'A', 'groupfolderId' => null],
            ['id' => '2', 'name' => 'String id', 'field' => 'file_gf_status', 'value' => 'A'],
            ['id' => 3, 'name' => 'Bad field', 'field' => 'a b', 'value' => 'A'],
            'not a search',
        ]]);

        $this->assertSame([1], array_column($this->service()->all('alice'), 'id'));
        // The counter still says 4, so 3 stays used.
        $this->assertSame(4, $this->service()->add('alice', 'New', 'file_gf_status', 'B', null)['id']);
    }

    public function testBrokenPreferenceCountsAsNone(): void {
        $this->stored['alice'] = '{not json';

        $this->assertSame([], $this->service()->all('alice'));
        $this->assertSame(1, $this->service()->add('alice', 'New', 'file_gf_status', 'B', null)['id']);
    }
}
