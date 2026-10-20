<?php

declare(strict_types=1);

namespace OCA\MetaVox\AppInfo;

use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\MetaVox\Access\AccessContext;
use OCA\MetaVox\Dav\MetadataPropertyPlugin;
use OCA\MetaVox\Dav\SavedSearchReportPlugin;
use OCA\MetaVox\Listener\ApplyDefaultsListener;
use OCA\MetaVox\Listener\CacheCleanupListener;
use OCA\MetaVox\Listener\FileCopyListener;
use OCA\MetaVox\Listener\PresenceKeepAliveListener;
use OCA\MetaVox\Listener\RegisterFlowChecksListener;
use OCA\MetaVox\Listener\SabrePluginAddListener;
use OCA\MetaVox\Middleware\AccessMiddleware;
use OCA\MetaVox\Middleware\ExceptionMiddleware;
use OCA\MetaVox\Search\MetadataSearchProvider;
use OCA\MetaVox\Service\AccountService;
use OCA\MetaVox\Service\DavMetadataService;
use OCA\MetaVox\Service\SavedSearchResults;
use OCA\MetaVox\Service\SavedSearchService;
use OCA\MetaVox\Service\SearchSettings;
use OCA\MetaVox\Service\DefaultsRequestCounter;
use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\GroupfolderResolver;
use OCA\MetaVox\Service\TeamFolderAccessService;
use OCA\MetaVox\Service\PresenceService;
use OCA\MetaVox\Service\UserFieldService;
use OCA\MetaVox\Service\ViewService;
use OCA\MetaVox\SetupCheck\BackgroundJobsCheck;
use OCA\MetaVox\SetupCheck\LiveUpdatesCheck;
use OCA\MetaVox\SetupCheck\PresenceCheck;
use OCA\MetaVox\SetupCheck\SearchIndexCheck;
use OCA\MetaVox\SetupCheck\SharedCacheCheck;
use OCA\MetaVox\SetupCheck\SqliteCheck;
use OCA\MetaVox\SetupCheck\TeamFoldersCheck;
use OCP\App\IAppManager;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Cache\CacheEntryRemovedEvent;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\User\Events\UserLiveStatusEvent;
use OCP\WorkflowEngine\Events\RegisterChecksEvent;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class Application extends App implements IBootstrap {
    /** Shown by Nextcloud in Administration settings, Overview, and by occ setupchecks. */
    public const SETUP_CHECKS = [
        SearchIndexCheck::class,
        BackgroundJobsCheck::class,
        SharedCacheCheck::class,
        LiveUpdatesCheck::class,
        PresenceCheck::class,
        TeamFoldersCheck::class,
        SqliteCheck::class,
    ];

    public const APP_ID = 'metavox';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        // Register search provider
        $context->registerSearchProvider(MetadataSearchProvider::class);
        foreach (self::SETUP_CHECKS as $check) {
            $context->registerSetupCheck($check);
        }

        // Controller exceptions become JSON errors without internal details.
        $context->registerMiddleware(ExceptionMiddleware::class);
        // The access gate: after ExceptionMiddleware, so its refusals unwind into it.
        $context->registerService(AccessContext::class, fn () => new AccessContext());
        $context->registerMiddleware(AccessMiddleware::class);

        // Register event listeners for file copy. FileCopyListener only handles
        // NodeCopiedEvent (it early-returns on anything else), so it is NOT
        // registered for NodeCreatedEvent — that would just waste a DI
        // instantiation per upload. New-file defaults are handled below.
        $context->registerEventListener(NodeCopiedEvent::class, FileCopyListener::class);

        // Apply per-folder default values to newly created files (fast-path).
        // DiscoverMissingDefaultsJob remains the source of truth; this only
        // reduces latency for normal uploads.
        $context->registerEventListener(NodeCreatedEvent::class, ApplyDefaultsListener::class);
        $context->registerEventListener(NodeRenamedEvent::class, ApplyDefaultsListener::class);

        // Shared per-request counter that throttles the defaults fast-path on
        // bulk uploads. Must be a singleton so all listener invocations in one
        // request share the same count.
        $context->registerService(DefaultsRequestCounter::class, function () {
            return new DefaultsRequestCounter();
        });

        // Clean up metadata when files are removed from filecache (trash emptied, etc.)
        $context->registerEventListener(CacheEntryRemovedEvent::class, CacheCleanupListener::class);

        // Register Flow (Workflow) checks for metadata-based conditions
        $context->registerEventListener(RegisterChecksEvent::class, RegisterFlowChecksListener::class);

        // Nextcloud's status heartbeat keeps the presence of an open grid alive.
        $context->registerEventListener(UserLiveStatusEvent::class, PresenceKeepAliveListener::class);

        // Grid values in the Files app PROPFIND (nc:metavox). The service
        // behind the plugin is only built for team folder listings.
        $context->registerEventListener(SabrePluginAddEvent::class, SabrePluginAddListener::class);
        $context->registerService(MetadataPropertyPlugin::class, static fn (ContainerInterface $c) => new MetadataPropertyPlugin(
            $c->get(IUserSession::class),
            $c->get(IAppManager::class),
            $c->get(GroupfolderResolver::class),
            static fn (): DavMetadataService => $c->get(DavMetadataService::class),
            $c->get(LoggerInterface::class),
        ));
        // Saved searches as folders in Files (REPORT); the search behind it is
        // only built for that report, not for Nextcloud's own ones.
        $context->registerService(SavedSearchReportPlugin::class, static fn (ContainerInterface $c) => new SavedSearchReportPlugin(
            $c->get(IUserSession::class),
            $c->get(IAppManager::class),
            $c->get(SavedSearchService::class),
            static fn (): SavedSearchResults => $c->get(SavedSearchResults::class),
            $c->get(LoggerInterface::class),
            $c->get(SearchSettings::class),
        ));

        // The occ command (metavox:apply-defaults) is registered via
        // appinfo/commands.php — IRegistrationContext has no registerCommand().
    }

    public function boot(IBootContext $context): void {
        // Skip request-dependent logic in CLI mode (occ commands, cron, etc.)
        if (\OC::$CLI) {
            return;
        }

        // Nothing for a page without a user, like the login page or a public
        // link: there is no search bar there, and no team folder.
        $user = \OCP\Server::get(IUserSession::class)->getUser();
        if ($user === null) {
            return;
        }

        // App icons follow core: app.svg is white (the header, theming), app-dark.svg is
        // dark (settings sections, the app list, search results). No metavox*.svg beside
        // them: AppManager::getAppIcon() would pick that one first.

        // Unified Search filter chip: the search bar exists on every page, not
        // just the Files app, so this bundle loads globally. It self-guards if
        // window.OCA.UnifiedSearch is unavailable. Only when the admin turned it
        // on; whether a filter may become a folder goes with the page, so a
        // click asks nothing.
        $search = \OCP\Server::get(SearchSettings::class);
        if ($search->filterOn()) {
            \OCP\Util::addScript('metavox', 'metavox-search');
            $this->getContainer()->get(\OCP\AppFramework\Services\IInitialState::class)
                ->provideInitialState('search-folders', $search->foldersOn());
        }

        $request = \OCP\Server::get(IRequest::class);
        if (!self::isFilesPage($request)) {
            return;
        }

        // Before the Files app: NC31/32 read file actions only once, at start.
        \OCP\Util::addInitScript('metavox', 'metavox-init');
        \OCP\Util::addScript('metavox', 'filesplugin');
        \OCP\Util::addStyle('metavox', 'files');

        // Inline init data for the current groupfolder so the JS has everything at startup
        try {
            $dir = self::requestedDir($request);
            $userId = $user->getUID();

            $userFieldService = \OCP\Server::get(UserFieldService::class);
            $groupfolders = $userFieldService->getAccessibleGroupfolders($userId);

            $groupfolderId = null;
            $path = ltrim($dir, '/');
            foreach ($groupfolders as $gf) {
                $mp = $gf['mount_point'] ?? '';
                if ($mp !== '' && ($path === $mp || str_starts_with($path, $mp . '/'))) {
                    $groupfolderId = (int)$gf['id'];
                    break;
                }
            }

            $initData = [
                'groupfolders' => $groupfolders,
                'groupfolder_id' => $groupfolderId,
            ];

            // "Assigned to you" counts your groups and teams too, where a field takes them.
            if (AccountService::takesGroupsOrTeams(\OCP\Server::get(FieldService::class)->getAllFields())) {
                $initData['viewer_items'] = \OCP\Server::get(AccountService::class)->viewerItems($user);
            }

            if ($groupfolderId !== null) {
                $fieldService = \OCP\Server::get(FieldService::class);

                // Register presence (30 min TTL)
                $presenceService = \OCP\Server::get(PresenceService::class);
                $presenceService->register($groupfolderId, $userId);
                $viewService = \OCP\Server::get(ViewService::class);

                $gfData = [
                    'fields' => $fieldService->getAssignedFileFieldsForGroupfolder($groupfolderId),
                    'views' => $viewService->getViewsForGroupfolder($groupfolderId),
                    // May edit views: the same rule as view#init and the view routes.
                    'can_manage' => \OCP\Server::get(TeamFolderAccessService::class)->canUpdateTeamFolder($userId, $groupfolderId),
                ];

                $initData['all_gf_data'] = [
                    $groupfolderId => $gfData,
                ];
            }

            $initialState = $this->getContainer()->get(\OCP\AppFramework\Services\IInitialState::class);
            $initialState->provideInitialState('init', $initData);
        } catch (\Exception $e) {
            // Silently fail — JS will fall back to API call
        }
    }

    /**
     * Whether this is a Files page. The path only: the login page's
     * redirect_url=/index.php/apps/files/… is not one. Both the request URI
     * (direct access) and the path info (behind a reverse proxy with a subpath).
     */
    public static function isFilesPage(IRequest $request): bool {
        $path = (string)parse_url($request->getRequestUri(), PHP_URL_PATH);
        return str_contains($path, '/apps/files')
            || str_contains((string)($request->getPathInfo() ?: ''), '/apps/files')
            || $request->getParam('app') === 'files';
    }

    /**
     * The folder the Files page opens. Behind Apache's 404 fallback (pretty URLs
     * without a rewrite rule) PHP gets no query string, but the request URI has it.
     */
    public static function requestedDir(IRequest $request): string {
        $dir = $request->getParam('dir');
        if (is_string($dir) && $dir !== '') {
            return $dir;
        }
        parse_str((string)parse_url($request->getRequestUri(), PHP_URL_QUERY), $query);
        return is_string($query['dir'] ?? null) ? $query['dir'] : '';
    }
}
