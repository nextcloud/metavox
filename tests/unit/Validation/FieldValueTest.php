<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Validation;

use OCA\MetaVox\Service\AccountService;
use OCA\MetaVox\Validation\FieldValue;
use OCA\MetaVox\Validation\InvalidValue;
use OCA\MetaVox\Validation\LinkSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FieldValueTest extends TestCase {

    private function values(bool $webOnly = false, array $blocked = [], array $allowed = []): FieldValue {
        $links = $this->createMock(LinkSettings::class);
        $links->method('webOnly')->willReturn($webOnly);
        $links->method('blockedDomains')->willReturn($blocked);
        $links->method('allowedDomains')->willReturn($allowed);
        return new FieldValue($this->createMock(AccountService::class), $links);
    }

    private function linkRefusal(FieldValue $values, string $address): ?string {
        try {
            $values->stored(self::field('url'), $address);
            return null;
        } catch (InvalidValue $e) {
            return $e->getMessage();
        }
    }

    private static function field(string $type, mixed $options = null, bool $required = false): array {
        return ['field_name' => 'f', 'field_type' => $type, 'field_options' => $options, 'is_required' => $required];
    }

    /** Input a field takes, and the form it is stored in. */
    public static function accepted(): array {
        $select = ['Draft', 'Final', 'Approved'];
        return [
            'text' => ['text', null, 'Hello', 'Hello'],
            'text keeps its spaces' => ['text', null, ' Hello ', ' Hello '],
            'text from a number' => ['text', null, 12, '12'],
            'long text with lines' => ['textarea', null, "a\nb", "a\nb"],
            'long text with tabs and returns' => ['textarea', null, "a\tb\r\nc", "a\tb\r\nc"],
            'nothing is no value' => ['text', null, null, ''],
            'number' => ['number', null, '12', '12'],
            'number with a point' => ['number', null, '3.5', '3.5'],
            'negative number' => ['number', null, '-0.25', '-0.25'],
            'number with spaces' => ['number', null, ' 7 ', '7'],
            'number as JSON' => ['number', null, 3.5, '3.5'],
            'whole number as JSON' => ['number', null, 42, '42'],
            'empty number' => ['number', null, '', ''],
            'date' => ['date', null, '2026-02-28', '2026-02-28'],
            'leap day' => ['date', null, '2028-02-29', '2028-02-29'],
            'date and time' => ['date', ['includeTime' => true], '2026-02-28T09:30:00', '2026-02-28T09:30:00'],
            'date and time without seconds' => ['date', ['includeTime' => true], '2026-02-28T09:30', '2026-02-28T09:30:00'],
            'empty date' => ['date', null, '', ''],
            'option' => ['select', $select, 'Final', 'Final'],
            'option in other case' => ['select', $select, 'final', 'Final'],
            'option with label' => ['select', [['value' => 'd', 'label' => 'Draft']], 'd', 'd'],
            'dropdown alias' => ['dropdown', $select, 'Draft', 'Draft'],
            'options joined' => ['multiselect', $select, 'Draft;#Final', 'Draft;#Final'],
            'options as a list' => ['multiselect', $select, ['Draft', 'Final'], 'Draft;#Final'],
            'options trimmed, deduplicated, empties dropped' => ['multiselect', $select, ' Draft ;#;#draft;#Final', 'Draft;#Final'],
            'no options chosen' => ['multiselect', $select, [], ''],
            'ticked' => ['checkbox', null, '1', '1'],
            'ticked as JSON' => ['checkbox', null, true, '1'],
            'ticked as text' => ['checkbox', null, 'true', '1'],
            'not ticked' => ['checkbox', null, '0', '0'],
            'not ticked as JSON' => ['checkbox', null, false, '0'],
            'not ticked as a number' => ['checkbox', null, 0, '0'],
            'checkbox cleared' => ['checkbox', null, '', ''],
            'web address' => ['url', null, 'https://example.com/a?b=c', 'https://example.com/a?b=c'],
            'address without scheme' => ['url', null, 'example.com', 'example.com'],
            'email address' => ['url', null, 'mailto:info@example.com', 'mailto:info@example.com'],
            'other scheme' => ['url', null, 'tel:+31201234567', 'tel:+31201234567'],
            'account' => ['user', null, 'anna', 'anna'],
            'no account' => ['user', null, '', ''],
            // Whether they exist and may be chosen is checked on saving (AccountService).
            'people joined' => ['user', null, 'anna;#group:staff;#team:Ab12', 'anna;#group:staff;#team:Ab12'],
            'people as a list' => ['user', null, ['anna', 'group:staff'], 'anna;#group:staff'],
            'people trimmed, deduplicated, empties dropped' => ['user', null, ' anna ;#;#anna;#group:staff', 'anna;#group:staff'],
            'nobody chosen' => ['user', null, [], ''],
            'account id that is a number' => ['user', null, '123', '123'],
            'file link' => ['filelink', null, '12:/Docs/a.txt', '12:/Docs/a.txt'],
            'file links as a list' => ['filelink', null, ['12:', '13:'], '12:;#13:'],
            'file link as a number' => ['filelink', null, 12, '12'],
        ];
    }

    #[DataProvider('accepted')]
    public function testAccepted(string $type, mixed $options, mixed $value, string $stored): void {
        $this->assertSame($stored, $this->values()->stored(self::field($type, $options), $value));
    }

    /** Input a field refuses, and what it says. */
    public static function refused(): array {
        $select = ['Draft', 'Final'];
        return [
            'text as a list' => ['text', null, ['a'], 'Enter text'],
            'text as yes/no' => ['text', null, true, 'Enter text'],
            'text that is not UTF-8' => ['text', null, "\xC3\x28", 'Enter text'],
            'text with a NUL character' => ['text', null, "nul \0 byte", 'This text contains a character that cannot be saved'],
            'long text with a NUL character' => ['textarea', null, "a\0", 'This text contains a character that cannot be saved'],
            'number as words' => ['number', null, 'abc', 'Enter a number, such as 12 or 3.5'],
            'number with a comma' => ['number', null, '1,5', 'Enter a number, such as 12 or 3.5'],
            'number too large' => ['number', null, '1e999', 'Enter a number, such as 12 or 3.5'],
            'number as yes/no' => ['number', null, true, 'Enter a number, such as 12 or 3.5'],
            'number as a list' => ['number', null, [1], 'Enter a number, such as 12 or 3.5'],
            'day that does not exist' => ['date', null, '2026-02-30', 'Enter a date as YYYY-MM-DD'],
            'month that does not exist' => ['date', null, '2026-13-01', 'Enter a date as YYYY-MM-DD'],
            'date in words' => ['date', null, 'tomorrow', 'Enter a date as YYYY-MM-DD'],
            'date in another format' => ['date', null, '15/04/2026', 'Enter a date as YYYY-MM-DD'],
            'time on a date-only field' => ['date', null, '2026-02-28T09:30:00', 'Enter a date as YYYY-MM-DD'],
            'date without time on a date-and-time field' => ['date', ['includeTime' => true], '2026-02-28', 'Enter a date and time as YYYY-MM-DDTHH:MM:SS'],
            'hour that does not exist' => ['date', ['includeTime' => true], '2026-02-28T24:00:00', 'Enter a date and time as YYYY-MM-DDTHH:MM:SS'],
            'option that is not there' => ['select', $select, 'Archived', 'Choose one of the options of this field'],
            'option as a list' => ['select', $select, ['Draft'], 'Choose one of the options of this field'],
            'field without options' => ['select', [], 'Draft', 'Choose one of the options of this field'],
            'one option that is not there' => ['multiselect', $select, 'Draft;#Archived', 'Choose only options of this field'],
            'options as an object' => ['multiselect', $select, ['a' => 'Draft'], 'Choose only options of this field'],
            'checkbox maybe' => ['checkbox', null, 'maybe', 'Use 1 for ticked or 0 for not ticked'],
            'checkbox yes' => ['checkbox', null, 'yes', 'Use 1 for ticked or 0 for not ticked'],
            'checkbox 2' => ['checkbox', null, 2, 'Use 1 for ticked or 0 for not ticked'],
            'javascript link' => ['url', null, 'javascript:alert(1)', 'This kind of link is not allowed'],
            'javascript link in capitals' => ['url', null, 'JavaScript:alert(1)', 'This kind of link is not allowed'],
            'javascript link with a space' => ['url', null, 'java script:alert(1)', 'This kind of link is not allowed'],
            'data link' => ['url', null, 'data:text/html,<b>x</b>', 'This kind of link is not allowed'],
            'link with a line break' => ['url', null, "java\nscript:alert(1)", 'Enter a web address'],
            'link as a list' => ['url', null, ['https://example.com'], 'Enter a web address'],
            'account with a colon' => ['user', null, 'other:anna', 'Choose an existing account'],
            'group without an id' => ['user', null, 'group:', 'Choose an existing account'],
            'account as an object' => ['user', null, ['a' => 'anna'], 'Choose an existing account'],
            'account as a number in a list' => ['user', null, [12], 'Choose an existing account'],
            'account with a line break' => ['user', null, "an
na", 'Choose an existing account'],
            'file link as an object' => ['filelink', null, ['a' => '12:'], 'Enter a link to a file'],
            'file link as yes/no' => ['filelink', null, true, 'Enter a link to a file'],
        ];
    }

    #[DataProvider('refused')]
    public function testRefused(string $type, mixed $options, mixed $value, string $message): void {
        try {
            $this->values()->stored(self::field($type, $options), $value);
            $this->fail('Accepted ' . var_export($value, true));
        } catch (InvalidValue $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    public function testTheAdministrationCanAllowOnlyWebAndEmailAddresses(): void {
        $field = self::field('url');
        $values = $this->values(webOnly: true);
        foreach (['https://example.com', 'http://example.com', 'mailto:a@example.com'] as $address) {
            $this->assertSame($address, $values->stored($field, $address));
        }
        foreach (['example.com', 'tel:+31201234567', 'ftp://example.com', 'https://exa mple.com', 'javascript:alert(1)'] as $address) {
            try {
                $values->stored($field, $address);
                $this->fail("Accepted $address");
            } catch (InvalidValue $e) {
                $this->assertSame('Enter an address that starts with http://, https:// or mailto:', $e->getMessage());
            }
        }
    }

    public function testTheAdministrationCanBlockDomains(): void {
        $values = $this->values(blocked: ['ru', 'evil.example']);
        $blocked = 'Links to this domain are not allowed';
        foreach (['https://yandex.ru', 'https://mail.YANDEX.RU./x', 'yandex.ru/page', 'mailto:anna@mail.ru', 'https://evil.example',
            'https://www.evil.example', 'https://evil.ru\\@good.com', 'https://anna@evil.ru'] as $address) {
            $this->assertSame($blocked, $this->linkRefusal($values, $address), $address);
        }
        foreach (['https://ru.example.com', 'https://notevil.example', 'https://example.com/ru', 'tel:+31201234567'] as $address) {
            $this->assertNull($this->linkRefusal($values, $address), $address);
        }
    }

    public function testTheAdministrationCanAllowSomeDomainsOnly(): void {
        $values = $this->values(allowed: ['example.com']);
        foreach (['https://example.com', 'https://docs.example.com/a', 'mailto:anna@example.com', 'example.com'] as $address) {
            $this->assertNull($this->linkRefusal($values, $address), $address);
        }
        foreach (['https://other.org', 'https://example.com.evil.org', 'tel:+31201234567', 'mailto:anna@other.org'] as $address) {
            $this->assertSame('Only links to approved domains are allowed', $this->linkRefusal($values, $address), $address);
        }
    }

    public function testDomainsAreReadAsAnAdministratorTypesThem(): void {
        $this->assertSame(['ru', 'example.com', 'xn--mnchen-3ya.de'],
            LinkSettings::domainList(".ru\nExample.com\n\nhttps://example.com/page, *.example.com\nmünchen.de"));
        $this->assertSame([], LinkSettings::domainList(''));
        $this->assertSame(['ru'], LinkSettings::domainList(['.ru']));
        foreach (['exa mple.com/x y!', ['a', ['b']], 42] as $wrong) {
            try {
                LinkSettings::domainList($wrong);
                $this->fail('Accepted ' . json_encode($wrong));
            } catch (InvalidValue $e) {
                $this->assertSame('Enter domains such as example.com or .ru, one per line', $e->getMessage());
            }
        }
    }

    public function testARequiredFieldKeepsAValue(): void {
        FieldValue::requireFilled(self::field('text', null, true), 'x');
        FieldValue::requireFilled(self::field('text'), '');
        foreach (['', '   '] as $empty) {
            try {
                FieldValue::requireFilled(self::field('text', null, true), $empty);
                $this->fail('An empty required field was accepted');
            } catch (InvalidValue $e) {
                $this->assertSame('This field is required', $e->getMessage());
            }
        }
    }

    public function testACheckboxIsNeverRequired(): void {
        // A flag 2.x let an admin set: ticked, not ticked and empty all save.
        foreach (['checkbox', 'boolean'] as $type) {
            $this->assertFalse(FieldValue::isRequired(self::field($type, null, true)));
            foreach (['1', '0', ''] as $stored) {
                FieldValue::requireFilled(self::field($type, null, true), $stored);
            }
        }
        $this->assertTrue(FieldValue::isRequired(self::field('text', null, true)));
        $this->assertFalse(FieldValue::isRequired(self::field('text')));
    }

    public function testWhoIsChosenIsLeftToTheAccounts(): void {
        $accounts = $this->createMock(AccountService::class);
        $accounts->expects($this->once())->method('chosen')->with(self::field('user'), 'anna;#bob', 'anna')->willReturn('anna;#bob');
        $values = new FieldValue($accounts, $this->createMock(LinkSettings::class));

        $this->assertSame('anna;#bob', $values->chosen(self::field('user'), 'anna;#bob', 'anna'));
    }
}
