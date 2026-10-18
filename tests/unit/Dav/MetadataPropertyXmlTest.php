<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Dav;

use OCA\MetaVox\Dav\CData;
use OCA\MetaVox\Dav\MetadataPropertyPayload as Payload;
use PHPUnit\Framework\TestCase;
use Sabre\DAV\Server;

/**
 * nc:metavox as Sabre writes it, read back. dav-metavox.xml is that output for
 * dav-metavox.json; tests/dav-parsers reads it with every parser NC 31-35 shipped.
 */
class MetadataPropertyXmlTest extends TestCase {

    private const FIXTURES = __DIR__ . '/../../fixtures/';
    private const NS = 'http://nextcloud.org/ns';
    private const BASE = '/remote.php/dav/';

    public function testFixtureIsWhatTheServerWrites(): void {
        $xml = self::multistatus(self::fixture());
        $file = self::FIXTURES . 'dav-metavox.xml';
        if (getenv('METAVOX_UPDATE_FIXTURES') === '1') {
            file_put_contents($file, $xml);
        }

        $this->assertSame(file_get_contents($file), $xml,
            'nc:metavox is written differently: run tests/dav-parsers, then METAVOX_UPDATE_FIXTURES=1 vendor/bin/phpunit');
    }

    public function testFixtureReadsBackAsItsValues(): void {
        $files = self::fixture();

        $read = $this->readBack(self::multistatus($files));

        $this->assertCount(count($files), $read);
        foreach ($files as $i => $file) {
            $this->assertReadsBack($file, $read[$i]);
        }
    }

    public function testRandomValuesSurviveTheXml(): void {
        $pieces = [']]>', ']]', ']', '>', '<', '<![CDATA[', '-->', '&', '&amp;', '&#0;', '"', "'", '\\', '\\u003E', '/', ';#',
            "\t", "\n", "\r", "\x00", "\x01", "\x0B", "\x1F", "\x7F", ' ', 'a', 'Z', '0', '-1e5', 'é', '€', '数',
            "\u{1F600}", "\u{FFFE}", "\u{2028}", "\u{200B}", '{"', '"}'];
        $random = static function (int $length) use ($pieces): string {
            $value = '';
            for (; $length > 0; $length--) {
                $value .= $pieces[mt_rand(0, count($pieces) - 1)];
            }
            return $value;
        };
        mt_srand(8);
        $files = [];
        for ($i = 0; $i < 2000; $i++) {
            // Every 50th value long enough to be cut off.
            $values = ['a' => $random($i % 50 === 0 ? 400 : mt_rand(0, 30)), 'b' => $random(mt_rand(0, 5))];
            $files[] = ['href' => self::BASE . "files/alice/f$i", 'groupfolder' => $i, 'canWrite' => $i % 2 === 0, 'values' => $values];
        }

        $read = $this->readBack(self::multistatus($files));

        foreach ($files as $i => $file) {
            $this->assertReadsBack($file, $read[$i]);
        }
    }

    /** @return list<array{href: string, noValues?: bool, groupfolder?: int, canWrite?: bool, values?: array<string, ?string>}> */
    private static function fixture(): array {
        return json_decode(file_get_contents(self::FIXTURES . 'dav-metavox.json'), true, 512, JSON_THROW_ON_ERROR)['files'];
    }

    private static function payload(array $file): string {
        return ($file['noValues'] ?? false) ? Payload::NO_VALUES : Payload::encode($file['groupfolder'], $file['canWrite'], $file['values']);
    }

    /** The way NC's DAV server answers a PROPFIND. */
    private static function multistatus(array $files): string {
        $server = new Server();
        $server->setBaseUri(self::BASE);
        $server->xml->namespaceMap['http://owncloud.org/ns'] = 'oc';
        $server->xml->namespaceMap[self::NS] = 'nc';
        return $server->generateMultiStatus(array_map(static fn (array $file) => [
            'href' => substr($file['href'], strlen(self::BASE)),
            200 => ['{' . self::NS . '}metavox' => new CData(self::payload($file))],
        ], $files));
    }

    /** @return list<string> each response's nc:metavox, checked to be one CDATA section */
    private function readBack(string $xml): array {
        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($xml), 'well-formed');
        $texts = [];
        foreach ($dom->getElementsByTagNameNS(self::NS, 'metavox') as $element) {
            $this->assertSame(1, $element->childNodes->length);
            $this->assertSame(XML_CDATA_SECTION_NODE, $element->firstChild->nodeType);
            $texts[] = $element->textContent;
        }
        return $texts;
    }

    private function assertReadsBack(array $file, string $json): void {
        if ($file['noValues'] ?? false) {
            $this->assertSame(Payload::NO_VALUES, $json);
            return;
        }
        $this->assertStringNotContainsString(']]>', $json);
        $doc = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(Payload::FORMAT, $doc['v']);
        $this->assertSame($file['groupfolder'], $doc['g']);
        $this->assertSame($file['canWrite'], isset($doc['e']));

        $fields = $doc['f'] ?? [];
        $kept = array_filter($file['values'], static fn ($v) => $v !== null && $v !== '');
        $this->assertSame(array_map('strval', array_keys($kept)), array_map('strval', array_keys($fields)));
        foreach ($kept as $name => $value) {
            if (mb_strlen($value, 'UTF-8') > Payload::MAX_CHARS) {
                $this->assertContains((string)$name, $doc['t'] ?? []);
                $this->assertNotSame('', $fields[$name]);
                $this->assertStringStartsWith($fields[$name], $value);
            } else {
                $this->assertSame($value, $fields[$name], "field $name of {$file['href']}");
            }
        }
    }
}
