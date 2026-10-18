<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Controller;

use OCA\MetaVox\Controller\SettingsController;
use OCA\MetaVox\Service\AiAutofillService;
use OCA\MetaVox\Service\ApiValidationException;
use OCA\MetaVox\Service\SearchSettings;
use OCA\MetaVox\Validation\LinkSettings;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/** The admin settings route carries the search switches and what a URL field takes. */
class SettingsSearchTest extends TestCase {

    /** @var array<string, mixed> */
    private array $params = [];

    /** @var list<array{0: string, 1: bool}> */
    private array $set = [];

    private function controller(): SettingsController {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => $this->params[$key] ?? $default);
        $ai = $this->createMock(AiAutofillService::class);
        $ai->method('isEnabledByAdmin')->willReturn(true);
        $search = $this->createMock(SearchSettings::class);
        $search->method('all')->willReturn(['search_filter' => true, 'search_folders' => false]);
        $search->method('set')->willReturnCallback(function (string $key, bool $on) {
            $this->set[] = [$key, $on];
        });
        $links = $this->createMock(LinkSettings::class);
        $links->method('webOnly')->willReturn(false);
        $links->method('blockedDomains')->willReturn(['ru']);
        $links->method('allowedDomains')->willReturn([]);
        $links->method('setDomains')->willReturnCallback(function (string $key, mixed $domains) {
            $this->set[] = [$key, LinkSettings::domainList($domains)];
        });
        $links->method('setWebOnly')->willReturnCallback(function (bool $on) {
            $this->set[] = ['url_web_only', $on];
        });
        return new SettingsController('metavox', $request, $ai, $search, $links);
    }

    public function testShowsTheSwitches(): void {
        $this->assertSame(
            ['ai_enabled' => true, 'url_web_only' => false, 'url_blocked_domains' => ['ru'], 'url_allowed_domains' => [], 'search_filter' => true, 'search_folders' => false],
            $this->controller()->get()->getData()['settings'],
        );
    }

    public function testSavesOnlyWhatWasSent(): void {
        $this->params = ['search_folders' => true];
        $this->controller()->save();

        $this->assertSame([['search_folders', true]], $this->set);
    }

    public function testReadsTheWaysAFormSaysOnAndOff(): void {
        $this->params = ['search_filter' => 'true', 'search_folders' => '0'];
        $this->controller()->save();

        $this->assertSame([['search_filter', true], ['search_folders', false]], $this->set);
    }

    public function testSavesWhatAUrlFieldTakes(): void {
        $this->params = ['url_web_only' => 'true', 'url_blocked_domains' => ".ru\nexample.com", 'url_allowed_domains' => ''];
        $this->controller()->save();

        $this->assertSame([['url_web_only', true], ['url_blocked_domains', ['ru', 'example.com']], ['url_allowed_domains', []]], $this->set);
    }

    public function testRefusesWhatIsNoYesOrNo(): void {
        $this->params = ['search_filter' => 'on'];
        try {
            $this->controller()->save();
            $this->fail('"on" was read as a yes or no');
        } catch (ApiValidationException $e) {
            $this->assertSame(['search_filter' => 'Use true or false'], $e->getErrors());
        }
        $this->assertSame([], $this->set);
    }

    public function testOneRefusedSettingSavesNone(): void {
        $this->params = ['url_web_only' => 'true', 'search_folders' => 'true', 'url_blocked_domains' => 'not a domain!'];
        try {
            $this->controller()->save();
            $this->fail('A list that is no domain was saved');
        } catch (ApiValidationException) {
        }
        $this->assertSame([], $this->set);
    }

    public function testRefusesWhatIsNoDomain(): void {
        $this->params = ['url_blocked_domains' => 'not a domain!'];
        try {
            $this->controller()->save();
            $this->fail('A list that is no domain was saved');
        } catch (ApiValidationException $e) {
            $this->assertSame(['url_blocked_domains' => 'Enter domains such as example.com or .ru, one per line'], $e->getErrors());
        }
    }
}
