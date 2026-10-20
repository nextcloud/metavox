<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Service\AccountService;
use OCA\MetaVox\Validation\InvalidValue;
use OCP\App\IAppManager;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A People field holds accounts, groups and teams by id. People know them by
 * name (github#109), and a new choice follows the rules for sharing with it.
 * Teams need the Teams app, which these tests run without: the browser tests
 * on the test servers cover them.
 */
class AccountServiceTest extends TestCase {

    /** Accounts with their groups. */
    private const PEOPLE = [
        'anna' => ['Anna de Vries', ['staff', 'board']],
        'bob' => ['Bob', ['staff']],
        'carol' => ['Carol', ['guests']],
    ];
    private const GROUPS = ['staff' => 'Staff', 'board' => 'Board', 'guests' => 'Guests'];

    private ?IUser $saver = null;
    private bool $groupSharing = true;
    private bool $ownGroupsOnly = false;
    private array $excludedGroups = [];
    private ?array $asked = null;
    private array $sharees = [];

    private function user(string $uid): IUser {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $user->method('getDisplayName')->willReturn(self::PEOPLE[$uid][0] ?? $uid);
        return $user;
    }

    private function service(): AccountService {
        $users = $this->createMock(IUserManager::class);
        $users->method('getDisplayName')->willReturnCallback(fn (string $uid): ?string => self::PEOPLE[$uid][0] ?? null);
        $users->method('get')->willReturnCallback(fn (string $uid): ?IUser => isset(self::PEOPLE[$uid]) ? $this->user($uid) : null);

        $groups = $this->createMock(IGroupManager::class);
        $groups->method('getDisplayName')->willReturnCallback(fn (string $gid): ?string => self::GROUPS[$gid] ?? null);
        $groups->method('getUserGroupIds')->willReturnCallback(fn (IUser $user): array => self::PEOPLE[$user->getUID()][1] ?? []);
        $groups->method('get')->willReturnCallback(function (string $gid): ?IGroup {
            if (!isset(self::GROUPS[$gid])) {
                return null;
            }
            $group = $this->createMock(IGroup::class);
            $group->method('inGroup')->willReturnCallback(fn (IUser $user): bool => in_array($gid, self::PEOPLE[$user->getUID()][1] ?? [], true));
            return $group;
        });

        $shares = $this->createMock(IShareManager::class);
        $shares->method('allowGroupSharing')->willReturnCallback(fn (): bool => $this->groupSharing);
        $shares->method('shareWithGroupMembersOnly')->willReturnCallback(fn (): bool => $this->ownGroupsOnly);
        $shares->method('shareWithGroupMembersOnlyExcludeGroupsList')->willReturnCallback(fn (): array => $this->excludedGroups);

        $search = $this->createMock(ISearch::class);
        $search->method('search')->willReturnCallback(function (...$args) {
            $this->asked = $args;
            return [$this->sharees, false];
        });

        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->saver);

        return new AccountService($users, $groups, $shares, $search, $this->createMock(IAppManager::class),
            $session, $this->createMock(ContainerInterface::class), $this->createMock(LoggerInterface::class));
    }

    private static function field(bool $multiple = false, array $kinds = ['user']): array {
        return ['field_type' => 'user', 'field_options' => json_encode(['multiple' => $multiple, 'kinds' => $kinds])];
    }

    private function refusal(array $field, string $value, string $current = ''): ?string {
        try {
            $this->service()->chosen($field, $value, $current);
            return null;
        } catch (InvalidValue $e) {
            return $e->getMessage();
        }
    }

    // What a value holds

    public static function items(): array {
        return [
            'an account' => ['anna', 'user', 'anna'],
            'a group' => ['group:staff', 'group', 'staff'],
            'a team' => ['team:Ab12', 'team', 'Ab12'],
            'a group whose id has a colon' => ['group:a:b', 'group', 'a:b'],
        ];
    }

    #[DataProvider('items')]
    public function testAnItemIsAKindAndAnId(string $item, string $kind, string $id): void {
        $this->assertSame($kind, AccountService::kind($item));
        $this->assertSame($id, AccountService::id($item));
        $this->assertSame($item, AccountService::item($kind, $id));
        $this->assertTrue(AccountService::isItem($item));
    }

    public function testAValueFromBeforeGroupsIsOneAccount(): void {
        $this->assertSame(['anna'], AccountService::items('anna'));
        $this->assertSame(['anna', 'group:staff', '123'], AccountService::items(' anna ;#;#group:staff;#anna;#123'));
        $this->assertSame([], AccountService::items(''));
        $this->assertSame([], AccountService::items(null));
    }

    public function testNotAnItem(): void {
        foreach (['other:anna', 'group:', 'team:', "an\0na", ''] as $text) {
            $this->assertFalse(AccountService::isItem($text), $text);
        }
    }

    // Names

    public function testPeopleAndGroupsByName(): void {
        $rows = $this->service()->withNames([
            ['field_name' => 'owner', 'field_type' => 'user', 'value' => 'anna;#group:staff'],
        ], 'bob');

        $this->assertSame([
            ['id' => 'anna', 'kind' => 'user', 'name' => 'Anna de Vries'],
            ['id' => 'group:staff', 'kind' => 'group', 'name' => 'Staff'],
        ], $rows[0]['accounts']);
        $this->assertSame('anna;#group:staff', $rows[0]['value']);
    }

    public function testWhatIsGoneOrHiddenHasNoName(): void {
        $rows = $this->service()->withNames([
            ['field_type' => 'user', 'value' => 'gone;#group:gone;#team:Ab12'],
        ], 'bob');

        $this->assertSame([null, null, null], array_column($rows[0]['accounts'], 'name'));
        $this->assertSame(['user', 'group', 'team'], array_column($rows[0]['accounts'], 'kind'));
    }

    public function testEmptyFieldsAndOtherTypesAreLeftAlone(): void {
        $rows = [
            ['field_name' => 'owner', 'field_type' => 'user', 'value' => ''],
            ['field_name' => 'reviewer', 'field_type' => 'user'],
            ['field_name' => 'title', 'field_type' => 'text', 'value' => 'anna'],
        ];

        $this->assertSame($rows, $this->service()->withNames($rows, 'bob'));
    }

    public function testRowsKeepTheirKeys(): void {
        // The defaults answer is keyed by field id.
        $rows = $this->service()->withNames([7 => ['field_type' => 'user', 'value' => 'anna']], 'bob');

        $this->assertSame([7], array_keys($rows));
        $this->assertSame('Anna de Vries', $rows[7]['accounts'][0]['name']);
    }

    public function testNamesInTheValuesOfPeopleFieldsOnly(): void {
        $names = $this->service()->namesInValues(
            ['owner' => 'anna;#group:board', 'title' => 'bob', 'empty' => null],
            ['owner' => true, 'empty' => true],
            'carol',
        );

        $this->assertSame(['anna' => 'Anna de Vries', 'group:board' => 'Board'], $names);
    }

    // Choosing

    public function testWhatTheValueHeldStays(): void {
        // Gone, of a kind the field no longer takes, more than it takes now:
        // it stays until someone takes it out.
        $this->saver = $this->user('carol');
        $field = self::field();

        $this->assertNull($this->refusal($field, 'gone;#group:staff;#team:Ab12', 'gone;#group:staff;#team:Ab12'));
        $this->assertNull($this->refusal($field, 'gone;#team:Ab12', 'gone;#group:staff;#team:Ab12'));
        $this->assertSame('gone;#team:Ab12', $this->service()->chosen($field, 'gone;#team:Ab12', 'gone;#group:staff;#team:Ab12'));
    }

    public function testOneUnlessTheFieldTakesSeveral(): void {
        $this->saver = $this->user('anna');

        $this->assertSame('Choose only one', $this->refusal(self::field(), 'anna;#bob'));
        $this->assertNull($this->refusal(self::field(true), 'anna;#bob'));
        // Several from before: one more is too many, one fewer is fine.
        $this->assertSame('Choose only one', $this->refusal(self::field(), 'anna;#bob;#carol', 'anna;#bob'));
        $this->assertNull($this->refusal(self::field(), 'bob', 'anna;#bob'));
    }

    public function testOnlyTheKindsTheFieldTakes(): void {
        $this->saver = $this->user('anna');

        $this->assertSame('This field takes no groups', $this->refusal(self::field(true), 'group:staff'));
        $this->assertSame('This field takes no teams', $this->refusal(self::field(true, ['user', 'group']), 'team:Ab12'));
        $this->assertSame('This field takes no accounts', $this->refusal(self::field(true, ['group']), 'anna'));
        $this->assertNull($this->refusal(self::field(true, ['user', 'group']), 'anna;#group:staff'));
    }

    public function testWhatIsChosenExists(): void {
        $this->saver = $this->user('anna');
        $field = self::field(true, ['user', 'group', 'team']);

        $this->assertSame('Choose an existing account', $this->refusal($field, 'nobody'));
        $this->assertSame('Choose an existing group', $this->refusal($field, 'group:nobody'));
        // Without the Teams app no team can be found.
        $this->assertSame('Choose an existing team', $this->refusal($field, 'team:Ab12'));
    }

    public function testGroupsOnlyWhenSharingWithGroupsIsOn(): void {
        $this->saver = $this->user('anna');
        $this->groupSharing = false;

        $this->assertSame('Groups cannot be chosen on this server', $this->refusal(self::field(true, ['group']), 'group:staff'));
    }

    public function testOnlyYourOwnGroupsWhenSharingIsLimitedToThem(): void {
        $this->saver = $this->user('bob');
        $this->ownGroupsOnly = true;
        $field = self::field(true, ['user', 'group']);

        $this->assertNull($this->refusal($field, 'group:staff'));
        $this->assertSame('Choose one of your own groups', $this->refusal($field, 'group:board'));
        $this->assertNull($this->refusal($field, 'anna'));
        $this->assertSame('Choose someone in one of your groups', $this->refusal($field, 'carol'));
        $this->assertNull($this->refusal($field, 'bob'));

        // A group left out of that rule does not count as shared.
        $this->excludedGroups = ['staff'];
        $this->assertSame('Choose someone in one of your groups', $this->refusal($field, 'anna'));
        $this->assertSame('Choose one of your own groups', $this->refusal($field, 'group:staff'));
    }

    public function testWithoutSomeoneSavingOnlyThatItExists(): void {
        $this->ownGroupsOnly = true;
        $this->groupSharing = false;
        $field = self::field(true, ['user', 'group']);

        $this->assertNull($this->refusal($field, 'carol;#group:board'));
        $this->assertSame('Choose an existing group', $this->refusal($field, 'group:nobody'));
    }

    // The picker

    private static function sharee(int $type, string $id, string $name): array {
        return ['label' => $name, 'value' => ['shareType' => $type, 'shareWith' => $id]];
    }

    public function testAsksNextcloudForTheKindsTheFieldTakes(): void {
        $me = $this->user('anna');

        $this->service()->search($me, 'bo', ['user']);
        $this->assertSame(['bo', [IShare::TYPE_USER], false, 25, 0], $this->asked);

        $this->service()->search($me, 'bo', ['user', 'group']);
        $this->assertSame([IShare::TYPE_USER, IShare::TYPE_GROUP], $this->asked[1]);

        // No groups when sharing with groups is off, no teams without the Teams app.
        $this->groupSharing = false;
        $this->asked = null;
        $this->assertSame([], $this->service()->search($me, 'bo', ['group', 'team']));
        $this->assertNull($this->asked);
    }

    public function testGivesWhatNextcloudFindsExactMatchesFirst(): void {
        $this->sharees = [
            'exact' => ['users' => [self::sharee(IShare::TYPE_USER, 'bo', 'Bo')], 'groups' => []],
            'users' => [self::sharee(IShare::TYPE_USER, 'bob', 'Bob'), self::sharee(IShare::TYPE_USER, 'bo', 'Bo')],
            'groups' => [self::sharee(IShare::TYPE_GROUP, 'board', 'Board')],
        ];

        $found = $this->service()->search($this->user('anna'), 'bo', ['user', 'group']);

        $this->assertSame([
            ['id' => 'bo', 'kind' => 'user', 'name' => 'Bo'],
            ['id' => 'bob', 'kind' => 'user', 'name' => 'Bob'],
            ['id' => 'group:board', 'kind' => 'group', 'name' => 'Board'],
        ], $found);
    }

    public function testYouCanPickYourself(): void {
        $this->assertSame([['id' => 'anna', 'kind' => 'user', 'name' => 'Anna de Vries']], $this->service()->search($this->user('anna'), 'vries', ['user']));
        $this->assertSame([], $this->service()->search($this->user('anna'), 'vries', ['group']));
    }

    public function testANumericAccountIdStaysText(): void {
        $this->sharees = ['users' => [self::sharee(IShare::TYPE_USER, '123', 'Nummer')]];

        $this->assertSame([['id' => '123', 'kind' => 'user', 'name' => 'Nummer']], $this->service()->search($this->user('anna'), 'nummer', ['user']));
    }

    // Assigned to you

    public function testYouAreYourAccountAndYourGroups(): void {
        $this->assertSame(['anna', 'group:staff', 'group:board'], $this->service()->viewerItems($this->user('anna')));
    }

    public function testOnlyFieldsWithGroupsOrTeamsNeedThem(): void {
        $this->assertFalse(AccountService::takesGroupsOrTeams([self::field(true), ['field_type' => 'user', 'field_options' => '[]'], ['field_type' => 'text']]));
        $this->assertTrue(AccountService::takesGroupsOrTeams([self::field(false, ['user', 'team'])]));
    }
}
