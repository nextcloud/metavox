<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Dav;

require_once __DIR__ . '/../../stubs/Dav.php';

use OCA\MetaVox\Dav\SavedSearchReportPlugin;
use OCA\MetaVox\Service\SavedSearchResults;
use OCA\MetaVox\Service\SavedSearchService;
use OCA\MetaVox\Service\SearchSettings;
use OCP\App\IAppManager;
use OCP\Files\Node;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Exception as SabreException;
use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\Exception\ReportNotSupported;
use Sabre\DAV\INode;
use Sabre\DAV\Server;
use Sabre\DAV\SimpleCollection;
use Sabre\DAV\SimpleFile;
use Sabre\HTTP\Request;
use Sabre\HTTP\Response;

/** The REPORT through the real Sabre server Nextcloud ships. */
class SavedSearchReportPluginTest extends TestCase {

    private const SEARCH = ['id' => 7, 'name' => 'Concept', 'field' => 'file_gf_status', 'value' => 'Concept', 'groupfolderId' => null];

    private ?IUser $user = null;
    private bool $appEnabled = true;
    private bool $foldersOn = true;

    /** @var array<int, array<string, mixed>> the user's saved searches by id */
    private array $saved = [7 => self::SEARCH];

    /** @var list<array{0: string, 1: INode}> what the search finds, as DAV nodes */
    private array $found = [];
    private bool $more = false;
    private ?\Throwable $failure = null;

    private int $searches = 0;
    private int $lookups = 0;

    /** @var list<array{0: list<INode>, 1: list<string>}> */
    private array $preloads = [];

    private LoggerInterface $logger;

    protected function setUp(): void {
        $this->user = $this->createMock(IUser::class);
        $this->user->method('getUID')->willReturn('alice');
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function plugin(): SavedSearchReportPlugin {
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturnCallback(fn () => $this->user);
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->willReturnCallback(fn (string $app) => $app === 'metavox' && $this->appEnabled);

        $searches = $this->createMock(SavedSearchService::class);
        $searches->method('get')->willReturnCallback(function (string $user, int $id) {
            $this->lookups++;
            $this->assertSame('alice', $user);
            return $this->saved[$id] ?? null;
        });

        $nodes = [];
        foreach ($this->found as $ignored) {
            $nodes[] = $this->createMock(Node::class);
        }
        $results = $this->createMock(SavedSearchResults::class);
        $results->method('find')->willReturnCallback(function (IUser $user, array $search, int $limit) use ($nodes) {
            $this->assertSame(['alice', self::SEARCH, SavedSearchReportPlugin::MAX_FILES], [$user->getUID(), $search, $limit]);
            if ($this->failure !== null) {
                throw $this->failure;
            }
            return ['nodes' => $nodes, 'more' => $this->more];
        });

        $found = $this->found;
        $factory = function () use ($results): SavedSearchResults {
            $this->searches++;
            return $results;
        };
        $settings = $this->createMock(SearchSettings::class);
        $settings->method('foldersOn')->willReturnCallback(fn () => $this->foldersOn);
        // The DAV nodes Nextcloud would build, here Sabre's own.
        return new class($session, $apps, $searches, $factory, $this->logger, $settings, $nodes, $found) extends SavedSearchReportPlugin {
            public function __construct($session, $apps, $searches, $factory, $logger, $settings, private array $nodes, private array $found) {
                parent::__construct($session, $apps, $searches, $factory, $logger, $settings);
            }

            protected function davNode(Node $node, string $userId): ?array {
                return $this->found[array_search($node, $this->nodes, true)];
            }
        };
    }

    private function report(string $body, string $path = 'files/alice'): Response {
        $server = new Server(new SimpleCollection('root'));
        $server->setBaseUri('/remote.php/dav/');
        $server->addPlugin($this->plugin());
        $server->on('preloadProperties', function (array $nodes, array $properties) {
            $this->preloads[] = [$nodes, $properties];
        });
        $request = new Request('REPORT', '/remote.php/dav/' . $path, ['Content-Type' => 'application/xml'], $body);
        // As Server::start() does.
        $request->setBaseUrl('/remote.php/dav/');
        $response = new Response();
        $server->httpRequest = $request;
        $server->httpResponse = $response;
        $server->invokeMethod($request, $response, false);
        return $response;
    }

    private static function body(string $inner = self::PROPS . '<nc:search-id>7</nc:search-id>', string $root = 'nc:metavox-search'): string {
        return '<?xml version="1.0"?><' . $root . ' xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns">'
            . $inner . '</' . $root . '>';
    }

    private const PROPS = '<d:prop><d:getcontentlength/><d:resourcetype/><oc:fileid/></d:prop>';

    /** @return array<string, \DOMElement> href => its d:response */
    private static function responses(Response $response): array {
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($response->getBodyAsString()));
        $byHref = [];
        foreach ($dom->getElementsByTagNameNS('DAV:', 'response') as $element) {
            $byHref[$element->getElementsByTagNameNS('DAV:', 'href')->item(0)->textContent] = $element;
        }
        return $byHref;
    }

    public function testAnswersWithTheFilesNewestFirst(): void {
        $this->found = [
            ['files/alice/HR/my report.pdf', new SimpleFile('my report.pdf', 'abcd')],
            ['files/alice/HR/Archive', new SimpleCollection('Archive')],
            ['files/alice/Sales/b.txt', new SimpleFile('b.txt', 'x')],
        ];

        $response = $this->report(self::body(self::PROPS . '<nc:search-id>7</nc:search-id>'));

        $this->assertSame(207, $response->getStatus());
        $this->assertSame('application/xml; charset=utf-8', $response->getHeader('Content-Type'));
        $this->assertFalse($response->hasHeader(SavedSearchReportPlugin::MORE_HEADER));
        $responses = self::responses($response);
        $this->assertSame([
            '/remote.php/dav/files/alice/HR/my%20report.pdf',
            '/remote.php/dav/files/alice/HR/Archive/',
            '/remote.php/dav/files/alice/Sales/b.txt',
        ], array_keys($responses));
        $this->assertSame('4', $responses['/remote.php/dav/files/alice/HR/my%20report.pdf']->getElementsByTagNameNS('DAV:', 'getcontentlength')->item(0)->textContent);
        // What no plugin knows stays 404, as in a listing.
        $this->assertSame(3, self::countIn($response, 'HTTP/1.1 404 Not Found'));
    }

    public function testPreloadsAllAtOnceForNextcloudsPlugins(): void {
        $this->found = [['files/alice/HR/a.pdf', new SimpleFile('a.pdf', 'a')], ['files/alice/HR/b.pdf', new SimpleFile('b.pdf', 'b')]];

        $this->report(self::body(self::PROPS . '<nc:search-id>7</nc:search-id>'));

        $this->assertCount(1, $this->preloads);
        [$nodes, $properties] = $this->preloads[0];
        $this->assertSame([$this->found[0][1], $this->found[1][1]], $nodes);
        $this->assertSame(['{DAV:}getcontentlength', '{DAV:}resourcetype', '{http://owncloud.org/ns}fileid'], $properties);
    }

    public function testNeverWhatCostsAQueryAFile(): void {
        $this->found = [['files/alice/HR/a.pdf', new SimpleFile('a.pdf', 'abc')]];
        $costly = '<oc:share-types/><nc:sharees/><oc:comments-count/><oc:comments-unread/><nc:system-tags/><nc:reminder-due-date/>'
            . '<nc:lock/><nc:lock-owner-type/><nc:lock-owner/><nc:lock-owner-displayname/><nc:lock-owner-editor/><nc:lock-time/><nc:lock-timeout/><nc:lock-token/>';

        $response = $this->report(self::body('<d:prop><d:getcontentlength/>' . $costly . '</d:prop><nc:search-id>7</nc:search-id>'));

        $this->assertSame(['{DAV:}getcontentlength'], $this->preloads[0][1]);
        $body = $response->getBodyAsString();
        $this->assertStringContainsString('getcontentlength>3<', $body);
        foreach (['share-types', 'sharees', 'comments', 'system-tags', 'reminder', 'lock'] as $name) {
            $this->assertStringNotContainsString($name, $body, $name);
        }
        $this->assertCount(14, SavedSearchReportPlugin::NOT_SERVED);
    }

    public function testAsksForItsProperties(): void {
        // Without <d:prop> WebDAV means all of them, shares included.
        $this->found = [['files/alice/HR/a.pdf', new SimpleFile('a.pdf', 'abc')]];

        try {
            $this->report(self::body('<nc:search-id>7</nc:search-id>'));
            $this->fail('answered');
        } catch (BadRequest) {
        }
        $this->assertSame(0, $this->searches);
    }

    public function testNothingFoundIsAnEmptyFolder(): void {
        $response = $this->report(self::body(self::PROPS . '<nc:search-id>7</nc:search-id>'));

        $this->assertSame(207, $response->getStatus());
        $this->assertSame([], self::responses($response));
    }

    public function testSaysWhenMoreMatch(): void {
        $this->found = [['files/alice/HR/a.pdf', new SimpleFile('a.pdf', 'a')]];
        $this->more = true;

        $this->assertSame('1', $this->report(self::body(self::PROPS . '<nc:search-id>7</nc:search-id>'))->getHeader(SavedSearchReportPlugin::MORE_HEADER));
    }

    public function testLeavesOtherReportsAlone(): void {
        try {
            $this->report(self::body('<oc:filter-rules><oc:favorite>1</oc:favorite></oc:filter-rules>', 'oc:filter-files'));
            $this->fail('answered');
        } catch (ReportNotSupported) {
        }
        $this->assertSame([0, 0], [$this->lookups, $this->searches]);
    }

    public function testNotWithoutAUser(): void {
        $this->user = null;

        $this->expectException(ReportNotSupported::class);
        $this->report(self::body());
    }

    public function testNotWhenMetaVoxIsOffForThem(): void {
        $this->appEnabled = false;

        try {
            $this->report(self::body());
            $this->fail('answered');
        } catch (ReportNotSupported) {
        }
        $this->assertSame([0, 0], [$this->lookups, $this->searches]);
    }

    public function testNotWhenTheAdminTurnedFoldersOff(): void {
        $this->foldersOn = false;

        try {
            $this->report(self::body());
            $this->fail('answered');
        } catch (ReportNotSupported) {
        }
        $this->assertSame([0, 0], [$this->lookups, $this->searches]);
    }

    public static function otherPaths(): array {
        return [
            'a folder in their files' => ['files/alice/HR'],
            'someone else\'s files' => ['files/bob'],
            'a user called like a path' => ['files/alice2'],
        ];
    }

    #[DataProvider('otherPaths')]
    public function testOnlyOnTheirOwnFilesRoot(string $path): void {
        try {
            $this->report(self::body(), $path);
            $this->fail('answered');
        } catch (NotFound) {
        }
        $this->assertSame([0, 0], [$this->lookups, $this->searches]);
    }

    public static function badIds(): array {
        return [
            'none' => [''],
            'empty' => ['<nc:search-id></nc:search-id>'],
            'zero' => ['<nc:search-id>0</nc:search-id>'],
            'negative' => ['<nc:search-id>-7</nc:search-id>'],
            'text' => ['<nc:search-id>seven</nc:search-id>'],
            'decimal' => ['<nc:search-id>7.5</nc:search-id>'],
            'too long' => ['<nc:search-id>12345678901</nc:search-id>'],
            'nested' => ['<nc:search-id><nc:x>7</nc:x></nc:search-id>'],
        ];
    }

    #[DataProvider('badIds')]
    public function testNeedsASearchId(string $inner): void {
        try {
            $this->report(self::body($inner));
            $this->fail('answered');
        } catch (BadRequest) {
        }
        $this->assertSame([0, 0], [$this->lookups, $this->searches]);
    }

    public function testOnlyTheirOwnSavedSearch(): void {
        // Another user's id, or a removed one: not among theirs.
        try {
            $this->report(self::body('<nc:search-id>8</nc:search-id>'));
            $this->fail('answered');
        } catch (NotFound) {
        }
        $this->assertSame(0, $this->searches);
    }

    public function testAFailureTellsNothing(): void {
        $this->failure = new \RuntimeException('SQLSTATE secret detail');
        $this->logger->expects($this->once())->method('error');

        try {
            $this->report(self::body());
            $this->fail('answered');
        } catch (SabreException $e) {
            $this->assertSame(SabreException::class, get_class($e));
            $this->assertSame('Saved search failed', $e->getMessage());
        }
    }

    public function testSabreErrorsPassThrough(): void {
        $this->failure = new NotFound('gone');

        $this->expectException(NotFound::class);
        $this->report(self::body());
    }

    public function testShowsTheNewest500(): void {
        $this->assertSame(500, SavedSearchReportPlugin::MAX_FILES);
    }

    public function testASearchIdOnItsOwnLines(): void {
        $this->assertSame(207, $this->report(self::body(self::PROPS . "<nc:search-id>\n    7\n</nc:search-id>"))->getStatus());
    }

    public function testNamesItsReport(): void {
        $this->assertSame([SavedSearchReportPlugin::REPORT], $this->plugin()->getSupportedReportSet('files/alice'));
        $this->assertSame('metavox-search', $this->plugin()->getPluginName());
    }

    private static function countIn(Response $response, string $needle): int {
        return substr_count($response->getBodyAsString(), $needle);
    }
}
