<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Service\ApiValidationException;
use OCA\MetaVox\Service\FieldName;
use PHPUnit\Framework\TestCase;

class FieldNameTest extends TestCase {

    public function testNamesOfEachKind(): void {
        foreach (['file_gf_department', 'file_gf_a', 'file_gf_due_2026'] as $name) {
            FieldName::check($name, false);
        }
        foreach (['gf_classification', 'gf_a1'] as $name) {
            FieldName::check($name, true);
        }
        $this->addToAssertionCount(5);
    }

    public function testRefusedNames(): void {
        $refused = [
            ['department', false], ['gf_department', false], ['file_gf_', false], ['file_gf_Department', false],
            ['file_gf_my field', false], ['file_gf_1st', false], ['file_gf__x', false], ["file_gf_x\n", false],
            [' file_gf_x', false], ['file_gf_x-y', false], ['file_gf_é', false], [null, false], [12, false],
            ['file_gf_department', true], ['classification', true], ['gf_', true],
        ];
        foreach ($refused as [$name, $folderLevel]) {
            try {
                FieldName::check($name, $folderLevel);
                $this->fail('Expected a refusal for ' . json_encode($name));
            } catch (ApiValidationException $e) {
                $this->assertSame(['field_name' => $folderLevel ? FieldName::FOLDER_MESSAGE : FieldName::FILE_MESSAGE], $e->getErrors());
            }
        }
    }

    public function testTheKindAsStored(): void {
        $this->assertTrue(FieldName::isFolderLevel(1));
        $this->assertTrue(FieldName::isFolderLevel(true));
        $this->assertTrue(FieldName::isFolderLevel('1'));
        $this->assertFalse(FieldName::isFolderLevel(0));
        $this->assertFalse(FieldName::isFolderLevel(false));
        $this->assertFalse(FieldName::isFolderLevel(null));
        $this->assertFalse(FieldName::isFolderLevel('0'));
    }
}
