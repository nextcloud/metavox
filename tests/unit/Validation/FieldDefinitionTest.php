<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Validation;

use OCA\MetaVox\Service\ApiValidationException;
use OCA\MetaVox\Service\FieldName;
use OCA\MetaVox\Validation\FieldDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FieldDefinitionTest extends TestCase {

    private const NEW = ['field_name' => 'file_gf_status', 'field_label' => 'Status', 'field_type' => 'select', 'field_options' => "Open\nClosed"];

    private static function refusal(array $sent, ?array $current = null): array {
        try {
            FieldDefinition::stored($sent, $current);
        } catch (ApiValidationException $e) {
            return $e->getErrors();
        }
        self::fail('Accepted ' . json_encode($sent));
    }

    public function testANewFieldInItsStoredForm(): void {
        $this->assertSame([
            'field_name' => 'file_gf_status',
            'field_label' => 'Status',
            'field_type' => 'select',
            'field_description' => '',
            'field_options' => '["Open","Closed"]',
            'is_required' => false,
            'sort_order' => 0,
            'applies_to_groupfolder' => 0,
        ], FieldDefinition::stored(self::NEW));
    }

    /** What the options came as: the admin page's text, the import's list, the API's objects. */
    public static function options(): array {
        return [
            'text, one per line' => ["Open\nClosed", '["Open","Closed"]'],
            'a list' => [['Open', 'Closed'], '["Open","Closed"]'],
            'values only' => [[['value' => 'Open'], ['value' => 'Closed']], '["Open","Closed"]'],
            'labels kept' => [[['value' => 'o', 'label' => 'Open']], '[{"value":"o","label":"Open"}]'],
            'doubles and spaces' => [[' Open ', 'Open', ''], '["Open"]'],
        ];
    }

    #[DataProvider('options')]
    public function testOptionsAreStoredInOneForm(mixed $options, string $stored): void {
        $this->assertSame($stored, FieldDefinition::stored(['field_options' => $options] + self::NEW)['field_options']);
    }

    public function testAChoiceFieldNeedsOptions(): void {
        foreach (['', [], [''], null] as $none) {
            $this->assertSame(['field_options' => 'Add at least one option'], self::refusal(['field_options' => $none] + self::NEW), json_encode($none));
        }
        $this->assertSame(['field_options' => 'An option cannot contain ;#'], self::refusal(['field_options' => ['A;#B']] + self::NEW));
    }

    public function testTypes(): void {
        foreach (['dropdown' => 'select', 'multi_select' => 'multiselect', 'boolean' => 'checkbox', 'usergroup' => 'user', 'TEXT' => 'text'] as $sent => $type) {
            $this->assertSame($type, FieldDefinition::stored(['field_type' => $sent] + self::NEW)['field_type'], $sent);
        }
        $this->assertSame(['field_type' => 'Choose one of the field types'], self::refusal(['field_type' => 'colour'] + self::NEW));
        $this->assertSame('text', FieldDefinition::stored(['field_name' => 'file_gf_a', 'field_label' => 'A'])['field_type'], 'text when left out');
    }

    public function testOptionsOfOtherTypes(): void {
        $date = ['field_type' => 'date'] + self::NEW;
        $this->assertSame('{"includeTime":true}', FieldDefinition::stored(['field_options' => ['includeTime' => 'true']] + $date)['field_options']);
        $this->assertSame('{"includeTime":false}', FieldDefinition::stored(['field_options' => ''] + $date)['field_options']);
        $this->assertArrayHasKey('field_options', self::refusal(['field_options' => ['includeTime' => 'maybe']] + $date));
        $this->assertArrayHasKey('field_options', self::refusal(['field_options' => ['A']] + $date));
        $this->assertSame('[]', FieldDefinition::stored(['field_type' => 'text'] + self::NEW)['field_options'], 'a text field has none');
    }

    public function testLabelNameAndTheRest(): void {
        $this->assertSame(['field_label' => 'Enter a label'], self::refusal(['field_label' => '  '] + self::NEW));
        $this->assertSame(['field_label' => 'This text is too long'], self::refusal(['field_label' => str_repeat('x', 256)] + self::NEW));
        $this->assertSame(['field_name' => FieldName::FILE_MESSAGE], self::refusal(['field_name' => 'status'] + self::NEW));
        $this->assertSame(['field_name' => FieldName::FOLDER_MESSAGE], self::refusal(['applies_to_groupfolder' => 1] + self::NEW));
        $this->assertSame(['is_required' => 'Use true or false'], self::refusal(['is_required' => 'on'] + self::NEW));
        $this->assertSame(['sort_order' => 'Enter a whole number'], self::refusal(['sort_order' => '1abc'] + self::NEW));
        $this->assertTrue(FieldDefinition::stored(['is_required' => '1'] + self::NEW)['is_required']);
    }

    public function testACheckboxIsStoredAsNotRequired(): void {
        // Sent required, as a 2.x export or template does: it imports, without the flag.
        $checkbox = ['field_type' => 'checkbox', 'field_options' => [], 'is_required' => true] + self::NEW;
        $this->assertFalse(FieldDefinition::stored($checkbox)['is_required']);
        $this->assertFalse(FieldDefinition::stored(['field_type' => 'boolean'] + $checkbox)['is_required']);

        // A stored checkbox that still carries the flag loses it on its next change.
        $current = ['field_name' => 'file_gf_ok', 'field_label' => 'OK', 'field_type' => 'checkbox', 'field_description' => '',
            'field_options' => '[]', 'is_required' => 1, 'sort_order' => 0, 'applies_to_groupfolder' => 0];
        $this->assertFalse(FieldDefinition::stored(['field_label' => 'Approved'], $current)['is_required']);
    }

    public function testEverythingRefusedAtOnce(): void {
        $this->assertEqualsCanonicalizing(['field_name', 'field_label', 'field_type'], array_keys(self::refusal(['field_name' => '', 'field_label' => '', 'field_type' => 'x'])));
    }

    public function testAChangeKeepsWhatIsLeftOut(): void {
        $current = ['field_name' => 'status', 'field_label' => 'Status', 'field_type' => 'dropdown', 'field_description' => 'd',
            'field_options' => '"Open\nClosed"', 'is_required' => 1, 'sort_order' => 3, 'applies_to_groupfolder' => 0];

        $field = FieldDefinition::stored(['field_label' => 'State'], $current);

        // An old name without its prefix stays; stored options stay as they are.
        $this->assertSame(['status', 'State', 'select', 'd', '"Open\nClosed"', true, 3, 0], array_values($field));
    }

    public function testAStoredTypeMetaVoxNoLongerKnowsStays(): void {
        $current = ['field_name' => 'file_gf_x', 'field_label' => 'X', 'field_type' => 'colour', 'field_options' => '[]', 'is_required' => 0, 'sort_order' => 0, 'applies_to_groupfolder' => 0];
        $this->assertSame('colour', FieldDefinition::stored(['field_label' => 'Y'], $current)['field_type']);
    }
}
