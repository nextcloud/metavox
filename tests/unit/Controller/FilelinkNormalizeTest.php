<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Controller;

use OCA\MetaVox\Controller\FieldController;
use OCA\MetaVox\Service\FileReferenceService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for FieldController::normalizeFilelinkValue (github#95).
 *
 * The team-folder gate added in 755e8919 dropped every token it could not
 * resolve to a fileid. Because the frontend always posts a bare path
 * (`{fileId: null, path}`), a path the picker returned in an unexpected shape
 * resolved to null, was dropped, and the joined result — an empty string —
 * overwrote the stored value while the endpoint still answered success. The
 * user saw "metadata updated", then an empty field after reload.
 *
 * The rule these tests pin down: an unresolvable path is NOT evidence that the
 * target lies outside the team folder — it means we do not know where it lives.
 * Only a token we resolved AND placed outside the folder may be dropped.
 */
class FilelinkNormalizeTest extends TestCase {

    private FileReferenceService&MockObject $fileReferenceService;
    private FieldController $controller;

    protected function setUp(): void {
        parent::setUp();

        $this->fileReferenceService = $this->createMock(FileReferenceService::class);

        // The controller is built without running its constructor, and only the
        // one collaborator the method under test uses is injected. Constructing
        // it normally would require mocking IRootFolder, which extends the
        // internal OC\Hooks\Emitter that the nextcloud/ocp stub package does not
        // ship — and normalizeFilelinkValue never touches the root folder.
        $this->controller = (new \ReflectionClass(FieldController::class))->newInstanceWithoutConstructor();

        // setAccessible() is omitted: it is a no-op since PHP 8.1 and
        // deprecated in 8.5, and phpunit.xml sets failOnWarning.
        (new \ReflectionProperty(FieldController::class, 'fileReferenceService'))
            ->setValue($this->controller, $this->fileReferenceService);
    }

    /**
     * Invoke the private method under test with the real object graph.
     */
    private function normalize(string $value, ?int $groupfolderId): string {
        $method = new \ReflectionMethod(FieldController::class, 'normalizeFilelinkValue');

        return $method->invoke($this->controller, $value, 'filelink', 'alice', $groupfolderId);
    }

    /**
     * The exact github#95 scenario: a bare path from the picker that cannot be
     * resolved, saved inside a team folder. It must survive as a bare path
     * rather than blanking the field.
     */
    public function testUnresolvablePathIsKeptInsteadOfBlankingTheValue(): void {
        $this->fileReferenceService->method('resolvePathToFileId')->willReturn(null);
        // Never consulted: with no fileid there is nothing to locate.
        $this->fileReferenceService->expects($this->never())->method('isFileInGroupfolder');

        $result = $this->normalize('/files/alice/Team/spec.pdf', 22);

        $this->assertNotSame('', $result, 'an unresolvable path must not blank the field');
        $this->assertSame('/files/alice/Team/spec.pdf', $result);
    }

    /**
     * A path that does resolve is upgraded to the canonical "<fileid>:<path>"
     * form, so renames keep following the file.
     */
    public function testResolvablePathInsideFolderIsUpgradedToFileId(): void {
        $this->fileReferenceService->method('resolvePathToFileId')->willReturn(13394);
        $this->fileReferenceService->method('isFileInGroupfolder')->willReturn(true);

        $this->assertSame('13394:/Team/spec.pdf', $this->normalize('/Team/spec.pdf', 22));
    }

    /**
     * The gate still does its job: a target we located outside this team folder
     * is dropped, because linking it would show other members a dead reference.
     */
    public function testResolvedTargetOutsideTheFolderIsStillDropped(): void {
        $this->fileReferenceService->method('resolvePathToFileId')->willReturn(999);
        $this->fileReferenceService->method('isFileInGroupfolder')->willReturn(false);

        $this->assertSame('', $this->normalize('/Elsewhere/other.pdf', 22));
    }

    /**
     * One unresolvable token must not take its resolvable siblings down with
     * it — the whole-value loss is what made github#95 severe.
     */
    public function testOneUnresolvableTokenDoesNotDiscardTheOthers(): void {
        $this->fileReferenceService->method('resolvePathToFileId')
            ->willReturnCallback(static fn (string $path): ?int => $path === '/Team/good.pdf' ? 500 : null);
        $this->fileReferenceService->method('isFileInGroupfolder')->willReturn(true);

        $result = $this->normalize('/Team/good.pdf;#/files/alice/Team/bad.pdf', 22);

        $this->assertStringContainsString('500:/Team/good.pdf', $result);
        $this->assertStringContainsString('/files/alice/Team/bad.pdf', $result);
    }

    /**
     * Outside any team folder (groupfolderId null) there is no boundary to
     * enforce, so nothing may be dropped.
     */
    public function testWithoutGroupfolderNothingIsDropped(): void {
        $this->fileReferenceService->method('resolvePathToFileId')->willReturn(null);

        $this->assertSame('/anywhere/file.pdf', $this->normalize('/anywhere/file.pdf', null));
    }
}
