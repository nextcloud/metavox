<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Controller;

use OCA\MetaVox\Access\Access;
use OCA\MetaVox\Access\RequireAccess;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The access rule of every route, as a snapshot: a new or changed rule has to
 * be changed here too, so it shows up in review.
 */
class AccessMapTest extends TestCase {

    private const EXPECTED = [
        // Web routes
        'field#getGroupfolderFields' => 'nc_admin',
        'field#createGroupfolderField' => 'nc_admin',
        'field#updateGroupfolderField' => 'nc_admin',
        'field#deleteGroupfolderField' => 'nc_admin',
        'field#bulkDeleteFields' => 'nc_admin',
        'field#getGroupfolders' => 'logged_in',
        'field#getGroupfolderMetadata' => 'team_folder_member folder:groupfolderId',
        'field#saveGroupfolderMetadata' => 'can_update_team_folder folder:groupfolderId',
        'field#getFileMetadata' => 'can_read file:fileId',
        'field#saveFileMetadata' => 'can_update file:fileId',
        'field#getFileBacklinks' => 'can_read folder:groupfolderId file:fileId',
        'field#saveBulkFileMetadata' => 'file_list',
        'field#clearFileMetadata' => 'file_list',
        'field#exportFileMetadata' => 'file_list',
        'field#getGroupfolderAssignedFields' => 'team_folder_member folder:groupfolderId',
        'field#setGroupfolderFields' => 'metavox_manager folder:groupfolderId',
        'field#getGroupfolderFileFields' => 'team_folder_member folder:groupfolderId',
        'defaults#getDefaults' => 'metavox_manager folder:groupfolderId',
        'defaults#setDefault' => 'metavox_manager folder:groupfolderId',
        'defaults#trigger' => 'metavox_manager folder:groupfolderId',
        'defaults#status' => 'metavox_manager folder:groupfolderId',
        'user_field#getAccessibleGroupfolders' => 'logged_in',
        'user_field#getGroupfolderFields' => 'team_folder_member folder:groupfolderId',
        'user_field#getFieldValues' => 'team_folder_member folder:groupfolderId',
        'user_field#getAllGroupfolderFields' => 'logged_in',
        'user_field#getGroupfolderMetadata' => 'team_folder_member folder:groupfolderId',
        'user_field#setGroupfolderFields' => 'metavox_manager folder:groupfolderId',
        'metadata_search#index' => 'logged_in',
        'metadata_search#create' => 'logged_in',
        'metadata_search#destroy' => 'logged_in',
        'field#getAccounts' => 'logged_in',
        'permission#getGroups' => 'nc_admin',
        'permission#getAllPermissions' => 'nc_admin',
        'permission#grantUserPermission' => 'nc_admin',
        'permission#grantGroupPermission' => 'nc_admin',
        'permission#revokeUserPermission' => 'nc_admin',
        'permission#revokeGroupPermission' => 'nc_admin',
        'settings#get' => 'nc_admin',
        'settings#save' => 'nc_admin',
        'license#getStats' => 'nc_admin',
        'license#saveSettings' => 'nc_admin',
        'license#validate' => 'nc_admin',
        'license#updateUsage' => 'nc_admin',
        'telemetry#getStatus' => 'nc_admin',
        'telemetry#getStats' => 'nc_admin',
        'telemetry#sendTelemetry' => 'nc_admin',
        'telemetry#saveSettings' => 'nc_admin',
        'view#listViews' => 'team_folder_member folder:gfId',
        'view#createView' => 'can_update_team_folder folder:gfId',
        'view#reorderViews' => 'can_update_team_folder folder:gfId',
        'view#updateView' => 'can_update_team_folder folder:gfId',
        'view#deleteView' => 'can_update_team_folder folder:gfId',
        'view#init' => 'logged_in',
        'filter#getDirectoryMetadata' => 'file_list folder:groupfolderId',
        'lock#lockCell' => 'can_update folder:groupfolderId file:fileId',
        'lock#unlockCell' => 'can_read folder:groupfolderId file:fileId',
        'presence#leave' => 'logged_in',
        'aiAutofill#status' => 'logged_in',
        'aiAutofill#generate' => 'can_update folder:groupfolderId file:fileId',
        'backup#list' => 'nc_admin',
        'backup#trigger' => 'nc_admin',
        'backup#restore' => 'nc_admin',
        'backup#download' => 'nc_admin',
        'backup#status' => 'nc_admin',

        // OCS routes
        'apiField#getFileMetadata' => 'can_read file:fileId',
        'apiField#saveFileMetadata' => 'can_update file:fileId',
        'api_field#getBulkFileMetadata' => 'file_list',
        'apiField#getGroupfolders' => 'logged_in',
        'apiField#getGroupfolderMetadata' => 'team_folder_member folder:groupfolderId',
        'apiField#saveGroupfolderMetadata' => 'can_update_team_folder folder:groupfolderId',
        'apiField#getGroupfolderFields' => 'logged_in',
        'apiField#createGroupfolderField' => 'nc_admin',
        'apiField#getGroupfolderFileMetadata' => 'can_read folder:groupfolderId file:fileId',
        'apiField#saveGroupfolderFileMetadata' => 'can_update folder:groupfolderId file:fileId',
        'apiField#getGroupfolderAssignedFields' => 'team_folder_member folder:groupfolderId',
        'apiField#setGroupfolderFields' => 'metavox_manager folder:groupfolderId',
        'apiField#batchUpdateFileMetadata' => 'file_list',
        'apiField#batchDeleteFileMetadata' => 'file_list',
        'apiField#batchCopyFileMetadata' => 'file_list',
        'apiField#getMetadataStatistics' => 'nc_admin',
        'apiField#getGroupfolderFileFields' => 'team_folder_member folder:groupfolderId',
        'apiFilter#getDirectoryMetadata' => 'file_list folder:groupfolderId',
        'apiFilter#getAllFilterValues' => 'team_folder_member folder:groupfolderId',
        'apiFilter#getScopedFilterValues' => 'team_folder_member folder:groupfolderId',
        'apiFilter#getSortedFileIds' => 'team_folder_member folder:groupfolderId',
        'apiField#updateGroupfolderField' => 'nc_admin',
        'apiField#deleteGroupfolderField' => 'nc_admin',
        'apiView#listViews' => 'team_folder_member folder:groupfolderId',
        'apiView#createView' => 'can_update_team_folder folder:groupfolderId',
        'apiView#reorderViews' => 'can_update_team_folder folder:groupfolderId',
        'apiView#updateView' => 'can_update_team_folder folder:groupfolderId',
        'apiView#deleteView' => 'can_update_team_folder folder:groupfolderId',
    ];

    /**
     * Web and OCS routes that do the same thing share a level. Not twins: the
     * web field catalogue belongs to the admin page, the OCS one is for everyone.
     */
    private const TWINS = [
        'field#getGroupfolders' => 'apiField#getGroupfolders',
        'field#getGroupfolderMetadata' => 'apiField#getGroupfolderMetadata',
        'field#saveGroupfolderMetadata' => 'apiField#saveGroupfolderMetadata',
        'field#getFileMetadata' => 'apiField#getFileMetadata',
        'field#saveFileMetadata' => 'apiField#saveFileMetadata',
        'field#getGroupfolderAssignedFields' => 'apiField#getGroupfolderAssignedFields',
        'field#setGroupfolderFields' => 'apiField#setGroupfolderFields',
        'field#getGroupfolderFileFields' => 'apiField#getGroupfolderFileFields',
        'field#createGroupfolderField' => 'apiField#createGroupfolderField',
        'field#updateGroupfolderField' => 'apiField#updateGroupfolderField',
        'field#deleteGroupfolderField' => 'apiField#deleteGroupfolderField',
        'user_field#getAllGroupfolderFields' => 'apiField#getGroupfolderFields',
        'field#exportFileMetadata' => 'api_field#getBulkFileMetadata',
        'field#clearFileMetadata' => 'apiField#batchDeleteFileMetadata',
        'filter#getDirectoryMetadata' => 'apiFilter#getDirectoryMetadata',
        'view#listViews' => 'apiView#listViews',
        'view#createView' => 'apiView#createView',
        'view#reorderViews' => 'apiView#reorderViews',
        'view#updateView' => 'apiView#updateView',
        'view#deleteView' => 'apiView#deleteView',
    ];

    /** Routes that take an id from the request body instead of the URL. */
    private const BODY_PARAMS = [
        'aiAutofill#generate' => ['fileId', 'groupfolderId'],
    ];

    /** @return array<string, array{url: string, method: ?\ReflectionMethod}> */
    private static function routes(): array {
        $routes = require __DIR__ . '/../../../appinfo/routes.php';
        $all = [];
        foreach (['routes', 'ocs'] as $kind) {
            foreach ($routes[$kind] as $route) {
                if (isset($all[$route['name']])) {
                    throw new \LogicException('Route name used twice: ' . $route['name']);
                }
                [$controller, $method] = explode('#', $route['name']);
                // Nextcloud's naming: api_field and apiField both mean ApiFieldController.
                $class = 'OCA\\MetaVox\\Controller\\' . ucfirst(str_replace('_', '', ucwords($controller, '_'))) . 'Controller';
                $all[$route['name']] = [
                    'url' => $route['url'],
                    'method' => method_exists($class, $method) ? new \ReflectionMethod($class, $method) : null,
                ];
            }
        }
        return $all;
    }

    private static function rule(\ReflectionMethod $method): ?RequireAccess {
        $rules = $method->getAttributes(RequireAccess::class);
        return $rules === [] ? null : $rules[0]->newInstance();
    }

    public function testEveryRouteHasTheExpectedRule(): void {
        $actual = [];
        foreach (self::routes() as $name => $route) {
            $rule = $route['method'] === null ? null : self::rule($route['method']);
            $actual[$name] = match (true) {
                $route['method'] === null => 'no controller',
                $rule === null => null,
                default => trim($rule->level->value
                    . ($rule->folder !== null ? ' folder:' . $rule->folder : '')
                    . ($rule->file !== null ? ' file:' . $rule->file : '')),
            };
        }
        $expected = self::EXPECTED;
        ksort($expected);
        ksort($actual);

        $this->assertSame($expected, $actual);
    }

    public static function routesWithRule(): array {
        $cases = [];
        foreach (self::routes() as $name => $route) {
            if ($route['method'] !== null && self::rule($route['method']) !== null) {
                $cases[$name] = [$name, $route['url'], $route['method']];
            }
        }
        return $cases;
    }

    #[DataProvider('routesWithRule')]
    public function testRuleIsComplete(string $name, string $url, \ReflectionMethod $method): void {
        $this->assertCount(1, $method->getAttributes(RequireAccess::class), 'one rule per route');
        $rule = self::rule($method);

        // Each level gets the parameters it checks, and no others.
        $needsFolder = in_array($rule->level, [Access::TeamFolderMember, Access::CanUpdateTeamFolder, Access::MetaVoxManager], true);
        $mayHaveFolder = $needsFolder || in_array($rule->level, [Access::CanRead, Access::CanUpdate, Access::FileList], true);
        $needsFile = in_array($rule->level, [Access::CanRead, Access::CanUpdate], true);
        if ($needsFolder) {
            $this->assertNotNull($rule->folder, 'the level needs a folder parameter');
        } elseif (!$mayHaveFolder) {
            $this->assertNull($rule->folder, 'the level does not use a folder parameter');
        }
        $this->assertSame($needsFile, $rule->file !== null, 'only file levels have a file parameter');

        // Only a route open to everyone says why that is safe.
        $this->assertSame($rule->level === Access::LoggedIn, trim((string)$rule->reason) !== '', 'reason');

        // Nextcloud's own admin check has to agree with the level.
        $this->assertSame($rule->level !== Access::NcAdmin, $method->getAttributes(NoAdminRequired::class) !== [], '#[NoAdminRequired]');

        // The parameters the rule names actually reach the request.
        preg_match_all('/\{(\w+)\}/', $url, $matches);
        $available = array_merge($matches[1], self::BODY_PARAMS[$name] ?? []);
        foreach (array_filter([$rule->folder, $rule->file]) as $param) {
            $this->assertContains($param, $available, "$param is not in the URL or the body");
        }
    }

    public function testRulesSitOnlyOnRoutes(): void {
        $routed = [];
        foreach (self::routes() as $route) {
            if ($route['method'] !== null) {
                $routed[] = $route['method']->class . '::' . $route['method']->name;
            }
        }

        // A rule on a method Nextcloud never calls directly protects nothing.
        foreach (glob(__DIR__ . '/../../../lib/Controller/*.php') as $file) {
            $class = new \ReflectionClass('OCA\\MetaVox\\Controller\\' . basename($file, '.php'));
            foreach ($class->getMethods() as $method) {
                if ($method->class === $class->name && $method->getAttributes(RequireAccess::class) !== []) {
                    $this->assertContains($method->class . '::' . $method->name, $routed);
                }
            }
        }
    }

    /** @return list<string> "Class::method" of every route */
    private static function routedMethods(): array {
        return array_values(array_filter(array_map(
            fn (array $route) => $route['method'] === null ? null : $route['method']->class . '::' . $route['method']->name,
            self::routes(),
        )));
    }

    public function testEveryRouteHasAMethodAndARule(): void {
        foreach (self::routes() as $name => $route) {
            $this->assertNotNull($route['method'], "$name has no controller method");
            $this->assertNotNull(self::rule($route['method']), "$name has no access rule");
        }
    }

    public function testEveryPublicControllerMethodIsARoute(): void {
        $routed = self::routedMethods();
        // An unrouted public action is a door nobody checks.
        foreach (glob(__DIR__ . '/../../../lib/Controller/*.php') as $file) {
            $class = new \ReflectionClass('OCA\\MetaVox\\Controller\\' . basename($file, '.php'));
            if ($class->isAbstract()) {
                continue;
            }
            foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->class === $class->name && !$method->isConstructor() && !$method->isStatic()) {
                    $this->assertContains($method->class . '::' . $method->name, $routed);
                }
            }
        }
    }

    public function testNoRouteCallsAnotherRoute(): void {
        $routed = self::routedMethods();
        // One route running another would skip the other's rule.
        foreach (self::routes() as $name => $route) {
            $method = $route['method'];
            $lines = array_slice(file($method->getFileName()), $method->getStartLine(), $method->getEndLine() - $method->getStartLine());
            preg_match_all('/\$this->(\w+)\(/', implode('', $lines), $calls);
            foreach ($calls[1] as $called) {
                $this->assertNotContains($method->class . '::' . $called, $routed, "$name calls the route $called");
            }
        }
    }

    public function testTwinsShareALevel(): void {
        $routes = self::routes();
        foreach (self::TWINS as $web => $ocs) {
            $this->assertSame(self::rule($routes[$web]['method'])->level, self::rule($routes[$ocs]['method'])->level, "$web and $ocs");
        }
    }

    public function testControllersLeaveRefusingToTheGate(): void {
        // Rights are refused in one place; a controller only checks what the rule
        // can't: every file of a list, and what a LoggedIn route returns.
        foreach (glob(__DIR__ . '/../../../lib/Controller/*.php') as $file) {
            $source = file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('/MetadataAccessException::(forbidden|notMember|notManager)\(/', $source, basename($file));
            $this->assertDoesNotMatchRegularExpression('/if \(!\$this->isAdmin\(\)\)/', $source, basename($file));
        }
    }
}
