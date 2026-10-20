<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\FileReferenceService;
use OCA\MetaVox\Service\MetadataVersions;
use OCA\MetaVox\Service\PushService;
use OCA\MetaVox\Service\SearchWordsService;
use OCA\MetaVox\Service\ViewService;
use OCA\MetaVox\Service\AccountService;
use OCA\MetaVox\Validation\FieldValue;
use OCA\MetaVox\Validation\LinkSettings;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What the API says about "required" is what holds: a checkbox that still
 * carries the flag 2.x let an admin set is read as not required.
 */
class FieldServiceRequiredFlagTest extends TestCase {

    public function testAStoredFlagOnACheckboxIsReadAsNotRequired(): void {
        $rows = [
            ['id' => 1, 'field_name' => 'file_gf_code', 'field_label' => 'Code', 'field_type' => 'text', 'is_required' => '1', 'sort_order' => 0],
            ['id' => 2, 'field_name' => 'file_gf_pii', 'field_label' => 'PII', 'field_type' => 'checkbox', 'is_required' => '1', 'sort_order' => 1],
            ['id' => 3, 'field_name' => 'file_gf_old', 'field_label' => 'Old', 'field_type' => 'boolean', 'is_required' => '1', 'sort_order' => 2],
            ['id' => 4, 'field_name' => 'file_gf_note', 'field_label' => 'Note', 'field_type' => 'text', 'is_required' => '0', 'sort_order' => 3],
        ];

        $cache = $this->createMock(ICache::class);
        $cache->method('get')->willReturn(null);
        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($cache);

        $result = $this->createMock(IResult::class);
        $result->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows, false]);
        $qb = $this->createMock(IQueryBuilder::class);
        foreach (['select', 'from', 'orderBy', 'addOrderBy'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('executeQuery')->willReturn($result);
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($qb);

        $service = new FieldService($db, $this->createMock(IGroupManager::class), $this->createMock(IUserManager::class),
            $this->createMock(SearchWordsService::class), $this->createMock(ViewService::class), $this->createMock(PushService::class),
            $cacheFactory, $this->createMock(LoggerInterface::class), $this->createMock(FileReferenceService::class), $this->createMock(MetadataVersions::class),
            new FieldValue($this->createMock(AccountService::class), $this->createMock(LinkSettings::class)));

        $this->assertSame([1 => true, 2 => false, 3 => false, 4 => false],
            array_column($service->getAllFields(), 'is_required', 'id'));
    }
}
