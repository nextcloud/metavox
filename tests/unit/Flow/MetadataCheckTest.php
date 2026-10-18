<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Flow;

use OCA\MetaVox\Flow\MetadataCheck;
use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\GroupfolderRootService;
use OCA\MetaVox\Service\TeamFolderAccessService;
use OCP\Files\Cache\ICache;
use OCP\Files\Cache\ICacheEntry;
use OCP\Files\IHomeStorage;
use OCP\Files\Storage\IStorage;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\WorkflowEngine\IManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Metadata rules are the admin's: a user rule could test values of files reached through a share. */
class MetadataCheckTest extends TestCase {

    /** the team folder the file is in, from where it lives */
    private ?int $fileTeamFolder = 2;
    /** stored values: team folder => field => value */
    private array $values = [];
    /** field types by field name; text when not given */
    private array $types = [];

    private function check(?string $user): MetadataCheck {
        $session = $this->createMock(IUserSession::class);
        if ($user !== null) {
            $iuser = $this->createMock(IUser::class);
            $iuser->method('getUID')->willReturn($user);
            $session->method('getUser')->willReturn($iuser);
        }
        $access = $this->createMock(TeamFolderAccessService::class);
        $access->method('isAdmin')->willReturnCallback(fn ($uid) => $uid === 'admin');
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnArgument(0);
        $roots = $this->createMock(GroupfolderRootService::class);
        $roots->method('getGroupfolderIdForFile')->willReturnCallback(fn () => $this->fileTeamFolder);
        $fields = $this->createMock(FieldService::class);
        $fields->method('getGroupfolderFileMetadata')->willReturnCallback(fn (int $gf) => array_map(
            fn (string $name, ?string $value) => ['field_name' => $name, 'value' => $value, 'field_type' => $this->types[$name] ?? 'text'],
            array_keys($this->values[$gf] ?? []), $this->values[$gf] ?? [],
        ));
        return new MetadataCheck($l, $this->createMock(IURLGenerator::class), $this->createMock(LoggerInterface::class),
            $fields, $session, $access, $roots);
    }

    /** The rule "status is empty" (in team folder $ruleFolder, or any) on file 42. */
    private function statusIsEmpty(?int $ruleFolder = null, bool $home = false): bool {
        $entry = $this->createMock(ICacheEntry::class);
        $entry->method('getId')->willReturn(42);
        $cache = $this->createMock(ICache::class);
        $cache->method('get')->willReturn($entry);
        $storage = $this->createMock(IStorage::class);
        $storage->method('getCache')->willReturn($cache);
        $storage->method('instanceOfStorage')->willReturnCallback(fn (string $class) => $home && $class === IHomeStorage::class);

        $check = $this->check('admin');
        $check->setFileInfo($storage, 'files/Projecten/plan.pdf');
        $rule = ['field_name' => 'status', 'operator' => 'empty'] + ($ruleFolder === null ? [] : ['groupfolder_id' => $ruleFolder]);
        return $check->executeCheck('empty', json_encode($rule));
    }

    /** The rule on field "f" of type $type, with $value stored, on file 42 in team folder 2. */
    private function rule(string $type, ?string $value, string $operator, mixed $expected): bool {
        $this->types = ['f' => $type];
        $this->values[2] = ['f' => $value];
        $entry = $this->createMock(ICacheEntry::class);
        $entry->method('getId')->willReturn(42);
        $cache = $this->createMock(ICache::class);
        $cache->method('get')->willReturn($entry);
        $storage = $this->createMock(IStorage::class);
        $storage->method('getCache')->willReturn($cache);
        $check = $this->check('admin');
        $check->setFileInfo($storage, 'files/Projecten/plan.pdf');
        // The Flow editor stores a list as JSON text, one value as it is.
        $value = \is_array($expected) ? json_encode($expected) : (string)($expected ?? '');
        return $check->executeCheck($operator, json_encode(['field_name' => 'f', 'operator' => $operator, 'value' => $value]));
    }

    /**
     * The cases of the grid's highlight rules for choice fields, that Flow has too:
     * a rule reads the same in both. Not the ones about today, me, a cut-off value or a folder.
     */
    public static function choiceVectors(): array {
        $vectors = json_decode(file_get_contents(__DIR__ . '/../../fixtures/value-rules-matrix.json'), true)['vectors'];
        $cases = [];
        foreach ($vectors as $v) {
            $ctx = $v['ctx'] ?? [];
            if (!\in_array($v['type'], ['select', 'dropdown', 'multiselect', 'multi_select', 'filelink'], true)
                || !empty($ctx['truncated']) || !empty($ctx['isFolder']) || \is_array($v['rule']['value'] ?? null) && !array_is_list($v['rule']['value'])
                || !\is_bool($v['expected'])) {
                continue;
            }
            $cases[$v['name']] = [$v['type'], $v['value'], $v['rule']['op'], $v['rule']['value'] ?? null, $v['expected']];
        }
        return $cases;
    }

    #[DataProvider('choiceVectors')]
    public function testAChoiceRuleReadsAsInTheGrid(string $type, mixed $value, string $operator, mixed $expected, bool $matches): void {
        $this->assertSame($matches, $this->rule($type, $value === null ? null : (string)$value, $operator, $expected));
    }

    public function testAMultiSelectContainsAllOfItsOptions(): void {
        $this->assertTrue($this->rule('multiselect', 'Rapport;#Beleid;#Offerte', 'containsAll', ['beleid', 'Rapport']));
        $this->assertFalse($this->rule('multiselect', 'Rapport;#Beleid', 'containsAll', ['Rapport', 'Contract']));
        // "contains" from older rules: any of them, a whole option, not a piece of one.
        $this->assertTrue($this->rule('multiselect', 'Rapport;#Beleid', 'contains', ['Contract', 'Beleid']));
        $this->assertFalse($this->rule('multiselect', 'IT-beheer', 'contains', ['IT']));
        $this->assertTrue($this->rule('multiselect', 'België;#Nederland', 'oneOf', ['BELGIË']));
    }

    public function testTextStillContainsAPiece(): void {
        $this->assertTrue($this->rule('text', 'Jaarrapport 2026', 'contains', 'rapport'));
    }

    public function testANewFileWithoutMetadataIsSeen(): void {
        $this->assertTrue($this->statusIsEmpty(2));
        $this->assertTrue($this->statusIsEmpty());
    }

    public function testAFilledInValueCounts(): void {
        $this->values[2] = ['status' => 'Klaar'];

        $this->assertFalse($this->statusIsEmpty(2));
    }

    public function testARuleForATeamFolderLeavesOtherTeamFoldersAlone(): void {
        $this->fileTeamFolder = 3;

        $this->assertFalse($this->statusIsEmpty(2));
    }

    public function testValuesComeFromWhereTheFileIsNotFromOldRows(): void {
        // Old rows under team folder 5 (moved, or #98): the file is in 2.
        $this->values = [5 => ['status' => 'Oud'], 2 => []];

        $this->assertTrue($this->statusIsEmpty());
    }

    public function testOutsideATeamFolderNoRuleApplies(): void {
        // Personal files, external storage, the trash and versions.
        $this->fileTeamFolder = null;
        $this->assertFalse($this->statusIsEmpty());

        $this->fileTeamFolder = 2;
        $this->assertFalse($this->statusIsEmpty(null, home: true));
    }

    public function testNoTeamFolderChosenIsAnyTeamFolder(): void {
        foreach ([0, ''] as $none) {
            $check = $this->check('admin');
            $entry = $this->createMock(ICacheEntry::class);
            $entry->method('getId')->willReturn(42);
            $cache = $this->createMock(ICache::class);
            $cache->method('get')->willReturn($entry);
            $storage = $this->createMock(IStorage::class);
            $storage->method('getCache')->willReturn($cache);
            $check->setFileInfo($storage, 'files/a.pdf');

            $this->assertTrue($check->executeCheck('empty', json_encode(['field_name' => 'status', 'operator' => 'empty', 'groupfolder_id' => $none])), var_export($none, true));
        }
    }

    public function testOnlyOfferedInAdminFlows(): void {
        $this->assertTrue($this->check('admin')->isAvailableForScope(IManager::SCOPE_ADMIN));
        $this->assertFalse($this->check('admin')->isAvailableForScope(IManager::SCOPE_USER));
    }

    public function testANonAdminCannotSaveARuleWithIt(): void {
        $this->expectExceptionObject(new \UnexpectedValueException('The metadata check is only available to administrators'));
        $this->check('alice')->validateCheck('is', '{"field_name":"status","value":"Draft"}');
    }

    public function testAnAdminGetsTheUsualValidation(): void {
        // Past the admin check: the configuration itself is judged.
        $this->expectExceptionObject(new \UnexpectedValueException('Invalid configuration format'));
        $this->check('admin')->validateCheck('is', 'not json');
    }

    public function testNoSessionUserIsNotRefused(): void {
        $this->expectExceptionObject(new \UnexpectedValueException('Invalid configuration format'));
        $this->check(null)->validateCheck('is', 'not json');
    }
}
