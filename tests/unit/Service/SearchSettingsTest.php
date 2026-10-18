<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Service\SearchSettings;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

class SearchSettingsTest extends TestCase {

    /** @var array<string, bool> what the admin set */
    private array $stored = [];

    /** @var list<array{0: string, 1: bool}> booleans asked for, with their lazy flag */
    private array $reads = [];

    private function settings(): SearchSettings {
        $config = $this->createMock(IAppConfig::class);
        $config->method('getValueBool')->willReturnCallback(function (string $app, string $key, bool $default = false, bool $lazy = false) {
            $this->assertSame('metavox', $app);
            $this->reads[] = [$key, $lazy];
            return $this->stored[$key] ?? $default;
        });
        $config->method('setValueBool')->willReturnCallback(function (string $app, string $key, bool $value, bool $lazy = false) {
            $this->assertSame(['metavox', false], [$app, $lazy]);
            $this->stored[$key] = $value;
            return true;
        });
        return new SearchSettings($config);
    }

    public function testTheFilterIsOnTheFoldersOffUntilTheAdminChooses(): void {
        // Also after an update: the filter users had stays.
        $this->assertTrue($this->settings()->filterOn());
        $this->assertFalse($this->settings()->foldersOn());
        $this->assertSame(['search_filter' => true, 'search_folders' => false], $this->settings()->all());
    }

    public function testTheAdminsChoiceCounts(): void {
        $this->settings()->set(SearchSettings::FILTER, false);

        $this->assertFalse($this->settings()->filterOn());
    }

    public function testFoldersOnlyWithTheFilter(): void {
        $this->settings()->set(SearchSettings::FILTER, false);
        $this->settings()->set(SearchSettings::FOLDERS, true);

        $this->assertFalse($this->settings()->foldersOn());
        // As set, so the admin page shows the switch the way they left it.
        $this->assertSame(['search_filter' => false, 'search_folders' => true], $this->settings()->all());

        $this->settings()->set(SearchSettings::FILTER, true);
        $this->assertTrue($this->settings()->foldersOn());
    }

    public function testReadWithTheRestOfTheAppConfig(): void {
        // Not lazy: Nextcloud loads it with the rest, no query of its own.
        $this->settings()->foldersOn();

        $this->assertNotEmpty($this->reads);
        $this->assertSame([false], array_values(array_unique(array_column($this->reads, 1))));
    }

    public function testAKeyOfAnotherTypeCountsAsTheDefault(): void {
        // Set by hand with occ as a string: Nextcloud refuses to read it as a boolean.
        $config = $this->createMock(IAppConfig::class);
        $config->method('getValueBool')->willThrowException(new \RuntimeException('type conflict'));
        $settings = new SearchSettings($config);

        $this->assertTrue($settings->filterOn());
        $this->assertFalse($settings->foldersOn());
        $this->assertSame(['search_filter' => true, 'search_folders' => false], $settings->all());
    }

    public function testOnlyItsOwnKeys(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->settings()->set('ai_enabled', true);
    }
}
