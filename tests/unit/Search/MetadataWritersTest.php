<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Search;

use PHPUnit\Framework\TestCase;

/**
 * Free-text search reads the search words, so every write to the file
 * metadata takes its words along. A new write fails here until it is listed
 * with how its words follow.
 */
class MetadataWritersTest extends TestCase {

    private const WITH_WORDS = [
        'lib/Service/FieldService.php' => 'saves, clears and deletes fields through SearchWordsService',
        'lib/Service/DefaultsService.php' => 'takes the words of the defaulted files from what is stored',
        'lib/Listener/CacheCleanupListener.php' => 'removes the words of a removed file',
        'lib/BackgroundJobs/CleanupDeletedMetadata.php' => 'removes the words of deleted files',
        'lib/Controller/BackupController.php' => 'builds all words again after a restore',
    ];

    private const WITHOUT_WORDS = [
        'lib/Service/FileLinkPathCleanup.php' => 'changes only file links, which have no words',
    ];

    private const WRITE = '/->(?:insert|update|delete)\(\s*[\'"]metavox_file_gf_meta[\'"]|(?:INSERT INTO|DELETE FROM|UPDATE)\s+\*PREFIX\*metavox_file_gf_meta\b/';
    // A table name in a variable, as in a restore that loops over tables.
    private const VARIABLE_WRITE = '/->(?:insert|update|delete)\(\s*\$\w+\s*\)|TRUNCATE TABLE|INSERT INTO \{/';

    public function testEveryWriteIsListed(): void {
        $listed = array_keys(self::WITH_WORDS + self::WITHOUT_WORDS);
        sort($listed);

        $this->assertSame($listed, $this->writers(), 'a write to metavox_file_gf_meta: list it in MetadataWritersTest with how its search words follow');
    }

    public function testThoseWithWordsKeepThemUpToDate(): void {
        foreach (self::WITH_WORDS as $file => $how) {
            $this->assertStringContainsString('searchWords', file_get_contents($this->root() . '/' . $file), $file . ' ' . $how);
        }
    }

    /** The caches of values (search filter, filter options, sorted ids) are only fresh when every write says so. */
    public function testEveryWriteChangesTheVersion(): void {
        foreach (array_keys(self::WITH_WORDS + self::WITHOUT_WORDS) as $file) {
            $this->assertMatchesRegularExpression('/->versions->bump(All)?\(/', file_get_contents($this->root() . '/' . $file), $file . ' writes metadata without bumping MetadataVersions');
        }
    }

    public function testThePatternsFindWrites(): void {
        $this->assertMatchesRegularExpression(self::WRITE, "\$qb->delete('metavox_file_gf_meta')");
        $this->assertMatchesRegularExpression(self::WRITE, "INSERT INTO *PREFIX*metavox_file_gf_meta (file_id)");
        $this->assertMatchesRegularExpression(self::WRITE, "UPDATE *PREFIX*metavox_file_gf_meta SET");
        $this->assertDoesNotMatchRegularExpression(self::WRITE, "->from('metavox_file_gf_meta')");
        $this->assertMatchesRegularExpression(self::VARIABLE_WRITE, '$update->update($table)');
        $this->assertDoesNotMatchRegularExpression(self::VARIABLE_WRITE, "\$qb->update('metavox_gf_assigns')");
    }

    /** @return list<string> */
    private function writers(): array {
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root() . '/lib'));
        foreach ($files as $file) {
            $path = substr($file->getPathname(), strlen($this->root()) + 1);
            if (!str_ends_with($path, '.php') || str_starts_with($path, 'lib/Migration/')) {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            if (preg_match(self::WRITE, $source)
                || (str_contains($source, 'metavox_file_gf_meta') && preg_match(self::VARIABLE_WRITE, $source))) {
                $found[] = $path;
            }
        }
        sort($found);
        return $found;
    }

    private function root(): string {
        return dirname(__DIR__, 3);
    }
}
