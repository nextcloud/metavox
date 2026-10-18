<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCA\MetaVox\L10n\Translatable;
use OCP\IConfig;

/**
 * A user's saved metadata searches, which Files shows as folders. They live in
 * the user's own preferences: nobody else sees them, and what a search finds
 * is checked when its folder opens, never here.
 */
class SavedSearchService {

    public const MAX_SEARCHES = 50;
    public const MAX_NAME = 100;
    public const MAX_VALUE = 1000;

    private const KEY = 'saved_searches';

    public function __construct(
        private readonly IConfig $config,
    ) {
    }

    /** @return list<array{id: int, name: string, field: string, value: string, groupfolderId: ?int}> */
    public function all(string $userId): array {
        return $this->read($userId)['searches'];
    }

    /** @return array{id: int, name: string, field: string, value: string, groupfolderId: ?int}|null */
    public function get(string $userId, int $id): ?array {
        foreach ($this->all($userId) as $search) {
            if ($search['id'] === $id) {
                return $search;
            }
        }
        return null;
    }

    /**
     * @return array{id: int, name: string, field: string, value: string, groupfolderId: ?int}
     * @throws ApiValidationException
     */
    public function add(string $userId, mixed $name, mixed $field, mixed $value, mixed $groupfolderId): array {
        $search = self::valid($name, $field, $value, $groupfolderId);
        $stored = $this->read($userId);
        if (\count($stored['searches']) >= self::MAX_SEARCHES) {
            // TRANSLATORS Error in the search filter dialog when someone tries to save a 51st search as a folder
            throw new ApiValidationException(['searches' => Translatable::t('You can save at most 50 searches.')]);
        }
        // Ids are never handed out twice, so an old link can't open another search.
        $search = ['id' => $stored['next']] + $search;
        $stored['searches'][] = $search;
        $stored['next']++;
        $this->write($userId, $stored);
        return $search;
    }

    public function remove(string $userId, int $id): bool {
        $stored = $this->read($userId);
        $kept = array_values(array_filter($stored['searches'], fn (array $search) => $search['id'] !== $id));
        if (\count($kept) === \count($stored['searches'])) {
            return false;
        }
        $stored['searches'] = $kept;
        $this->write($userId, $stored);
        return true;
    }

    /**
     * @return array{name: string, field: string, value: string, groupfolderId: ?int}
     * @throws ApiValidationException
     */
    private static function valid(mixed $name, mixed $field, mixed $value, mixed $groupfolderId): array {
        $errors = [];
        $name = self::text($name);
        if ($name === '' || mb_strlen($name) > self::MAX_NAME) {
            // TRANSLATORS Error when a saved search has no name or a name that is too long
            $errors['name'] = Translatable::t('Give the search a name of at most 100 characters.');
        }
        // Only the form of a field name: whether it exists is not told here.
        if (!\is_string($field) || preg_match('/^\w{1,255}\z/', $field) !== 1) {
            // TRANSLATORS Error when a search is saved without a metadata field to filter on
            $errors['field'] = Translatable::t('Choose a field.');
        }
        $value = self::text($value);
        if ($value === '' || mb_strlen($value) > self::MAX_VALUE) {
            // TRANSLATORS Error when a search is saved without a value to filter on
            $errors['value'] = Translatable::t('Choose a value.');
        }
        if ($groupfolderId === '') {
            $groupfolderId = null;
        }
        if ($groupfolderId !== null && (filter_var($groupfolderId, FILTER_VALIDATE_INT) === false || (int)$groupfolderId <= 0)) {
            // TRANSLATORS Error when a search is saved with a Team folder that does not exist
            $errors['groupfolderId'] = Translatable::t('Choose a Team folder.');
        }
        if ($errors !== []) {
            throw new ApiValidationException($errors);
        }
        return [
            'name' => $name,
            'field' => $field,
            'value' => $value,
            'groupfolderId' => $groupfolderId === null ? null : (int)$groupfolderId,
        ];
    }

    /** Trimmed text; empty for anything else, broken UTF-8 included. */
    private static function text(mixed $text): string {
        return \is_string($text) && mb_check_encoding($text, 'UTF-8') ? trim($text) : '';
    }

    /** @return array{next: int, searches: list<array{id: int, name: string, field: string, value: string, groupfolderId: ?int}>} */
    private function read(string $userId): array {
        $stored = json_decode($this->config->getUserValue($userId, 'metavox', self::KEY, ''), true);
        $searches = [];
        $next = 1;
        // Whatever doesn't look like a search is left out, never trusted.
        foreach (\is_array($stored['searches'] ?? null) ? $stored['searches'] : [] as $search) {
            if (!\is_array($search) || !\is_int($search['id'] ?? null)) {
                continue;
            }
            try {
                $searches[] = ['id' => $search['id']] + self::valid($search['name'] ?? null, $search['field'] ?? null, $search['value'] ?? null, $search['groupfolderId'] ?? null);
                $next = max($next, $search['id'] + 1);
            } catch (ApiValidationException) {
            }
        }
        if (\is_int($stored['next'] ?? null)) {
            $next = max($next, $stored['next']);
        }
        return ['next' => $next, 'searches' => $searches];
    }

    private function write(string $userId, array $stored): void {
        $this->config->setUserValue($userId, 'metavox', self::KEY, json_encode($stored, JSON_THROW_ON_ERROR));
    }
}
