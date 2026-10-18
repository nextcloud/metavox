<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Controller;

use OCA\MetaVox\Controller\MetadataSearchController;
use OCA\MetaVox\Exception\RequestException;
use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\SavedSearchService;
use OCA\MetaVox\Service\SearchSettings;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** The routes of the saved searches: the caller's own, the frontend's parameter names. */
class MetadataSearchControllerTest extends TestCase {

    /** @var array<string, mixed> */
    private array $params = [];

    private SavedSearchService&MockObject $searches;

    private bool $foldersOn = true;

    protected function setUp(): void {
        $this->searches = $this->createMock(SavedSearchService::class);
    }

    private function controller(): MetadataSearchController {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => $this->params[$key] ?? $default);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);
        $settings = $this->createMock(SearchSettings::class);
        $settings->method('foldersOn')->willReturnCallback(fn () => $this->foldersOn);
        return new MetadataSearchController('metavox', $request, $session, $this->createMock(FieldService::class), $this->searches, $settings);
    }

    public function testListsTheirOwn(): void {
        $list = [['id' => 1, 'name' => 'Concept', 'field' => 'file_gf_status', 'value' => 'Concept', 'groupfolderId' => 7]];
        $this->searches->expects($this->once())->method('all')->with('alice')->willReturn($list);

        $this->assertSame(['searches' => $list], $this->controller()->index()->getData());
    }

    public function testCreatesWithTheFrontendsNames(): void {
        $this->params = ['name' => 'Concept', 'field' => 'file_gf_status', 'value' => 'Concept', 'groupfolderId' => '7'];
        $saved = ['id' => 3, 'name' => 'Concept', 'field' => 'file_gf_status', 'value' => 'Concept', 'groupfolderId' => 7];
        $this->searches->expects($this->once())->method('add')
            ->with('alice', 'Concept', 'file_gf_status', 'Concept', '7')
            ->willReturn($saved);

        $response = $this->controller()->create();

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
        $this->assertSame($saved, $response->getData());
    }

    public function testRemovesTheirOwn(): void {
        $this->searches->expects($this->once())->method('remove')->with('alice', 3)->willReturn(true);

        $this->assertSame(Http::STATUS_OK, $this->controller()->destroy(3)->getStatus());
    }

    public function testRemovingOneTheyDoNotHaveIsNotFound(): void {
        $this->searches->method('remove')->willReturn(false);

        try {
            $this->controller()->destroy(99);
            $this->fail('removed');
        } catch (RequestException $e) {
            $this->assertSame(Http::STATUS_NOT_FOUND, $e->getStatus());
        }
    }

    public function testSavingIsRefusedWhenTheAdminTurnedFoldersOff(): void {
        $this->foldersOn = false;
        $this->params = ['name' => 'Concept', 'field' => 'file_gf_status', 'value' => 'Concept'];
        $this->searches->expects($this->never())->method('add');

        try {
            $this->controller()->create();
            $this->fail('saved');
        } catch (RequestException $e) {
            $this->assertSame([Http::STATUS_FORBIDDEN, 'turned_off'], [$e->getStatus(), $e->getReason()]);
        }
    }

    public function testListingAndRemovingStillWorkWhenFoldersAreOff(): void {
        // So nobody is left with searches they can't clean up.
        $this->foldersOn = false;
        $this->searches->method('all')->willReturn([]);
        $this->searches->method('remove')->willReturn(true);

        $this->assertSame(['searches' => []], $this->controller()->index()->getData());
        $this->assertSame(Http::STATUS_OK, $this->controller()->destroy(3)->getStatus());
    }
}
