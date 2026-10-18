<?php
declare(strict_types=1);

namespace OCA\MetaVox\Service;

/**
 * Reads groupfolders entries on every version: objects on groupfolders 20+
 * (NC 32+), arrays with folder_id/mount_point on groupfolders 19 (NC 31).
 */
final class GroupFolderAccessor {

    /** groupfolders 19 array keys. */
    private const ALIASES = [
        'id' => ['folder_id'],
        'mountPoint' => ['mount_point'],
        'aclDefaultNoPermission' => ['acl_default_no_permission'],
    ];

    /**
     * @param array|object $folder A folder entry from FolderManager
     * @param string $key Property/key name ('id', 'mountPoint', 'quota', 'acl', 'groups')
     * @param mixed $default Returned when the key is missing on both shapes
     */
    public static function get($folder, string $key, $default = null) {
        foreach ([$key, ...(self::ALIASES[$key] ?? [])] as $candidate) {
            $value = is_array($folder) ? ($folder[$candidate] ?? null)
                : (is_object($folder) ? ($folder->{$candidate} ?? null) : null);
            if ($value !== null) {
                return $value;
            }
        }
        return $default;
    }
}
