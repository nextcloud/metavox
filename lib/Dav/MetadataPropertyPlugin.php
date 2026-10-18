<?php

declare(strict_types=1);

namespace OCA\MetaVox\Dav;

use OCA\DAV\Connector\Sabre\Directory;
use OCA\DAV\Connector\Sabre\Node as DavNode;
use OCA\MetaVox\AppInfo\Application;
use OCA\MetaVox\Service\DavMetadataService;
use OCA\MetaVox\Service\GroupfolderResolver;
use OCP\App\IAppManager;
use OCP\Files\NotFoundException;
use OCP\Files\StorageNotAvailableException;
use OCP\IUserSession;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Exception as SabreException;
use Sabre\DAV\ICollection;
use Sabre\DAV\INode;
use Sabre\DAV\PropFind;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;

/**
 * Serves nc:metavox in Depth-1 PROPFINDs of team folder listings, so the Files
 * app gets the grid values with the listing. Like NC's TagsPlugin: one preload
 * per listed folder, then each file is served from memory. Anything we can't
 * vouch for stays 404 and the frontend falls back to the web route.
 */
class MetadataPropertyPlugin extends ServerPlugin {

    // nc: on purpose: NC never stores nc: properties in oc_properties.
    public const PROPERTY = '{http://nextcloud.org/ns}metavox';

    /** @var array<int, string> file id => property value */
    private array $payloads = [];

    /** @var array<string, true> listed paths already preloaded */
    private array $preloaded = [];

    private ?DavMetadataService $service = null;

    /**
     * @param \Closure(): DavMetadataService $serviceFactory only built for team folder listings
     */
    public function __construct(
        private readonly IUserSession $userSession,
        private readonly IAppManager $appManager,
        private readonly GroupfolderResolver $resolver,
        private readonly \Closure $serviceFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getPluginName(): string {
        return 'metavox';
    }

    public function initialize(Server $server): void {
        // PROPPATCH gets a 403 instead of a stored dead property.
        $server->protectedProperties[] = self::PROPERTY;
        // NC 32+; on NC 31 propFind() does the preload.
        $server->on('preloadCollection', $this->preloadCollection(...));
        $server->on('propFind', $this->propFind(...));
    }

    public function preloadCollection(PropFind $propFind, ICollection $collection): void {
        if ($collection instanceof Directory) {
            $this->preload($propFind, $collection);
        }
    }

    public function propFind(PropFind $propFind, INode $node): void {
        if (!$node instanceof DavNode || $propFind->getStatus(self::PROPERTY) === null) {
            return;
        }
        if ($node instanceof Directory) {
            $this->preload($propFind, $node);
        }
        $id = $node->getId();
        if ($id !== null && isset($this->payloads[$id])) {
            $json = $this->payloads[$id];
            $propFind->handle(self::PROPERTY, static fn (): CData => new CData($json));
        }
    }

    private function preload(PropFind $propFind, Directory $directory): void {
        if ($propFind->getDepth() !== 1 || $propFind->getStatus(self::PROPERTY) === null) {
            return;
        }
        $path = $propFind->getPath();
        if (isset($this->preloaded[$path])) {
            return;
        }
        $this->preloaded[$path] = true;

        // Read here, not in the constructor: the plugin is added before auth.
        $user = $this->userSession->getUser();
        if ($user === null || !$this->appManager->isEnabledForUser(Application::APP_ID, $user)) {
            return;
        }

        $start = hrtime(true);
        $memoryBefore = memory_get_usage();
        $peakBefore = memory_get_peak_usage();
        try {
            $folder = $directory->getNode();
            if ($this->resolver->getGroupfolderId($folder) === null) {
                return;
            }
            $children = [];
            foreach ($directory->getChildren() as $child) {
                if ($child instanceof DavNode) {
                    $children[] = $child->getNode();
                }
            }
            $this->service ??= ($this->serviceFactory)();
            $built = $this->service->buildForListing($user->getUID(), $folder, $children);
            $this->payloads = $built + $this->payloads;

            $this->logger->debug('MetaVox: PROPFIND preload', [
                'app' => 'metavox',
                'path' => $path,
                'children' => count($children),
                'served' => count($built),
                'ms' => round((hrtime(true) - $start) / 1e6, 1),
                // What the preload keeps, and how far it raised the request's peak.
                'memoryAdded' => memory_get_usage() - $memoryBefore,
                'peakAdded' => memory_get_peak_usage() - $peakBefore,
                'peakMemory' => memory_get_peak_usage(),
            ]);
        } catch (SabreException|LockedException|StorageNotAvailableException|NotFoundException $e) {
            // The listing itself is refused; Sabre reports that on its own.
            $this->logger->debug('MetaVox: PROPFIND preload skipped', ['app' => 'metavox', 'exception' => $e]);
        } catch (\Throwable $e) {
            $this->logger->warning('MetaVox: PROPFIND preload failed, serving no values', ['app' => 'metavox', 'exception' => $e]);
        }
    }
}
