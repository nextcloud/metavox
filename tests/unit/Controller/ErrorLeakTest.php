<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Exception texts can carry SQL, paths or provider errors, so controllers leave
 * errors to ExceptionMiddleware. Only our own access messages may be shown.
 */
class ErrorLeakTest extends TestCase {

    private const LIB = __DIR__ . '/../../../lib';

    public function testControllersOnlyShowAccessErrorMessages(): void {
        $offenders = [];
        foreach (glob(self::LIB . '/Controller/*.php') as $file) {
            $source = file_get_contents($file);
            preg_match_all('/\$(\w+)->(?:getMessage|getTraceAsString)\(\)/', $source, $calls, PREG_OFFSET_CAPTURE);
            foreach ($calls[1] as [$variable, $offset]) {
                $before = substr($source, 0, $offset);
                preg_match_all('/catch\s*\(([^)]*?)\$' . $variable . '\s*\)/', $before, $catches);
                $caught = trim((string)end($catches[1]));
                // InvalidValue: a fixed text saying what a field takes.
                if (!in_array($caught, ['MetadataAccessException', 'InvalidValue'], true)) {
                    $offenders[] = basename($file) . ':' . (substr_count($before, "\n") + 1) . ' shows the message of ' . ($caught ?: 'an unknown exception');
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", $offenders));
    }

    /** Our own exceptions carry texts meant for the caller; every other text stays in the log. */
    private const OWN_EXCEPTIONS = ['MetadataAccessException', 'RequestException', 'ClientErrorException', 'AiAutofillException', 'ApiValidationException', 'InvalidValue'];

    public function testOutsideTheLogOnlyOurOwnMessagesAreShown(): void {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::LIB));
        foreach ($files as $file) {
            $path = $file->getPathname();
            // The middleware shows our own exceptions and checks their type itself.
            if (!str_ends_with($path, '.php') || str_ends_with($path, 'Middleware/ExceptionMiddleware.php')) {
                continue;
            }
            $source = file_get_contents($path);
            preg_match_all('/\$(\w+)->getMessage\(\)/', $source, $calls, PREG_OFFSET_CAPTURE);
            foreach ($calls[1] as [$variable, $offset]) {
                $before = substr($source, 0, $offset);
                $statement = substr($before, max((int)strrpos($before, ';'), (int)strrpos($before, '{'), (int)strrpos($before, '}')));
                // The log, and a migration's output to the admin running the update.
                if (str_contains($statement, '->logger->') || str_contains($statement, '$output->')) {
                    continue;
                }
                preg_match_all('/catch\s*\(([^)]*?)\$' . $variable . '\s*\)/', $before, $catches);
                $types = array_map(static fn(string $type) => substr(strrchr('\\' . trim($type), '\\'), 1), explode('|', (string)end($catches[1])));
                if (array_diff($types, self::OWN_EXCEPTIONS) !== []) {
                    $offenders[] = substr($path, strlen(self::LIB) + 1) . ':' . (substr_count($before, "\n") + 1);
                }
            }
        }

        $this->assertSame([], $offenders, 'exception text outside the log: ' . implode(', ', $offenders));
    }

    public function testExceptionMiddlewareIsRegistered(): void {
        $this->assertStringContainsString(
            'registerMiddleware(ExceptionMiddleware::class)',
            file_get_contents(self::LIB . '/AppInfo/Application.php'),
        );
    }
}
