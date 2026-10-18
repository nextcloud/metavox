<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Validation\LinkSettings;
use OCA\MetaVox\Validation\FieldValue;
use OCA\MetaVox\Service\MetadataVersions;
use OCA\MetaVox\Service\ApiValidationException;
use OCA\MetaVox\Service\FieldName;
use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\FileReferenceService;
use OCA\MetaVox\Service\PushService;
use OCA\MetaVox\Service\SearchWordsService;
use OCA\MetaVox\Service\ViewService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FieldServiceCreateFieldTest extends TestCase {

    public function testDuplicateNameIsAValidationError(): void {
        $service = $this->service(existing: 12);

        try {
            $service->createField(['field_name' => 'file_gf_status', 'field_label' => 'Status', 'field_type' => 'text']);
            $this->fail('Expected a validation error');
        } catch (ApiValidationException $e) {
            $this->assertSame(['field_name' => 'A field with this name already exists'], $e->getErrors());
        }
    }

    /** Before anything is asked of the database. */
    public function testANameWithoutThePrefixOfItsKindIsRefused(): void {
        $service = $this->service(existing: false, queries: 0);
        foreach ([
            [['field_name' => 'status'], FieldName::FILE_MESSAGE],
            [['field_name' => 'gf_status'], FieldName::FILE_MESSAGE],
            [['field_name' => 'file_gf_status', 'applies_to_groupfolder' => 1], FieldName::FOLDER_MESSAGE],
            [['field_name' => null], 'This field is required'],
        ] as [$field, $message]) {
            try {
                $service->createField($field + ['field_label' => 'Status', 'field_type' => 'text']);
                $this->fail('Expected a validation error for ' . json_encode($field));
            } catch (ApiValidationException $e) {
                $this->assertSame(['field_name' => $message], $e->getErrors());
            }
        }
    }

    private function service(int|false $existing, ?int $queries = null): FieldService {
        $result = $this->createMock(IResult::class);
        $result->method('fetchOne')->willReturn($existing);
        $qb = $this->createMock(IQueryBuilder::class);
        foreach (['select', 'from', 'where'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
        $qb->method('executeQuery')->willReturn($result);
        $db = $this->createMock(IDBConnection::class);
        if ($queries === null) {
            $db->method('getQueryBuilder')->willReturn($qb);
        } else {
            $db->expects($this->exactly($queries))->method('getQueryBuilder')->willReturn($qb);
        }

        return new FieldService(
            $db,
            $this->createMock(IGroupManager::class),
            $this->createMock(IUserManager::class),
            $this->createMock(SearchWordsService::class),
            $this->createMock(ViewService::class),
            $this->createMock(PushService::class),
            $this->createMock(ICacheFactory::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(FileReferenceService::class),
            $this->createMock(MetadataVersions::class), new FieldValue($this->createMock(IUserManager::class), $this->createMock(LinkSettings::class)),
        );
    }
}
