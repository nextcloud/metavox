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

/** A field in use (assigned to a team folder) keeps its name and type, and can't be deleted; a change sends only what changes. */
class FieldServiceInUseTest extends TestCase {

    private const FIELD = ['field_name' => 'file_gf_status', 'field_label' => 'Status', 'field_type' => 'text', 'is_required' => false];

    private int $writes = 0;
    /** @var array<string, mixed> what the last update set */
    private array $set = [];
    private ?string $joined = null;
    private ?SearchWordsService $words = null;

    /** $inUse: assigned to an existing team folder. $taken: another field already has the new name. $stored: the field as it is. */
    private function service(bool $inUse, bool $taken = false, array $stored = ['field_name' => 'file_gf_status', 'field_label' => 'Status', 'field_type' => 'text', 'is_required' => 0, 'applies_to_groupfolder' => 0]): FieldService {
        $table = null;
        $qb = $this->createMock(IQueryBuilder::class);
        foreach (['select', 'where', 'andWhere', 'update', 'delete', 'setMaxResults'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('createNamedParameter')->willReturnCallback(fn ($value) => $value);
        $qb->method('set')->willReturnCallback(function ($column, $value) use ($qb) {
            $this->set[$column] = $value;
            return $qb;
        });
        $qb->method('from')->willReturnCallback(function ($from) use (&$table, $qb) {
            $table = $from;
            return $qb;
        });
        $qb->method('innerJoin')->willReturnCallback(function ($from, $join) use ($qb) {
            $this->joined = $join;
            return $qb;
        });
        $qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
        $qb->method('executeQuery')->willReturnCallback(function () use (&$table, $inUse, $taken, $stored) {
            $result = $this->createMock(IResult::class);
            if ($table === 'metavox_gf_assigns') {
                $result->method('fetchOne')->willReturn($inUse ? 3 : false);
            } else {
                $result->method('fetch')->willReturn($stored);
                $result->method('fetchOne')->willReturn($taken ? 12 : false);
            }
            return $result;
        });
        $qb->method('executeStatement')->willReturnCallback(function () {
            $this->writes++;
            return 1;
        });
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($qb);

        return new FieldService(
            $db,
            $this->createMock(IGroupManager::class),
            $this->createMock(IUserManager::class),
            $this->words ?? $this->createMock(SearchWordsService::class),
            $this->createMock(ViewService::class),
            $this->createMock(PushService::class),
            $this->createMock(ICacheFactory::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(FileReferenceService::class),
            $this->createMock(MetadataVersions::class), new FieldValue($this->createMock(IUserManager::class), $this->createMock(LinkSettings::class)),
        );
    }

    private function assertRefused(array $errors, callable $call): void {
        try {
            $call();
            $this->fail('Expected a validation error');
        } catch (ApiValidationException $e) {
            $this->assertSame($errors, $e->getErrors());
        }
        $this->assertSame(0, $this->writes, 'nothing may be written');
    }

    public function testRenamingAFieldInUseIsRefused(): void {
        $service = $this->service(inUse: true);
        $this->assertRefused(['field_name' => 'Internal name cannot be changed when field is in use'],
            fn () => $service->updateField(5, ['field_name' => 'file_gf_state'] + self::FIELD));
    }

    /** Files without a value then show as missing one. */
    public function testAFieldInUseMayBecomeRequired(): void {
        $this->assertTrue($this->service(inUse: true)->updateField(5, ['is_required' => true] + self::FIELD));
        $this->assertSame(1, $this->set['is_required']);
    }

    public function testOtherChangesToAFieldInUseAreSaved(): void {
        $this->assertTrue($this->service(inUse: true)->updateField(5, ['field_label' => 'State'] + self::FIELD));
        $this->assertSame(1, $this->writes);
    }

    /** Its values are stored in the form of the type, as the admin page already said. */
    public function testANewTypeForAFieldInUseIsRefused(): void {
        $service = $this->service(inUse: true, stored: ['field_name' => 'file_gf_status', 'field_label' => 'Status', 'field_type' => 'select', 'field_options' => '["A"]', 'is_required' => 0, 'applies_to_groupfolder' => 0]);
        $this->assertRefused(['field_type' => 'Field type cannot be changed when field is in use'],
            fn () => $service->updateField(5, ['field_type' => 'multiselect']));
    }

    public function testAnOldNameForATypeIsNoNewType(): void {
        $service = $this->service(inUse: true, stored: ['field_name' => 'file_gf_status', 'field_label' => 'Status', 'field_type' => 'dropdown', 'field_options' => '["A"]', 'is_required' => 0, 'applies_to_groupfolder' => 0]);
        $this->assertTrue($service->updateField(5, ['field_type' => 'select', 'field_label' => 'State']));
        $this->assertSame('select', $this->set['field_type']);
    }

    public function testAnUnusedFieldMayGetAnotherType(): void {
        $this->assertTrue($this->service(inUse: false)->updateField(5, ['field_type' => 'select', 'field_options' => ['A', 'B']]));
        $this->assertSame(['select', '["A","B"]'], [$this->set['field_type'], $this->set['field_options']]);
    }

    /** A partial change: an OCS PUT with a new label turned a dropdown into text and wiped its options. */
    public function testWhatIsLeftOutStaysAsItIs(): void {
        $service = $this->service(inUse: true, stored: ['field_name' => 'file_gf_status', 'field_label' => 'Status', 'field_type' => 'select', 'field_description' => 'Where it is',
            'field_options' => '["Open","Closed"]', 'is_required' => 1, 'sort_order' => 4, 'applies_to_groupfolder' => 0]);

        $this->assertTrue($service->updateField(5, ['field_label' => 'State']));
        $this->assertSame(['file_gf_status', 'State', 'select', 'Where it is', '["Open","Closed"]', 1, 4, 0],
            [$this->set['field_name'], $this->set['field_label'], $this->set['field_type'], $this->set['field_description'],
             $this->set['field_options'], $this->set['is_required'], $this->set['sort_order'], $this->set['applies_to_groupfolder']]);
    }

    public function testTheSameTypeLeavesTheSearchIndexAlone(): void {
        $this->words = $this->createMock(SearchWordsService::class);
        $this->words->expects($this->never())->method('rebuildAll');
        $service = $this->service(inUse: true);

        $this->assertTrue($service->updateField(5, ['field_label' => 'State'] + self::FIELD));
    }

    public function testRenamingAnUnusedFieldIsSaved(): void {
        $this->assertTrue($this->service(inUse: false)->updateField(5, ['field_name' => 'file_gf_state'] + self::FIELD));
    }

    public function testRenamingToATakenNameIsRefused(): void {
        $service = $this->service(inUse: false, taken: true);
        $this->assertRefused(['field_name' => 'A field with this name already exists'],
            fn () => $service->updateField(5, ['field_name' => 'file_gf_state'] + self::FIELD));
    }

    public function testRenamingToANameWithoutItsPrefixIsRefused(): void {
        $service = $this->service(inUse: false);
        $this->assertRefused(['field_name' => FieldName::FILE_MESSAGE],
            fn () => $service->updateField(5, ['field_name' => 'state'] + self::FIELD));
    }

    public function testAnOldNameWithoutPrefixMayStayWhileOtherThingsChange(): void {
        $service = $this->service(inUse: true, stored: ['field_name' => 'status', 'field_label' => 'Status', 'field_type' => 'text', 'is_required' => 0, 'applies_to_groupfolder' => 0]);
        $this->assertTrue($service->updateField(5, ['field_name' => 'status', 'field_label' => 'State'] + self::FIELD));
    }

    public function testAnotherKindNeedsTheNameOfThatKind(): void {
        $service = $this->service(inUse: false);
        $this->assertRefused(['field_name' => FieldName::FOLDER_MESSAGE],
            fn () => $service->updateField(5, ['applies_to_groupfolder' => 1] + self::FIELD));
    }

    public function testLeavingTheKindOutKeepsIt(): void {
        $service = $this->service(inUse: false, stored: ['field_name' => 'gf_status', 'field_label' => 'Status', 'field_type' => 'text', 'is_required' => 0, 'applies_to_groupfolder' => 1]);
        $this->assertTrue($service->updateField(5, ['field_name' => 'gf_status'] + self::FIELD));
    }

    public function testDeletingAFieldInUseIsRefused(): void {
        $service = $this->service(inUse: true);
        $this->assertRefused(['field' => 'Cannot delete (in use)'], fn () => $service->deleteField(5));
    }

    public function testInUseOnlyCountsTeamFoldersThatStillExist(): void {
        $this->service(inUse: true)->isFieldInUse(5);
        $this->assertSame('group_folders', $this->joined);
    }
}
