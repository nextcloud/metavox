<?php

/**
 * MetaVox Performance Test Runner
 *
 * Usage:
 *   php tests/Performance/run_tests.php --suite=all
 *   php tests/Performance/run_tests.php --suite=field
 *   php tests/Performance/run_tests.php --suite=filter
 *   php tests/Performance/run_tests.php --suite=search
 *   php tests/Performance/run_tests.php --suite=concurrent
 *   php tests/Performance/run_tests.php --suite=filecache
 *   php tests/Performance/run_tests.php --generate-data --records=10000000
 *   php tests/Performance/run_tests.php --cleanup
 *
 * BELANGRIJK: Deze test moet worden uitgevoerd vanuit de Nextcloud omgeving!
 * Dit betekent dat je Nextcloud moet draaien en dat je via de Nextcloud CLI
 * deze test moet aanroepen, zodat de DI container beschikbaar is.
 *
 * Aanbevolen aanpak:
 * 1. Maak een OCC command in lib/Command/PerformanceTest.php
 * 2. Run via: occ metavox:performance-test --suite=all
 */

// Parse command line arguments
$options = getopt('', [
    'suite:',
    'generate-data',
    'records:',
    'cleanup',
    'help',
]);

if (isset($options['help'])) {
    echo <<<HELP
MetaVox Performance Test Runner

Usage:
  php tests/Performance/run_tests.php [options]

Options:
  --suite=SUITE         Run specific test suite (all, field, filter, search, concurrent, filecache)
  --generate-data       Generate test data before running tests
  --records=N           Number of metadata records to generate (default: 10000000)
  --cleanup             Clean up all test data
  --help                Show this help message

Examples:
  # Generate 10M test records
  php tests/Performance/run_tests.php --generate-data --records=10000000

  # Run all performance tests
  php tests/Performance/run_tests.php --suite=all

  # Run only field service tests
  php tests/Performance/run_tests.php --suite=field

  # Clean up test data
  php tests/Performance/run_tests.php --cleanup

IMPORTANT:
  This script must be run from within the Nextcloud environment!
  Recommended: Create an OCC command instead of running this directly.

HELP;
    exit(0);
}

echo <<<BANNER

╔════════════════════════════════════════════════════════════════╗
║                                                                ║
║         MetaVox Performance Test Suite                        ║
║                                                                ║
║  Testing scalability with millions of metadata records        ║
║                                                                ║
╚════════════════════════════════════════════════════════════════╝


BANNER;

echo "ERROR: This script must be run via the Nextcloud OCC command!\n\n";
echo "Steps to run performance tests:\n\n";
echo "1. Create OCC command:\n";
echo "   File: lib/Command/PerformanceTestCommand.php\n\n";
echo "2. Register command in appinfo/info.xml:\n";
echo "   <command>OCA\\MetaVox\\Command\\PerformanceTestCommand</command>\n\n";
echo "3. Run via OCC:\n";
echo "   cd /path/to/nextcloud\n";
echo "   sudo -u www-data php occ metavox:performance-test --suite=all\n\n";
echo "See tests/Performance/README.md for detailed instructions\n\n";

exit(1);

// De daadwerkelijke test uitvoering gebeurt in het OCC command
// Hieronder is referentie code voor hoe de tests aangeroepen moeten worden:

/*
namespace OCA\MetaVox\Command;

use OCA\MetaVox\Tests\Performance\DataGenerator;
use OCA\MetaVox\Tests\Performance\FieldServiceTest;
use OCA\MetaVox\Tests\Performance\FilterServiceTest;
use OCA\MetaVox\Tests\Performance\SearchServiceTest;
use OCA\MetaVox\Tests\Performance\ConcurrencyTest;
use OCA\MetaVox\Tests\Performance\FileCacheIntegrationTest;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PerformanceTestCommand extends Command {

    public function __construct(
        private FieldService $fieldService,
        private FilterService $filterService,
        private SearchIndexService $searchService,
        private IDBConnection $db,
        private IUserSession $userSession,
        private IRootFolder $rootFolder,
        private ILogger $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('metavox:performance-test')
            ->setDescription('Run MetaVox performance tests')
            ->addOption('suite', 's', InputOption::VALUE_REQUIRED, 'Test suite to run (all, field, filter, search, concurrent, filecache)', 'all')
            ->addOption('generate-data', 'g', InputOption::VALUE_NONE, 'Generate test data')
            ->addOption('records', 'r', InputOption::VALUE_REQUIRED, 'Number of records to generate', '10000000')
            ->addOption('cleanup', 'c', InputOption::VALUE_NONE, 'Clean up test data');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $suite = $input->getOption('suite');
        $generateData = $input->getOption('generate-data');
        $records = (int) $input->getOption('records');
        $cleanup = $input->getOption('cleanup');

        // Set user session (admin user voor tests)
        // $this->userSession->setUser(...);

        if ($generateData) {
            $output->writeln("Generating test data...");
            $generator = new DataGenerator(
                $this->fieldService,
                $this->filterService,
                $this->searchService,
                $this->db,
                $this->userSession,
                $this->rootFolder,
                $this->logger
            );
            $generator->run();
        }

        if ($cleanup) {
            $output->writeln("Cleaning up test data...");
            $generator = new DataGenerator(...);
            $generator->cleanup();
            return 0;
        }

        // Run test suites
        switch ($suite) {
            case 'all':
                $this->runFieldTests($output);
                $this->runFilterTests($output);
                $this->runSearchTests($output);
                $this->runConcurrencyTests($output);
                $this->runFileCacheTests($output);
                break;

            case 'field':
                $this->runFieldTests($output);
                break;

            case 'filter':
                $this->runFilterTests($output);
                break;

            case 'search':
                $this->runSearchTests($output);
                break;

            case 'concurrent':
                $this->runConcurrencyTests($output);
                break;

            case 'filecache':
                $this->runFileCacheTests($output);
                break;

            default:
                $output->writeln("<error>Unknown test suite: $suite</error>");
                return 1;
        }

        return 0;
    }

    private function runFieldTests(OutputInterface $output): void {
        $output->writeln("Running Field Service tests...");
        $test = new FieldServiceTest(
            $this->fieldService,
            $this->filterService,
            $this->searchService,
            $this->db,
            $this->userSession,
            $this->rootFolder,
            $this->logger
        );
        $test->run();
    }

    // ... similar methods for other test suites
}
*/
