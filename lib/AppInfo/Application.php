<?php

declare(strict_types=1);

namespace OCA\MetaVox\AppInfo;

use OCA\MetaVox\Listener\ApplyDefaultsListener;
use OCA\MetaVox\Listener\CacheCleanupListener;
use OCA\MetaVox\Listener\FileCopyListener;
use OCA\MetaVox\Listener\RegisterFlowChecksListener;
use OCA\MetaVox\Search\MetadataSearchProvider;
use OCA\MetaVox\Service\DefaultsRequestCounter;
use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\FilterService;
use OCA\MetaVox\Service\PermissionService;
use OCA\MetaVox\Service\PresenceService;
use OCA\MetaVox\Service\UserFieldService;
use OCA\MetaVox\Service\ViewService;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Cache\CacheEntryRemovedEvent;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\WorkflowEngine\Events\RegisterChecksEvent;

class Application extends App implements IBootstrap {
    public const APP_ID = 'metavox';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        // Register search provider
        $context->registerSearchProvider(MetadataSearchProvider::class);

        // Register event listeners for file copy. FileCopyListener only handles
        // NodeCopiedEvent (it early-returns on anything else), so it is NOT
        // registered for NodeCreatedEvent — that would just waste a DI
        // instantiation per upload. New-file defaults are handled below.
        $context->registerEventListener(NodeCopiedEvent::class, FileCopyListener::class);

        // Apply per-folder default values to newly created files (fast-path).
        // DiscoverMissingDefaultsJob remains the source of truth; this only
        // reduces latency for normal uploads.
        $context->registerEventListener(NodeCreatedEvent::class, ApplyDefaultsListener::class);

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

        // The occ command (metavox:apply-defaults) is registered via
        // appinfo/commands.php — IRegistrationContext has no registerCommand().
    }

    public function boot(IBootContext $context): void {
        // Skip request-dependent logic in CLI mode (occ commands, cron, etc.)
        if (\OC::$CLI) {
            return;
        }

        // Icon styling handled by NC via app.svg (#000) and app-dark.svg (#fff)

        // Unified Search filter chip: the search bar exists on every page, not
        // just the Files app, so this bundle loads globally. It self-guards if
        // window.OCA.UnifiedSearch is unavailable.
        \OCP\Util::addScript('metavox', 'metavox-search');

        // Load Files app integration only when needed
        // NC34 removed \OC::$server->getRequest() — use container lookup instead.
        $request = \OC::$server->get(IRequest::class);
        $requestUri = $request->getRequestUri();

        // Also check pathInfo for reverse proxy setups with subpaths
        // getPathInfo() returns the Nextcloud-specific path without proxy prefix
        $pathInfo = $request->getPathInfo() ?? '';

        // Check URL patterns for Files app using PHP 8 str_contains
        // Check both requestUri (direct access) and pathInfo (behind reverse proxy)
        $isFilesApp = (
            str_contains($requestUri, '/apps/files') ||
            str_contains($requestUri, '/index.php/apps/files') ||
            str_contains($pathInfo, '/apps/files') ||
            (($_GET['app'] ?? '') === 'files') ||
            (($_POST['app'] ?? '') === 'files')
        );

        // Only load scripts when in Files app
        if ($isFilesApp) {
            \OCP\Util::addScript('metavox', 'filesplugin');
            \OCP\Util::addStyle('metavox', 'files');

            // Inline init data for the current groupfolder so the JS has everything at startup
            try {
                $user = \OC::$server->get(IUserSession::class)->getUser();
                if ($user) {
                    $dir = $_GET['dir'] ?? '';
                    $userId = $user->getUID();

                    $userFieldService = \OC::$server->get(UserFieldService::class);
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

                    if ($groupfolderId !== null) {
                        $fieldService = \OC::$server->get(FieldService::class);

                        // Register presence (30 min TTL)
                        $presenceService = \OC::$server->get(PresenceService::class);
                        $presenceService->register($groupfolderId, $userId);
                        $viewService = \OC::$server->get(ViewService::class);
                        $permissionService = \OC::$server->get(PermissionService::class);

                        $gfData = [
                            'fields' => $fieldService->getAssignedFileFieldsForGroupfolder($groupfolderId),
                            'views' => $viewService->getViewsForGroupfolder($groupfolderId),
                            'can_manage' => $permissionService->hasPermission(
                                $userId, PermissionService::PERM_MANAGE_FIELDS, $groupfolderId
                            ),
                        ];

                        // Prefetch directory metadata for instant cell rendering
                        if ($dir !== '') {
                            try {
                                $rootFolder = \OC::$server->get(\OCP\Files\IRootFolder::class);
                                $userFolder = $rootFolder->getUserFolder($userId);
                                $dirNode = $userFolder->get($dir);
                                if ($dirNode instanceof \OCP\Files\Folder) {
                                    $children = $dirNode->getDirectoryListing();
                                    $fileIds = array_map(fn($n) => $n->getId(), $children);
                                    if (!empty($fileIds) && count($fileIds) <= 100) {
                                        $filterService = \OC::$server->get(FilterService::class);
                                        $directoryMetadata = $filterService->getDirectoryMetadata($fileIds, $groupfolderId);
                                        $gfData['directory_metadata'] = $directoryMetadata;

                                        // Resolve current names for any file-link references so
                                        // the grid shows live names without per-cell API calls.
                                        // Map: fileid => current display name. JS falls back to
                                        // the cached path when an id is absent (stale / no access).
                                        $gfData['filelink_resolved'] = $this->resolveFilelinkNames(
                                            $directoryMetadata,
                                            $gfData['fields'] ?? [],
                                            $userId
                                        );
                                    }
                                }
                            } catch (\Exception $e) {
                                // Skip — JS will fetch via API
                            }
                        }

                        $initData['all_gf_data'] = [
                            $groupfolderId => $gfData,
                        ];
                    }

                    $initialState = $this->getContainer()->get(\OCP\AppFramework\Services\IInitialState::class);
                    $initialState->provideInitialState('init', $initData);
                }
            } catch (\Exception $e) {
                // Silently fail — JS will fall back to API call
            }
        }
    }

    /**
     * Build a fileid => current-name map for every file-link reference in the
     * prefetched directory metadata, so the grid renders live names without a
     * round-trip per cell. Returns an empty array on any failure (the JS falls
     * back to the cached path baked into the stored value).
     *
     * @param array<int, array<string, string>> $directoryMetadata fileId => [field => value]
     * @param array<int, array<string, mixed>>  $fields            assigned file fields
     * @return array<int, string> referenced fileId => current display name
     */
    private function resolveFilelinkNames(array $directoryMetadata, array $fields, string $userId): array {
        // Which assigned fields are file-link types?
        $filelinkFieldNames = [];
        foreach ($fields as $field) {
            if (in_array($field['field_type'] ?? '', \OCA\MetaVox\Service\FileReferenceService::FILELINK_TYPES, true)) {
                $filelinkFieldNames[(string)$field['field_name']] = true;
            }
        }
        if (empty($filelinkFieldNames)) {
            return [];
        }

        // Collect referenced fileids from those columns' values.
        $referencedIds = [];
        foreach ($directoryMetadata as $fields_) {
            foreach ($fields_ as $fieldName => $value) {
                if (!isset($filelinkFieldNames[$fieldName]) || $value === null || $value === '') {
                    continue;
                }
                foreach (\OCA\MetaVox\Service\FileReferenceService::parseValue((string)$value) as $token) {
                    if ($token['fileId'] !== null) {
                        $referencedIds[] = $token['fileId'];
                    }
                }
            }
        }
        if (empty($referencedIds)) {
            return [];
        }

        try {
            $refService = \OC::$server->get(\OCA\MetaVox\Service\FileReferenceService::class);
            $resolved = $refService->resolveMany($referencedIds, $userId);
        } catch (\Throwable $e) {
            return [];
        }

        $map = [];
        foreach ($resolved as $id => $info) {
            $map[$id] = $info['name'];
        }
        return $map;
    }
}