<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Service;

use OCA\MetaVox\Service\FileReferenceService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * How a file-link value is stored: ids only, never a path (a path is how one
 * user saw the file), and only files in this team folder the user may open. A
 * path we can't resolve is kept as text, because dropping it blanked people's
 * fields while the save still answered success (github#95).
 */
class FileLinkNormalizeTest extends TestCase {

    private const FOLDER = 22;

    /** @var list<int[]> the ids each access check was asked about */
    private array $checked = [];

    /**
     * @param array<string, int> $paths path => id as the saving user sees it
     * @param int[] $inFolder ids that live in the team folder
     * @param ?int[] $linkable ids the user may open there; null: all
     */
    private function service(array $paths = [], array $inFolder = [], ?string $user = 'alice', ?array $linkable = null): FileReferenceService {
        $service = $this->getMockBuilder(FileReferenceService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolvePathToFileId', 'filesInGroupfolder', 'linkableFiles'])
            ->getMock();
        $service->method('linkableFiles')->willReturnCallback(function (string $userId, array $ids) use ($linkable): array {
            $this->checked[] = $ids;
            return $linkable === null ? $ids : array_values(array_intersect($ids, $linkable));
        });
        $service->method('resolvePathToFileId')->willReturnCallback(static fn(string $path): ?int => $paths[$path] ?? null);
        $service->method('filesInGroupfolder')->willReturnCallback(
            static fn(array $ids, int $gf): array => $gf === self::FOLDER ? array_values(array_intersect($ids, $inFolder)) : []
        );

        $session = $this->createMock(IUserSession::class);
        if ($user !== null) {
            $iUser = $this->createMock(IUser::class);
            $iUser->method('getUID')->willReturn($user);
            $session->method('getUser')->willReturn($iUser);
        }
        // A private readonly property of the parent class: set it from its own scope.
        \Closure::bind(fn() => $this->userSession = $session, $service, FileReferenceService::class)();
        return $service;
    }

    public function testAPickedPathIsStoredAsItsIdWithoutThePath(): void {
        $service = $this->service(['/Team/spec.pdf' => 13394], [13394]);

        $this->assertSame('13394:', $service->normalize('/Team/spec.pdf', self::FOLDER));
    }

    public function testThePathTextSentWithAnIdIsNotStored(): void {
        $service = $this->service([], [777]);

        $this->assertSame('777:', $service->normalize('777:/any/text', self::FOLDER));
    }

    public function testALinkOutsideTheTeamFolderIsDropped(): void {
        $service = $this->service(['/Elsewhere/other.pdf' => 999], [5]);

        $this->assertSame('', $service->normalize('/Elsewhere/other.pdf', self::FOLDER));
        $this->assertSame('5:', $service->normalize('999:;#5:', self::FOLDER));
    }

    public function testAnUnresolvablePathIsKeptInsteadOfBlankingTheValue(): void {
        $service = $this->service();

        $this->assertSame('/files/alice/Team/spec.pdf', $service->normalize('/files/alice/Team/spec.pdf', self::FOLDER));
    }

    public function testOneUnresolvableLinkDoesNotDiscardTheOthers(): void {
        $service = $this->service(['/Team/good.pdf' => 500], [500]);

        $this->assertSame('500:;#/files/alice/Team/bad.pdf', $service->normalize('/Team/good.pdf;#/files/alice/Team/bad.pdf', self::FOLDER));
    }

    public function testTheSameFileLinkedTwiceIsStoredOnce(): void {
        $service = $this->service(['/Team/a.pdf' => 7], [7]);

        $this->assertSame('7:', $service->normalize('7:/Team/a.pdf;#/Team/a.pdf;#7:', self::FOLDER));
    }

    public function testAFileIsNotLinkedToItself(): void {
        // Picked by path or sent as an id: the file being saved drops out.
        $service = $this->service(['/Team/self.pdf' => 42], [42, 7]);

        $this->assertSame('7:', $service->normalize('/Team/self.pdf;#7:', self::FOLDER, 42));
        $this->assertSame('42:;#7:', $service->normalize('42:;#7:', self::FOLDER), 'without a file of its own nothing is dropped');
    }

    public function testWithoutAUserPathsStayButIdsAreStillChecked(): void {
        $service = $this->service(['/Team/a.pdf' => 7], [7], null);

        $this->assertSame('7:;#/Team/a.pdf', $service->normalize('7:/Team/a.pdf;#8:/x;#/Team/a.pdf', self::FOLDER));
    }

    public function testAnIdTheUserMayNotOpenIsLeftOutLikeOneOutsideTheFolder(): void {
        // 6 is in the team folder but hidden by ACL; 999 is elsewhere.
        $service = $this->service([], [5, 6], 'alice', [5]);

        $this->assertSame('5:', $service->normalize('5:;#6:;#999:', self::FOLDER));
    }

    public function testALinkAlreadyThereStaysAndIsNotCheckedAgain(): void {
        // Someone who may open 6 linked it; alice adds 5 and keeps it.
        $service = $this->service([], [5, 6], 'alice', [5]);

        $this->assertSame('6:;#5:', $service->normalize('6:;#5:', self::FOLDER, null, '6:'));
        $this->assertSame([[5]], $this->checked);
    }

    public function testAPickedPathIsTheUsersOwnAndNeedsNoCheck(): void {
        $service = $this->service(['/Team/a.pdf' => 7], [7], 'alice', []);

        $this->assertSame('7:', $service->normalize('/Team/a.pdf', self::FOLDER));
        $this->assertSame([], $this->checked);
    }

    public function testACopyAndASaveWithoutUserOnlyLookAtTheTeamFolder(): void {
        $this->assertSame('5:;#6:', $this->service([], [5, 6], 'alice', [])->normalize('5:;#6:', self::FOLDER, null, '', false));
        $this->assertSame('5:;#6:', $this->service([], [5, 6], null, [])->normalize('5:;#6:', self::FOLDER));
        $this->assertSame([], $this->checked);
    }

    public function testAnEmptyValueStaysEmpty(): void {
        $this->assertSame('', $this->service()->normalize('', self::FOLDER));
    }

    public function testWithoutPathsKeepsIdsAndTextOnly(): void {
        $this->assertSame('12:;#34:;#/legacy/c.pdf', FileReferenceService::withoutPaths('12:/a.pdf;#34:/b/c d.docx;#/legacy/c.pdf'));
        $this->assertSame('12:', FileReferenceService::withoutPaths('12:'));
        $this->assertSame('', FileReferenceService::withoutPaths(''));
    }
}
