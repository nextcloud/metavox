<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use PHPUnit\Framework\TestCase;

/**
 * Static regression guard against using Nextcloud QueryBuilder methods that do
 * not exist in the supported NC versions (31–34).
 *
 * Background: an early version of DefaultsService used $qb->expr()->notExists(),
 * which is NOT part of NC's IExpressionBuilder and crashed at runtime only when
 * the discovery query actually executed (on Postgres). The editor's stub
 * warnings hide this class of bug. This test scans the app's PHP source for the
 * specific non-existent expression methods so the mistake can't silently return.
 *
 * It is intentionally source-text based (no DB, no NC runtime) so it runs in the
 * pure unit suite.
 */
class QueryBuilderApiTest extends TestCase {

    /** Methods that look plausible but are NOT on NC's IExpressionBuilder. */
    private const FORBIDDEN_EXPR_METHODS = [
        'notExists',
        'exists',
    ];

    public function testNoForbiddenExpressionMethodsInLib(): void {
        $libDir = __DIR__ . '/../../../lib';
        $offenders = [];

        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($libDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($rii as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            foreach (self::FORBIDDEN_EXPR_METHODS as $method) {
                // Match ->expr()->notExists( with optional whitespace.
                if (preg_match('/->\s*expr\s*\(\s*\)\s*->\s*' . preg_quote($method, '/') . '\s*\(/', $contents)) {
                    $offenders[] = $file->getPathname() . ' uses ->expr()->' . $method . '()';
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Found IExpressionBuilder methods that do not exist in NC 31–34. "
            . "Build NOT EXISTS / EXISTS as a raw SQL fragment via createFunction() instead:\n"
            . implode("\n", $offenders)
        );
    }

    /**
     * Regression guard: IJobList::countByClass() takes NO argument and returns
     * a list of [class, count] pairs for ALL classes — not an int for one
     * class. Calling it with an argument (and treating the result as an int)
     * silently broke discovery backpressure. Forbid the arg-passing form.
     */
    public function testCountByClassCalledWithoutArgument(): void {
        $libDir = __DIR__ . '/../../../lib';
        $offenders = [];

        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($libDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($rii as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            // Match countByClass( with any non-")" character right after — i.e.
            // an argument was passed. countByClass() with empty parens is fine.
            if (preg_match('/countByClass\s*\(\s*[^)\s]/', $contents)) {
                $offenders[] = $file->getPathname() . ' calls countByClass() with an argument';
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "IJobList::countByClass() takes no argument and returns a list of "
            . "[class, count] pairs. Scan that list for your class instead:\n"
            . implode("\n", $offenders)
        );
    }
}
