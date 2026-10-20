<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Validation\LinkSettings;
use OCA\MetaVox\Service\AccountService;
use OCA\MetaVox\Validation\FieldValue;
use OCA\MetaVox\Service\MetadataVersions;
use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\FileReferenceService;
use OCA\MetaVox\Service\PushService;
use OCA\MetaVox\Service\SearchWordsService;
use OCA\MetaVox\Service\ViewService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FieldServiceCatalogueTest extends TestCase {

    private ?array $askedFolders = null;

    /** Fields 3, 4 and 5; folders 7 and 9 together use fields 3 and 5. */
    private function service(): FieldService {
        $cache = $this->createMock(ICache::class);
        $cache->method('get')->with('fields_all')->willReturn([['id' => 3], ['id' => 4], ['id' => 5]]);
        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($cache);

        $result = $this->createMock(IResult::class);
        $result->method('fetchOne')->willReturnOnConsecutiveCalls(3, 5, false);
        $qb = $this->createMock(IQueryBuilder::class);
        foreach (['selectDistinct', 'from', 'where'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
        $qb->method('createNamedParameter')->willReturnCallback(function ($value) {
            $this->askedFolders = $value;
            return ':folders';
        });
        $qb->method('executeQuery')->willReturn($result);
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($qb);

        return new FieldService($db, $this->createMock(IGroupManager::class), $this->createMock(IUserManager::class),
            $this->createMock(SearchWordsService::class), $this->createMock(ViewService::class), $this->createMock(PushService::class),
            $cacheFactory, $this->createMock(LoggerInterface::class), $this->createMock(FileReferenceService::class), $this->createMock(MetadataVersions::class), new FieldValue($this->createMock(AccountService::class), $this->createMock(LinkSettings::class)));
    }

    public function testNullMeansEveryField(): void {
        $this->assertSame([3, 4, 5], array_column($this->service()->getCatalogue(null), 'id'));
    }

    public function testOnlyTheFieldsOfTheGivenTeamFolders(): void {
        $this->assertSame([3, 5], array_column($this->service()->getCatalogue([7, 9]), 'id'));
        $this->assertSame([7, 9], $this->askedFolders);
    }

    public function testNoTeamFoldersMeansNoFields(): void {
        $this->assertSame([], $this->service()->getCatalogue([]));
    }
}
