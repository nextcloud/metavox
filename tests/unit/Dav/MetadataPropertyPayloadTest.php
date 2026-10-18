<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Dav;

use OCA\MetaVox\Dav\MetadataPropertyPayload as Payload;
use PHPUnit\Framework\TestCase;

class MetadataPropertyPayloadTest extends TestCase {

    private function decode(string $json): array {
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    public function testFolderWithoutValues(): void {
        $this->assertSame('{"v":1,"g":12}', Payload::encode(12, false, []));
    }

    public function testEditableOnlyWhenAllowed(): void {
        $this->assertSame('{"v":1,"g":12,"e":1,"f":{"status":"Draft"}}', Payload::encode(12, true, ['status' => 'Draft']));
        $this->assertArrayNotHasKey('e', $this->decode(Payload::encode(12, false, ['status' => 'Draft'])));
    }

    public function testNoValuesConstant(): void {
        $this->assertSame(['v' => Payload::FORMAT], $this->decode(Payload::NO_VALUES));
    }

    public function testFieldsStayAnObject(): void {
        $this->assertSame('{"v":1,"g":12,"f":{"0":"x"}}', Payload::encode(12, false, ['0' => 'x']));
    }

    public function testEmptyValuesAreDroppedButZeroStays(): void {
        $doc = $this->decode(Payload::encode(12, false, ['a' => null, 'b' => '', 'c' => '0']));

        $this->assertSame(['c' => '0'], $doc['f']);
    }

    public function testNumbersStayStrings(): void {
        $doc = $this->decode(Payload::encode(12, false, ['version' => '2024.10', 'count' => '007']));

        $this->assertSame(['version' => '2024.10', 'count' => '007'], $doc['f']);
    }

    public function testCutOffAtMaxChars(): void {
        $exact = str_repeat('a', Payload::MAX_CHARS);
        $long = $exact . 'b';

        $doc = $this->decode(Payload::encode(12, false, ['exact' => $exact, 'long' => $long]));

        $this->assertSame($exact, $doc['f']['exact']);
        $this->assertSame($exact, $doc['f']['long']);
        $this->assertSame(['long'], $doc['t']);
    }

    public function testCutOffCountsCharactersNotBytes(): void {
        $value = str_repeat('é', Payload::MAX_CHARS) . '€';

        $cut = $this->decode(Payload::encode(12, false, ['note' => $value]))['f']['note'];

        $this->assertTrue(mb_check_encoding($cut, 'UTF-8'));
        $this->assertSame(Payload::MAX_CHARS, mb_strlen($cut, 'UTF-8'));
        $this->assertStringStartsWith($cut, $value);
    }

    public function testCutOffListEndsOnAWholeItem(): void {
        $items = array_map(fn ($i) => 'Option ' . $i, range(1, 100));
        $value = implode(';#', $items);

        $cut = $this->decode(Payload::encode(12, false, ['tags' => $value]))['f']['tags'];

        $this->assertStringStartsWith($cut, $value);
        $this->assertLessThanOrEqual(Payload::MAX_CHARS, mb_strlen($cut, 'UTF-8'));
        foreach (explode(';#', $cut) as $item) {
            $this->assertContains($item, $items);
        }
    }

    public function testNastyValuesStayValidJsonAndXml(): void {
        $values = [
            'control' => "a\x0Bb\x00c",
            'invalid' => "x\xC3\x28y",
            'nonchar' => "z\u{FFFE}\u{FFFF}",
            'markup' => '<b>&"\'</b>',
            'cdataEnd' => 'x]]>y',
            'unicode' => 'Überprüfung ✓ 数据',
        ];

        $json = Payload::encode(12, true, $values);

        $this->assertMatchesRegularExpression('/^[\x20-\x7E]*$/', $json, 'plain ASCII');
        $this->assertStringNotContainsString(']]>', $json, 'safe inside CDATA');
        $fields = $this->decode($json)['f'];
        $this->assertSame($values['control'], $fields['control']);
        $this->assertSame($values['markup'], $fields['markup']);
        $this->assertSame($values['cdataEnd'], $fields['cdataEnd']);
        $this->assertSame($values['unicode'], $fields['unicode']);
        $this->assertSame("x\u{FFFD}(y", $fields['invalid']);

        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElementNs('d', 'multistatus', 'DAV:');
        $xml->startElementNs('nc', 'metavox', 'http://nextcloud.org/ns');
        $xml->text($json);
        $xml->endElement();
        $xml->endElement();
        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($xml->outputMemory()));
        $this->assertSame($json, $dom->getElementsByTagNameNS('http://nextcloud.org/ns', 'metavox')->item(0)->textContent);
    }
}
