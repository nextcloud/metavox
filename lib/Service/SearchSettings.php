<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCA\MetaVox\AppInfo\Application;
use OCP\IAppConfig;

/**
 * The admin's two switches for searching metadata: the filter in Nextcloud's
 * search bar, on unless the admin turns it off, and saving a filter as a
 * folder in Files, off until the admin turns it on: folders cost more per
 * opening. Read from Nextcloud's app config, which it loads once per request
 * anyway; a choice once saved stays through updates.
 */
class SearchSettings {

    public const FILTER = 'search_filter';
    public const FOLDERS = 'search_folders';

    public function __construct(
        private readonly IAppConfig $appConfig,
    ) {
    }

    private const DEFAULTS = [self::FILTER => true, self::FOLDERS => false];

    public function filterOn(): bool {
        return $this->read(self::FILTER);
    }

    /** Only with the filter: a folder is a saved filter. */
    public function foldersOn(): bool {
        return $this->filterOn() && $this->read(self::FOLDERS);
    }

    /** @return array{search_filter: bool, search_folders: bool} as the admin set them */
    public function all(): array {
        return [
            self::FILTER => $this->read(self::FILTER),
            self::FOLDERS => $this->read(self::FOLDERS),
        ];
    }

    /** The default when unset, and when someone stored it with another type: never a broken page. */
    private function read(string $key): bool {
        try {
            return $this->appConfig->getValueBool(Application::APP_ID, $key, self::DEFAULTS[$key]);
        } catch (\Throwable) {
            return self::DEFAULTS[$key];
        }
    }

    public function set(string $key, bool $on): void {
        if ($key !== self::FILTER && $key !== self::FOLDERS) {
            throw new \InvalidArgumentException('Unknown search setting');
        }
        $this->appConfig->setValueBool(Application::APP_ID, $key, $on);
    }
}
