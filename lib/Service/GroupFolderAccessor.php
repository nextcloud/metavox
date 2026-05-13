<?php
declare(strict_types=1);

namespace OCA\MetaVox\Service;

/**
 * Polymorphic accessor for groupfolders entries.
 *
 * `OCA\GroupFolders\Folder\FolderManager::getFoldersForUser()` and
 * `::getAllFolders()` have shifted return-shape across versions:
 *  - Older releases (≤ ~v18): returns objects (`stdClass`-like) with
 *    public properties `id`, `mountPoint`, `quota`, `acl`, `groups`.
 *  - Newer releases (≥ ~v19, also some NC 31 distributions): returns
 *    associative arrays with the same keys.
 *
 * Accessing `$folder->id` on an array yields a PHP warning + null on
 * older PHP, or a TypeError on PHP 8.2+. This helper handles both shapes
 * so we don't have to fork the call site per groupfolders version.
 *
 * Reported by Martin Svendsen (NC 31 + groupfolders v19, April 2026).
 */
final class GroupFolderAccessor {
    /**
     * @param array|object $folder A folder entry from FolderManager
     * @param string $key Property/key name ('id', 'mountPoint', 'quota', 'acl', 'groups')
     * @param mixed $default Returned when the key is missing on both shapes
     */
    public static function get($folder, string $key, $default = null) {
        if (is_array($folder)) {
            return $folder[$key] ?? $default;
        }
        if (is_object($folder)) {
            return $folder->{$key} ?? $default;
        }
        return $default;
    }
}
