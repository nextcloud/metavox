<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Service\FieldService;
use PHPUnit\Framework\TestCase;

/**
 * Pure validation of per-folder default values, per field type. No DB — uses
 * FieldService::validateDefaultValueForType directly with an injected
 * user-exists callback. Returns null when valid, an error string when not.
 */
class DefaultValueValidationTest extends TestCase {

    /** A user-exists stub that accepts only "alice". */
    private function userExists(): callable {
        return static fn(string $uid): bool => $uid === 'alice';
    }

    private function validate(string $type, ?string $value, $options = []): ?string {
        return FieldService::validateDefaultValueForType($type, $value, $options, $this->userExists());
    }

    public function testEmptyAlwaysAllowed(): void {
        $this->assertNull($this->validate('date', null));
        $this->assertNull($this->validate('date', ''));
        $this->assertNull($this->validate('select', '', ['A', 'B']));
    }

    public function testDateValid(): void {
        $this->assertNull($this->validate('date', '2026-06-19'));
    }

    public function testDateInvalid(): void {
        // Format contract (YYYY-MM-DD), matching ApiFieldService — a wrong
        // shape is rejected. (Calendar validity is not checked, by design.)
        $this->assertNotNull($this->validate('date', '19-06-2026'));
        $this->assertNotNull($this->validate('date', '2026/06/19'));
        $this->assertNotNull($this->validate('date', 'today'));
    }

    public function testDateTimeRequiresTimeComponent(): void {
        $opts = ['includeTime' => true];
        $this->assertNull($this->validate('date', '2026-06-19T08:30:00', $opts));
        $this->assertNotNull($this->validate('date', '2026-06-19', $opts));
    }

    public function testNumber(): void {
        $this->assertNull($this->validate('number', '2030'));
        $this->assertNull($this->validate('number', '3.14'));
        $this->assertNotNull($this->validate('number', 'abc'));
    }

    public function testCheckbox(): void {
        $this->assertNull($this->validate('checkbox', '0'));
        $this->assertNull($this->validate('checkbox', '1'));
        $this->assertNotNull($this->validate('checkbox', 'true'));
    }

    public function testSelectMembership(): void {
        $opts = ['Draft', 'Review', 'Published'];
        $this->assertNull($this->validate('select', 'Review', $opts));
        $this->assertNotNull($this->validate('select', 'Archived', $opts));
    }

    public function testSelectObjectOptionShape(): void {
        // Options can also be [{value,label}, ...].
        $opts = [['value' => 'a', 'label' => 'Alpha'], ['value' => 'b', 'label' => 'Beta']];
        $this->assertNull($this->validate('select', 'b', $opts));
        $this->assertNotNull($this->validate('select', 'z', $opts));
    }

    public function testMultiselectMembership(): void {
        $opts = ['High', 'Medium', 'Low'];
        $this->assertNull($this->validate('multiselect', 'High;#Low', $opts));
        $this->assertNotNull($this->validate('multiselect', 'High;#Urgent', $opts));
    }

    public function testUserExists(): void {
        $this->assertNull($this->validate('user', 'alice'));
        $this->assertNotNull($this->validate('user', 'bob'));
    }

    public function testFilelinkRequiresFileId(): void {
        $this->assertNull($this->validate('filelink', '482:/Docs/spec.pdf'));
        $this->assertNull($this->validate('filelink', '1:/a.pdf;#2:/b.pdf'));
        // A bare path means it could not be resolved to a file id.
        $this->assertNotNull($this->validate('filelink', '/Docs/spec.pdf'));
    }

    public function testFreeFormTypes(): void {
        $this->assertNull($this->validate('text', 'anything'));
        $this->assertNull($this->validate('textarea', "multi\nline"));
        $this->assertNull($this->validate('url', 'https://example.com'));
    }
}
