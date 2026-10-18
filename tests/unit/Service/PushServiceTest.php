<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Service\PresenceService;
use OCA\MetaVox\Service\PushService;
use PHPUnit\Framework\TestCase;

/** Every member gets push events, so they carry ids and never values. */
class PushServiceTest extends TestCase {

    private object $queue;

    private function service(array $present = ['alice']): PushService {
        $presence = $this->createMock(PresenceService::class);
        $presence->method('getActiveViewers')->willReturn($present);
        $service = new PushService($presence);

        // notify_push's queue, recording what would be sent.
        $this->queue = new class {
            public array $sent = [];

            public function push(string $channel, array $message): void {
                $this->sent[] = $message;
            }
        };
        (new \ReflectionProperty(PushService::class, 'notifyQueue'))->setValue($service, $this->queue);
        return $service;
    }

    /** @return list<array> the bodies sent */
    private function bodies(): array {
        return array_column($this->queue->sent, 'body');
    }

    public function testAChangeSendsOnlyIds(): void {
        $service = $this->service();
        $service->metadataChanged(5, 10);
        $service->flushPendingChanges();

        $this->assertSame([['gfId' => 5, 'fileIds' => [10]]], $this->bodies());
        $this->assertSame('metavox_metadata_changed', $this->queue->sent[0]['message']);
        $this->assertSame('alice', $this->queue->sent[0]['user']);
    }

    public function testSeveralFieldsOfOneFileAreOneId(): void {
        $service = $this->service();
        $service->metadataChanged(5, 10);
        $service->metadataChanged(5, 10);
        $service->flushPendingChanges();

        $this->assertSame([['gfId' => 5, 'fileIds' => [10]]], $this->bodies());
    }

    public function testSeveralFilesGoInOneEventPerTeamFolder(): void {
        $service = $this->service();
        $service->metadataChanged(5, 10);
        $service->metadataChanged(5, 11);
        $service->metadataChanged(6, 20);
        $service->flushPendingChanges();

        $this->assertSame([['gfId' => 5, 'fileIds' => [10, 11]], ['gfId' => 6, 'fileIds' => [20]]], $this->bodies());
    }

    public function testAFlushSendsEachChangeOnce(): void {
        $service = $this->service();
        $service->metadataChanged(5, 10);
        $service->flushPendingChanges();
        $service->flushPendingChanges();

        $this->assertCount(1, $this->queue->sent);
    }

    public function testOnlyWhoHasTheGridOpen(): void {
        $service = $this->service(['alice', 'bob']);
        $service->metadataChanged(5, 10);
        $service->flushPendingChanges();

        $this->assertSame(['alice', 'bob'], array_column($this->queue->sent, 'user'));
    }

    public function testNobodyThereNoPush(): void {
        $service = $this->service([]);
        $service->metadataChanged(5, 10);
        $service->flushPendingChanges();
        $service->cellLocked(5, 10, 'status', 'bob');

        $this->assertSame([], $this->queue->sent);
    }

    public function testLockEventsCarryNoValues(): void {
        $service = $this->service();
        $service->cellLocked(5, 10, 'status', 'bob');
        $service->cellUnlocked(5, 10, 'status');

        $this->assertSame([
            ['gfId' => 5, 'fileId' => 10, 'fieldName' => 'status', 'userId' => 'bob'],
            ['gfId' => 5, 'fileId' => 10, 'fieldName' => 'status'],
        ], $this->bodies());
    }
}
