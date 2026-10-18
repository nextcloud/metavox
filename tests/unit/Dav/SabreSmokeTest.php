<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Dav;

require_once __DIR__ . '/../../stubs/Dav.php';

use OCA\DAV\Connector\Sabre\Directory;
use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\MetaVox\Dav\CData;
use OCA\MetaVox\Dav\MetadataPropertyPayload;
use OCP\Files\Folder;
use PHPUnit\Framework\TestCase;
use Sabre\DAV\ICollection;
use Sabre\DAV\PropFind;
use Sabre\DAV\Server;
use Sabre\DAV\Xml\Response\MultiStatus;
use Sabre\DAV\Xml\Element\Response;
use Sabre\DAV\Xml\Service;

/** The real Sabre (the version NC ships) and the NC stubs work together. */
class SabreSmokeTest extends TestCase {

    private const PROPERTY = '{http://nextcloud.org/ns}metavox';

    public function testPropFindServesARequestedProperty(): void {
        $propFind = new PropFind('files/alice/HR', [self::PROPERTY], 1);
        $this->assertSame(404, $propFind->getStatus(self::PROPERTY));

        $propFind->handle(self::PROPERTY, static fn () => '{"v":1,"g":12}');

        $this->assertSame([200 => [self::PROPERTY => '{"v":1,"g":12}'], 404 => []], $propFind->getResultForMultiStatus());
    }

    public function testUnrequestedPropertyIsNotServed(): void {
        $propFind = new PropFind('files/alice/HR', ['{DAV:}getetag'], 1);

        $this->assertNull($propFind->getStatus(self::PROPERTY));
    }

    public function testPayloadSurvivesSabresXml(): void {
        $payload = MetadataPropertyPayload::encode(12, true, ['note' => "<b>&\"x\x0B\u{FFFE}</b>]]>&amp;"]);

        $xml = $this->multistatus(new CData($payload));

        $this->assertStringContainsString('<nc:metavox><![CDATA[{"v":1,', $xml);
        $this->assertStringNotContainsString('&quot;', $xml);
        $this->assertSame($payload, $this->readBack($xml));
    }

    public function testTextThatWouldEndTheSectionIsEscapedInstead(): void {
        $text = '{"a":"]]>"}';

        $xml = $this->multistatus(new CData($text));

        $this->assertStringNotContainsString('CDATA', $xml);
        $this->assertSame($text, $this->readBack($xml));
    }

    private function multistatus(CData $value): string {
        $service = new Service();
        $service->namespaceMap['http://nextcloud.org/ns'] = 'nc';
        return $service->write('{DAV:}multistatus', new MultiStatus([
            new Response('/remote.php/dav/files/alice/HR/a.pdf', [200 => [self::PROPERTY => $value]]),
        ]));
    }

    private function readBack(string $xml): string {
        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($xml));
        return $dom->getElementsByTagNameNS('http://nextcloud.org/ns', 'metavox')->item(0)->textContent;
    }

    public function testStubsCanBeMocked(): void {
        $folder = $this->createMock(Folder::class);
        $directory = $this->createMock(Directory::class);
        $directory->method('getNode')->willReturn($folder);

        $this->assertInstanceOf(ICollection::class, $directory);
        $this->assertSame($folder, $directory->getNode());

        $server = new Server();
        $this->assertSame($server, (new SabrePluginAddEvent($server))->getServer());
    }
}
