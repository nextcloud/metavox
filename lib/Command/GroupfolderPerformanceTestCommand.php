<?php

declare(strict_types=1);

namespace OCA\MetaVox\Command;

use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\FilterService;
use OCA\MetaVox\Service\SearchIndexService;
use OCA\MetaVox\Tests\Performance\GroupfolderDataGenerator;
use OCA\MetaVox\Tests\Performance\GroupfolderFieldServiceTest;
use OCA\MetaVox\Tests\Performance\GroupfolderFilterServiceTest;
use OCA\MetaVox\Tests\Performance\GroupfolderSearchServiceTest;
use OCA\MetaVox\Tests\Performance\GroupfolderConcurrencyTest;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\IUserManager;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * OCC Command voor MetaVox GROUPFOLDER performance tests
 *
 * Deze command test de ECHTE productie scenario's:
 * - Groupfolder metadata (team folder niveau)
 * - File metadata binnen groupfolders
 *
 * Usage:
 *   occ metavox:gf-performance-test --generate-data --records=100000 --user=admin
 *   occ metavox:gf-performance-test --suite=all --user=admin
 *   occ metavox:gf-performance-test --suite=field --user=admin
 *   occ metavox:gf-performance-test --cleanup --user=admin
 */
class GroupfolderPerformanceTestCommand extends Command {

    public function __construct(
        private FieldService $fieldService,
        private FilterService $filterService,
        private SearchIndexService $searchService,
        private IDBConnection $db,
        private IUserSession $userSession,
        private IUserManager $userManager,
        private IRootFolder $rootFolder,
        private LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('metavox:gf-performance-test')
            ->setDescription('Run MetaVox GROUPFOLDER performance tests (production scenario)')
            ->addOption(
                'suite',
                's',
                InputOption::VALUE_REQUIRED,
                'Test suite to run: all, field, filter, search, concurrent',
                'all'
            )
            ->addOption(
                'generate-data',
                'g',
                InputOption::VALUE_NONE,
                'Generate test data via DB injection (fast!)'
            )
            ->addOption(
                'records',
                'r',
                InputOption::VALUE_REQUIRED,
                'Number of file metadata records to generate',
                '100000'
            )
            ->addOption(
                'cleanup',
                'c',
                InputOption::VALUE_NONE,
                'Clean up all test data'
            )
            ->addOption(
                'user',
                'u',
                InputOption::VALUE_REQUIRED,
                'User to run tests as (default: admin)',
                'admin'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $suite = $input->getOption('suite');
        $generateData = $input->getOption('generate-data');
        $records = (int) $input->getOption('records');
        $cleanup = $input->getOption('cleanup');
        $username = $input->getOption('user');

        $output->writeln('');
        $output->writeln('╔════════════════════════════════════════════════════════════════╗');
        $output->writeln('║                                                                ║');
        $output->writeln('║      MetaVox GROUPFOLDER Performance Test Suite               ║');
        $output->writeln('║                                                                ║');
        $output->writeln('║  Testing PRODUCTION scenario: Team Folder Metadata            ║');
        $output->writeln('║                                                                ║');
        $output->writeln('╚════════════════════════════════════════════════════════════════╝');
        $output->writeln('');

        // Setup user session
        $user = $this->userManager->get($username);
        if (!$user) {
            $output->writeln("<error>User '$username' not found</error>");
            $output->writeln("Please specify an existing user with --user=username");
            return 1;
        }

        $this->userSession->setUser($user);
        $output->writeln("Running tests as user: <info>$username</info>");
        $output->writeln('');

        // Cleanup mode
        if ($cleanup) {
            return $this->runCleanup($output);
        }

        // Data generation mode
        if ($generateData) {
            $success = $this->runDataGeneration($output, $records);
            if (!$success) {
                return 1;
            }
            $output->writeln('');
        }

        // Run test suite
        return $this->runTestSuite($output, $suite);
    }

    /**
     * Generate groupfolder test data via DB injection
     */
    private function runDataGeneration(OutputInterface $output, int $records): bool {
        $output->writeln("<info>Generating groupfolder test data via DB injection...</info>");
        $output->writeln("Target file metadata records: " . number_format($records));
        $output->writeln("Strategy: Direct DB injection (FAST!) + Service testing (REALISTIC!)");
        $output->writeln('');
        $output->writeln("This should be MUCH faster than creating real files!");
        $output->writeln('');

        try {
            $generator = new GroupfolderDataGenerator(
                $this->fieldService,
                $this->filterService,
                $this->searchService,
                $this->db,
                $this->userSession,
                $this->rootFolder,
                $this->logger
            );

            // Load en override config via reflection voor custom record count
            $configFile = __DIR__ . '/../Tests/Performance/config.php';
            if (file_exists($configFile)) {
                $config = require $configFile;
                $config['max_records'] = $records;

                $reflection = new \ReflectionClass($generator);
                $configProperty = $reflection->getProperty('config');
                $configProperty->setAccessible(true);
                $configProperty->setValue($generator, $config);
            }

            $generator->run();

            $output->writeln('');
            $output->writeln("<info>✓ Groupfolder data generation complete!</info>");
            return true;

        } catch (\Exception $e) {
            $output->writeln('');
            $output->writeln("<error>✗ Data generation failed: {$e->getMessage()}</error>");
            $output->writeln("<comment>Stack trace:</comment>");
            $output->writeln($e->getTraceAsString());
            $this->logger->error('Groupfolder performance test data generation failed', [
                'exception' => $e,
            ]);
            return false;
        }
    }

    /**
     * Run cleanup
     */
    private function runCleanup(OutputInterface $output): int {
        $output->writeln("<info>Cleaning up groupfolder test data...</info>");
        $output->writeln('');

        try {
            $generator = new GroupfolderDataGenerator(
                $this->fieldService,
                $this->filterService,
                $this->searchService,
                $this->db,
                $this->userSession,
                $this->rootFolder,
                $this->logger
            );

            $generator->cleanup();

            $output->writeln('');
            $output->writeln("<info>✓ Cleanup complete!</info>");
            return 0;

        } catch (\Exception $e) {
            $output->writeln('');
            $output->writeln("<error>✗ Cleanup failed: {$e->getMessage()}</error>");
            return 1;
        }
    }

    /**
     * Run test suite
     */
    private function runTestSuite(OutputInterface $output, string $suite): int {
        $output->writeln("<info>Running groupfolder test suite: $suite</info>");
        $output->writeln('');

        $startTime = microtime(true);
        $success = true;

        try {
            switch ($suite) {
                case 'all':
                    $this->runFieldTests($output);
                    $this->runFilterTests($output);
                    $this->runSearchTests($output);
                    $this->runConcurrencyTests($output);
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

                default:
                    $output->writeln("<error>Unknown test suite: $suite</error>");
                    $output->writeln("Valid suites: all, field, filter, search, concurrent");
                    return 1;
            }

        } catch (\Exception $e) {
            $output->writeln('');
            $output->writeln("<error>✗ Test suite failed: {$e->getMessage()}</error>");
            $output->writeln("<comment>Stack trace:</comment>");
            $output->writeln($e->getTraceAsString());
            $this->logger->error('Groupfolder performance test failed', [
                'suite' => $suite,
                'exception' => $e,
            ]);
            $success = false;
        }

        $duration = microtime(true) - $startTime;

        $output->writeln('');
        $output->writeln('═══════════════════════════════════════════════════════════════');
        $output->writeln('');
        $output->writeln("Total duration: " . gmdate('H:i:s', (int) $duration));

        if ($success) {
            $output->writeln("<info>✓ All groupfolder tests completed successfully!</info>");
            $output->writeln('');
            $output->writeln("Results saved in: tests/Performance/results/gf_*.json");
            return 0;
        } else {
            $output->writeln("<error>✗ Some tests failed</error>");
            return 1;
        }
    }

    private function runFieldTests(OutputInterface $output): void {
        $output->writeln("<comment>→ Running Groupfolder Field Service tests...</comment>");

        $test = new GroupfolderFieldServiceTest(
            $this->fieldService,
            $this->filterService,
            $this->searchService,
            $this->db,
            $this->userSession,
            $this->rootFolder,
            $this->logger
        );

        $test->run();
        $output->writeln('');
    }

    private function runFilterTests(OutputInterface $output): void {
        $output->writeln("<comment>→ Running Groupfolder Filter Service tests...</comment>");

        $test = new GroupfolderFilterServiceTest(
            $this->fieldService,
            $this->filterService,
            $this->searchService,
            $this->db,
            $this->userSession,
            $this->rootFolder,
            $this->logger
        );

        $test->run();
        $output->writeln('');
    }

    private function runSearchTests(OutputInterface $output): void {
        $output->writeln("<comment>→ Running Groupfolder Search Service tests...</comment>");

        $test = new GroupfolderSearchServiceTest(
            $this->fieldService,
            $this->filterService,
            $this->searchService,
            $this->db,
            $this->userSession,
            $this->rootFolder,
            $this->logger
        );

        $test->run();
        $output->writeln('');
    }

    private function runConcurrencyTests(OutputInterface $output): void {
        $output->writeln("<comment>→ Running Groupfolder Concurrency tests...</comment>");

        $test = new GroupfolderConcurrencyTest(
            $this->fieldService,
            $this->filterService,
            $this->searchService,
            $this->db,
            $this->userSession,
            $this->rootFolder,
            $this->logger
        );

        $test->run();
        $output->writeln('');
    }
}
