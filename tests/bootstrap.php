<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for MetaVox unit tests.
 *
 * These are pure unit tests: no database, no running Nextcloud. They only need
 * the OCP interfaces to exist so PHPUnit can mock them.
 *
 * Two ways the OCP interfaces become available:
 *  1. The `nextcloud/ocp` dev-dependency provides stubs (preferred, works on
 *     any machine with `composer install`).
 *  2. When the suite runs inside a Nextcloud install (e.g. the app deployed in
 *     a container), the real OCP classes are already on disk — we register a
 *     small autoloader for them as a fallback.
 *
 * Run:  composer install && composer test
 */

require_once __DIR__ . '/../vendor/autoload.php';

// The nextcloud/ocp stub package ships the OCP interfaces but declares no
// autoload section of its own, so composer never maps the OCP\ namespace and
// every mock of an OCP interface fails with "Class or interface does not
// exist". Register the stub directory ourselves.
$ocpStubDir = __DIR__ . '/../vendor/nextcloud/ocp/OCP';
if (!interface_exists(\OCP\IDBConnection::class) && is_dir($ocpStubDir)) {
    spl_autoload_register(static function (string $class) use ($ocpStubDir): void {
        if (strncmp($class, 'OCP\\', 4) !== 0) {
            return;
        }
        $file = $ocpStubDir . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}

// Fallback: if the OCP interfaces are still not autoloadable, but we are
// running inside a Nextcloud install, use the server's own autoloaders. NC's
// 3rdparty autoloader provides Doctrine (which OCP\DB interfaces reference),
// and a small OCP autoloader maps the public API classes — together they
// satisfy everything the mocks need.
if (!interface_exists(\OCP\IDBConnection::class)) {
    foreach (['/var/www/html', __DIR__ . '/../../..'] as $ncRoot) {
        $ocpDir = $ncRoot . '/lib/public';
        if (!is_dir($ocpDir)) {
            continue;
        }
        // Doctrine + other 3rdparty deps referenced by the OCP interfaces.
        if (is_file($ncRoot . '/3rdparty/autoload.php')) {
            require_once $ncRoot . '/3rdparty/autoload.php';
        }
        // Map the OCP\ namespace to the server's public API directory.
        spl_autoload_register(static function (string $class) use ($ocpDir): void {
            if (strncmp($class, 'OCP\\', 4) !== 0) {
                return;
            }
            $file = $ocpDir . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
        break;
    }
}
