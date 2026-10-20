<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCA\Circles\CirclesManager;
use OCA\Circles\Model\Circle;
use OCA\MetaVox\L10n\Translatable;
use OCA\MetaVox\Validation\FieldOptions;
use OCA\MetaVox\Validation\InvalidValue;
use OCP\App\IAppManager;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The values of a People field: accounts, groups and teams, stored by id. An
 * item is an account id as it is, or group:<id> or team:<id>; several are
 * joined with ;#. An account id never holds a colon, so a value from before
 * groups and teams is an account, as it was.
 *
 * Who sees what follows Nextcloud: a name goes with a value someone may read,
 * a team's name only to someone who can see the team, and a new choice
 * follows the rules for sharing with that account, group or team.
 */
class AccountService {

    /** Field types whose value is one or more of these items. */
    public const FIELD_TYPES = ['user'];

    private const PREFIXES = ['group' => 'group:', 'team' => 'team:'];

    /** Results the picker shows at most. */
    private const SEARCH_LIMIT = 25;

    /** @var array<string, array<string, ?string>> viewer ('' for none) => team id => name, null when hidden */
    private array $teamNames = [];

    public function __construct(
        private readonly IUserManager $users,
        private readonly IGroupManager $groups,
        private readonly IShareManager $shares,
        private readonly ISearch $sharees,
        private readonly IAppManager $apps,
        private readonly IUserSession $userSession,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function isAccountField(array $field): bool {
        return in_array($field['field_type'] ?? '', self::FIELD_TYPES, true);
    }

    /** @return list<string> the items of a stored value, each once */
    public static function items(?string $value): array {
        $items = [];
        foreach (explode(';#', (string)$value) as $item) {
            $item = trim($item);
            if ($item !== '') {
                $items[$item] = true;
            }
        }
        return array_map('strval', array_keys($items));
    }

    /** 'user', 'group' or 'team'. */
    public static function kind(string $item): string {
        foreach (self::PREFIXES as $kind => $prefix) {
            if (str_starts_with($item, $prefix)) {
                return $kind;
            }
        }
        return 'user';
    }

    public static function id(string $item): string {
        $kind = self::kind($item);
        return $kind === 'user' ? $item : substr($item, strlen(self::PREFIXES[$kind]));
    }

    public static function item(string $kind, string $id): string {
        return (self::PREFIXES[$kind] ?? '') . $id;
    }

    /** Whether the text can be an item: an account id without a colon, or a prefix and an id. */
    public static function isItem(string $item): bool {
        $id = self::id($item);
        return $id !== '' && !preg_match('/[\x00-\x1F\x7F]|;#/', $item)
            && (self::kind($item) !== 'user' || !str_contains($item, ':'));
    }

    /**
     * The names of these items as the viewer reads them: an account's and a
     * group's display name, a team's when the viewer can see the team.
     * Without a name: gone, or hidden from the viewer.
     *
     * @param list<string> $items
     * @return array<string, string> item => name
     */
    public function names(array $items, string $viewer): array {
        $names = [];
        $teams = [];
        foreach (array_unique($items) as $item) {
            $item = (string)$item;
            $kind = self::kind($item);
            if ($kind === 'team') {
                $teams[] = self::id($item);
                continue;
            }
            $name = $kind === 'group' ? $this->groups->getDisplayName(self::id($item)) : $this->users->getDisplayName($item);
            if ($name !== null) {
                $names[$item] = $name;
            }
        }
        foreach ($this->teamNamesFor($teams, $viewer) as $id => $name) {
            $names[self::item('team', $id)] = $name;
        }
        return $names;
    }

    /**
     * The names in the values of People fields.
     *
     * @param array<string, ?string> $values field name => stored value
     * @param array<string, true> $accountFields the People fields, by name
     * @return array<string, string> item => name
     */
    public function namesInValues(array $values, array $accountFields, string $viewer): array {
        $items = [];
        foreach (array_intersect_key($values, $accountFields) as $value) {
            array_push($items, ...self::items($value));
        }
        return $items === [] ? [] : $this->names($items, $viewer);
    }

    /**
     * Each People field with what it holds, by name: [{id, kind, name}], the
     * name null when it is gone or hidden from the viewer.
     *
     * @param array<array-key, array<string, mixed>> $rows with field_type and value
     * @return array<array-key, array<string, mixed>>
     */
    public function withNames(array $rows, string $viewer): array {
        $items = [];
        foreach ($rows as $row) {
            if (self::isAccountField($row)) {
                array_push($items, ...self::items((string)($row['value'] ?? '')));
            }
        }
        if ($items === []) {
            return $rows;
        }
        $names = $this->names($items, $viewer);
        foreach ($rows as &$row) {
            if (self::isAccountField($row) && (string)($row['value'] ?? '') !== '') {
                $row['accounts'] = array_map(fn (string $item): array => [
                    'id' => $item,
                    'kind' => self::kind($item),
                    'name' => $names[$item] ?? null,
                ], self::items((string)$row['value']));
            }
        }
        unset($row);
        return $rows;
    }

    /**
     * What "assigned to you" looks for: the account, its groups and its teams.
     *
     * @return list<string>
     */
    public function viewerItems(IUser $user): array {
        $items = [$user->getUID()];
        foreach ($this->groups->getUserGroupIds($user) as $gid) {
            $items[] = self::item('group', (string)$gid);
        }
        $circles = $this->circles();
        if ($circles !== null) {
            try {
                foreach ($circles->getLocalFederatedUser($user->getUID())->getMemberships() as $membership) {
                    $items[] = self::item('team', $membership->getCircleId());
                }
            } catch (\Exception $e) {
                $this->logger->debug('MetaVox: no teams for the viewer', ['exception' => $e]);
            }
        }
        return $items;
    }

    /** Whether one of these fields takes groups or teams. */
    public static function takesGroupsOrTeams(array $fields): bool {
        foreach ($fields as $field) {
            if (self::isAccountField($field) && FieldOptions::accounts($field['field_options'] ?? null)['kinds'] !== ['user']) {
                return true;
            }
        }
        return false;
    }

    /** Whether teams can be chosen: the Teams app is on. */
    public function teamsAvailable(): bool {
        return $this->circles() !== null;
    }

    /**
     * Accounts, groups and teams to pick for a field, as Nextcloud's sharee
     * search finds them for this user: its share settings apply.
     *
     * @param list<string> $kinds
     * @return list<array{id: string, kind: string, name: string}>
     */
    public function search(IUser $user, string $term, array $kinds): array {
        $types = array_filter([
            'user' => IShare::TYPE_USER,
            'group' => $this->shares->allowGroupSharing() ? IShare::TYPE_GROUP : null,
            'team' => $this->teamsAvailable() ? IShare::TYPE_CIRCLE : null,
        ], fn (?int $type, string $kind): bool => $type !== null && in_array($kind, $kinds, true), ARRAY_FILTER_USE_BOTH);
        if ($types === []) {
            return [];
        }
        [$result] = $this->sharees->search($term, array_values($types), false, self::SEARCH_LIMIT, 0);

        $found = [];
        // The sharee search leaves out the one searching; picking yourself is fine.
        if (isset($types['user']) && ($term === '' || str_contains(mb_strtolower($user->getUID() . ' ' . $user->getDisplayName()), mb_strtolower($term)))) {
            $found[$user->getUID()] = ['id' => $user->getUID(), 'kind' => 'user', 'name' => $user->getDisplayName()];
        }
        foreach (['user' => 'users', 'group' => 'groups', 'team' => 'circles'] as $kind => $key) {
            if (!isset($types[$kind])) {
                continue;
            }
            foreach (array_merge($result['exact'][$key] ?? [], $result[$key] ?? []) as $sharee) {
                $id = (string)($sharee['value']['shareWith'] ?? '');
                $item = self::item($kind, $id);
                if ($id !== '' && !isset($found[$item])) {
                    $found[$item] = ['id' => $item, 'kind' => $kind, 'name' => (string)($sharee['label'] ?? $id)];
                }
            }
        }
        return array_values(array_slice($found, 0, self::SEARCH_LIMIT, true));
    }

    /**
     * A People value as it is stored. What it held already stays; what is new
     * must exist, be of a kind the field takes, and be something the one
     * saving could share with. One field takes one, unless it takes several:
     * a value from before that change may lose items, not gain them.
     *
     * @param string $value the value in its stored form (FieldValue)
     * @throws InvalidValue
     */
    public function chosen(array $field, string $value, string $current): string {
        $items = self::items($value);
        $before = array_flip(self::items($current));
        $new = array_values(array_filter($items, fn (string $item): bool => !isset($before[$item])));
        if ($new === []) {
            return implode(';#', $items);
        }

        $options = FieldOptions::accounts($field['field_options'] ?? null);
        if (!$options['multiple'] && count($items) > 1) {
            // TRANSLATORS Error under a People field that takes one account, group or team, when several were chosen
            throw new InvalidValue(Translatable::t('Choose only one'));
        }
        $saver = $this->userSession->getUser();
        foreach ($new as $item) {
            $kind = self::kind($item);
            if (!in_array($kind, $options['kinds'], true)) {
                throw new InvalidValue(match ($kind) {
                    // TRANSLATORS Error under a People field when a group was chosen, but the field takes no groups
                    'group' => Translatable::t('This field takes no groups'),
                    // TRANSLATORS Error under a People field when a team was chosen, but the field takes no teams
                    'team' => Translatable::t('This field takes no teams'),
                    // TRANSLATORS Error under a People field when an account was chosen, but the field takes only groups or teams
                    default => Translatable::t('This field takes no accounts'),
                });
            }
            $this->checkChoice($kind, self::id($item), $saver);
        }
        return implode(';#', $items);
    }

    /**
     * What sharing asks of the same choice: the account or group exists, the
     * team is one the user can see, and the share settings allow it.
     * Without a user (a background job), only that it exists.
     *
     * @throws InvalidValue
     */
    private function checkChoice(string $kind, string $id, ?IUser $saver): void {
        if ($kind === 'team') {
            if ($this->teamName($id, $saver?->getUID()) === null) {
                // TRANSLATORS Error under a People field when the chosen team does not exist or the user cannot see it
                throw new InvalidValue(Translatable::t('Choose an existing team'));
            }
            return;
        }
        if ($kind === 'group') {
            $group = $this->groups->get($id);
            if ($group === null) {
                // TRANSLATORS Error under a People field when the chosen group does not exist
                throw new InvalidValue(Translatable::t('Choose an existing group'));
            }
            if ($saver === null) {
                return;
            }
            if (!$this->shares->allowGroupSharing()) {
                // TRANSLATORS Error under a People field when the administration turned off sharing with groups
                throw new InvalidValue(Translatable::t('Groups cannot be chosen on this server'));
            }
            if ($this->shares->shareWithGroupMembersOnly()
                && (!$group->inGroup($saver) || in_array($id, $this->shares->shareWithGroupMembersOnlyExcludeGroupsList(), true))) {
                // TRANSLATORS Error under a People field when the administration allows sharing only within your own groups
                throw new InvalidValue(Translatable::t('Choose one of your own groups'));
            }
            return;
        }

        $account = $this->users->get($id);
        if ($account === null) {
            // TRANSLATORS Error under an account field (a person) that got a name which is no account on this server
            throw new InvalidValue(Translatable::t('Choose an existing account'));
        }
        if ($saver === null || $account->getUID() === $saver->getUID() || !$this->shares->shareWithGroupMembersOnly()) {
            return;
        }
        $shared = array_diff(
            array_intersect($this->groups->getUserGroupIds($saver), $this->groups->getUserGroupIds($account)),
            $this->shares->shareWithGroupMembersOnlyExcludeGroupsList(),
        );
        if ($shared === []) {
            // TRANSLATORS Error under a People field when the administration allows sharing only with people in your own groups
            throw new InvalidValue(Translatable::t('Choose someone in one of your groups'));
        }
    }

    /** @return array<string, string> team id => name, for the teams the viewer can see */
    private function teamNamesFor(array $ids, string $viewer): array {
        $names = [];
        foreach (array_unique($ids) as $id) {
            $name = $this->teamName((string)$id, $viewer);
            if ($name !== null) {
                $names[(string)$id] = $name;
            }
        }
        return $names;
    }

    /**
     * A team's name, as the Teams app shows it to this user; null when gone or
     * hidden from them. Without a user, whether it exists at all.
     */
    private function teamName(string $id, ?string $viewer): ?string {
        if (array_key_exists($id, $this->teamNames[$viewer ?? ''] ?? [])) {
            return $this->teamNames[$viewer ?? ''][$id];
        }
        $name = null;
        $circles = $this->circles();
        if ($circles !== null) {
            try {
                $viewer === null ? $circles->startSuperSession() : $circles->startSession($circles->getLocalFederatedUser($viewer));
                $circle = $circles->getCircle($id);
                // A person's own circle and a group's are no teams.
                if (!$circle->isConfig(Circle::CFG_SINGLE) && !$circle->isConfig(Circle::CFG_BACKEND)) {
                    $name = $circle->getDisplayName();
                }
            } catch (\Exception $e) {
                // Not found, or not visible to this user.
            } finally {
                $circles->stopSession();
            }
        }
        return $this->teamNames[$viewer ?? ''][$id] = $name;
    }

    private function circles(): ?CirclesManager {
        if (!class_exists(CirclesManager::class) || !$this->apps->isEnabledForUser('circles')) {
            return null;
        }
        try {
            return $this->container->get(CirclesManager::class);
        } catch (\Throwable $e) {
            $this->logger->debug('MetaVox: Teams not available', ['exception' => $e]);
            return null;
        }
    }
}
