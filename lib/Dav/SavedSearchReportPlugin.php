<?php

declare(strict_types=1);

namespace OCA\MetaVox\Dav;

use OC\Files\Filesystem;
use OCA\DAV\Connector\Sabre\CachingTree;
use OCA\DAV\Connector\Sabre\Directory;
use OCA\DAV\Connector\Sabre\File;
use OCA\MetaVox\AppInfo\Application;
use OCA\MetaVox\Service\SavedSearchResults;
use OCA\MetaVox\Service\SavedSearchService;
use OCA\MetaVox\Service\SearchSettings;
use OCP\App\IAppManager;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Exception as SabreException;
use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\ICollection;
use Sabre\DAV\INode;
use Sabre\DAV\PropFind;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;

/**
 * A saved search as the contents of a folder in Files: a REPORT on the user's
 * own files root, answered like Nextcloud's WebDAV SEARCH, so each file comes
 * with the properties of a folder listing.
 */
class SavedSearchReportPlugin extends ServerPlugin {

    public const REPORT = '{http://nextcloud.org/ns}metavox-search';
    public const SEARCH_ID = '{http://nextcloud.org/ns}search-id';
    /** The newest files a search folder shows. */
    public const MAX_FILES = 500;
    /** Set when more files match than the folder shows. */
    public const MORE_HEADER = 'X-MetaVox-More';
    /**
     * Not served here: for a list of loose files Nextcloud reads these one file
     * at a time, a query or more a file (shares about eleven). The file's own
     * folder and the sidebar still show them.
     */
    public const NOT_SERVED = [
        // Shares
        '{http://owncloud.org/ns}share-types',
        '{http://nextcloud.org/ns}sharees',
        // Comments
        '{http://owncloud.org/ns}comments-count',
        '{http://owncloud.org/ns}comments-unread',
        // Tags
        '{http://nextcloud.org/ns}system-tags',
        // Reminders
        '{http://nextcloud.org/ns}reminder-due-date',
        // Locks (files_lock)
        '{http://nextcloud.org/ns}lock',
        '{http://nextcloud.org/ns}lock-owner-type',
        '{http://nextcloud.org/ns}lock-owner',
        '{http://nextcloud.org/ns}lock-owner-displayname',
        '{http://nextcloud.org/ns}lock-owner-editor',
        '{http://nextcloud.org/ns}lock-time',
        '{http://nextcloud.org/ns}lock-timeout',
        '{http://nextcloud.org/ns}lock-token',
    ];

    private ?Server $server = null;

    /**
     * @param \Closure(): SavedSearchResults $resultsFactory only built for this report
     */
    public function __construct(
        private readonly IUserSession $userSession,
        private readonly IAppManager $appManager,
        private readonly SavedSearchService $searches,
        private readonly \Closure $resultsFactory,
        private readonly LoggerInterface $logger,
        private readonly SearchSettings $settings,
    ) {
    }

    public function getPluginName(): string {
        return 'metavox-search';
    }

    public function initialize(Server $server): void {
        $this->server = $server;
        $server->on('report', $this->onReport(...));
    }

    public function getSupportedReportSet($uri): array {
        return [self::REPORT];
    }

    /**
     * @return ?bool false when answered; null leaves other reports to their plugins
     */
    public function onReport(string $reportName, mixed $report, string $uri): ?bool {
        if ($reportName !== self::REPORT) {
            return null;
        }
        // Read here, not in the constructor: the plugin is added before auth.
        $user = $this->userSession->getUser();
        if ($user === null || !$this->appManager->isEnabledForUser(Application::APP_ID, $user) || !$this->settings->foldersOn()) {
            return null;
        }
        $userId = $user->getUID();
        if (trim($uri, '/') !== 'files/' . $userId) {
            throw new NotFound('Saved searches are on your own files only');
        }
        $id = self::searchId($report);
        if ($id === null) {
            throw new BadRequest('No search id');
        }
        $search = $this->searches->get($userId, $id);
        if ($search === null) {
            throw new NotFound('Saved search not found');
        }
        // No list means every property, the ones not served here too.
        $properties = self::requestedProperties($report);
        if ($properties === null) {
            throw new BadRequest('No properties asked for');
        }
        $properties = array_values(array_diff($properties, self::NOT_SERVED));

        try {
            ['nodes' => $nodes, 'more' => $more] = ($this->resultsFactory)()->find($user, $search, self::MAX_FILES);
            $davNodes = [];
            foreach ($nodes as $node) {
                $dav = $this->davNode($node, $userId);
                if ($dav !== null) {
                    $davNodes[$dav[0]] = $dav[1];
                }
            }
            // Nextcloud's plugins that can, read their values for all at once.
            $this->server->emit('preloadProperties', [array_values($davNodes), $properties]);
            $responses = [];
            foreach ($davNodes as $href => $davNode) {
                $propFind = new PropFind($href, $properties);
                if (!$this->server->getPropertiesByNode($propFind, $davNode)) {
                    continue;
                }
                $response = $propFind->getResultForMultiStatus();
                $response['href'] = $href . ($davNode instanceof ICollection ? '/' : '');
                $responses[] = $response;
            }
        } catch (SabreException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('MetaVox: saved search failed', ['app' => 'metavox', 'exception' => $e]);
            throw new SabreException('Saved search failed');
        }

        $httpResponse = $this->server->httpResponse;
        $httpResponse->setStatus(207);
        $httpResponse->setHeader('Content-Type', 'application/xml; charset=utf-8');
        if ($more) {
            $httpResponse->setHeader(self::MORE_HEADER, '1');
        }
        $httpResponse->setBody($this->server->generateMultiStatus($responses, false));
        return false;
    }

    /**
     * Where the file is in the user's DAV tree, and the node there; as
     * Nextcloud's WebDAV SEARCH builds them.
     *
     * @return array{0: string, 1: INode}|null
     */
    protected function davNode(Node $node, string $userId): ?array {
        $view = Filesystem::getView();
        $path = $view?->getRelativePath($node->getPath());
        if ($path === null) {
            return null;
        }
        $tree = $this->server->tree;
        $tree = $tree instanceof CachingTree ? $tree : null;
        $davNode = $node instanceof Folder ? new Directory($view, $node, $tree) : new File($view, $node);
        $href = 'files/' . $userId . $path;
        $tree?->cacheNode($davNode, $href);
        return [$href, $davNode];
    }

    /** The search id of <nc:search-id>; null when missing or not a positive number. */
    private static function searchId(mixed $report): ?int {
        foreach (\is_array($report) ? $report : [] as $element) {
            if (($element['name'] ?? null) === self::SEARCH_ID) {
                $value = $element['value'] ?? null;
                return \is_string($value) && preg_match('/^\s*[1-9]\d{0,9}\s*$/', $value) === 1 ? (int)$value : null;
            }
        }
        return null;
    }

    /**
     * The properties in <d:prop>; null without one.
     *
     * @return ?list<string>
     */
    private static function requestedProperties(mixed $report): ?array {
        foreach (\is_array($report) ? $report : [] as $element) {
            if (($element['name'] ?? null) === '{DAV:}prop' && \is_array($element['value'] ?? null)) {
                $names = [];
                foreach ($element['value'] as $property) {
                    if (\is_string($property['name'] ?? null)) {
                        $names[] = $property['name'];
                    }
                }
                return $names;
            }
        }
        return null;
    }
}
