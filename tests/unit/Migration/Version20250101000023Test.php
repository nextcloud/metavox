<?php

declare(strict_types=1);

namespace OCA\MetaVox\Tests\Migration;

use OCA\MetaVox\Migration\Version20250101000023;
use OCA\MetaVox\Repair\PostgresValueIndex;
use OCA\MetaVox\Service\SearchWordsService;
use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/** An update sets PostgreSQL's filter index to the start of a value, then refills search. */
class Version20250101000023Test extends TestCase {

    public function testAnUpdateSetsTheValueIndexAndRefillsSearch(): void {
        $valueIndex = $this->createMock(PostgresValueIndex::class);
        $valueIndex->expects($this->once())->method('ensure');
        $words = $this->createMock(SearchWordsService::class);
        $words->expects($this->once())->method('rebuildAll');

        (new Version20250101000023($this->createMock(IJobList::class), $words, $valueIndex))
            ->postSchemaChange($this->createMock(IOutput::class), fn () => null, []);
    }
}
