<?php

declare(strict_types=1);

namespace OCA\MetaVox\Command;

use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\SearchIndexService;
use OCA\MetaVox\Tests\Performance\DataGenerator;
use OCA\MetaVox\Tests\Performance\FieldServiceTest;
use OCA\MetaVox\Tests\Performance\SearchServiceTest;
use OCA\MetaVox\Tests\Performance\ConcurrencyTest;
use OCA\MetaVox\Tests\Performance\FileCacheIntegrationTest;
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
 * OCC Command voor MetaVox performance tests
 *
 * Usage:
 *   occ metavox:performance-test --generate-data --records=10000000
 *   occ metavox:performance-test --suite=all
 *   occ metavox:performance-test --suite=field
 *   occ metavox:performance-test --cleanup
 */
class PerformanceTestCommand extends Command {

    public function __construct(
        private FieldService $fieldService,
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
        $this->setName('metavox:performance-test')
            ->setDescription('Run MetaVox performance tests with millions of metadata records')
            ->addOption(
                'suite',
                's',
                InputOption::VALUE_REQUIRED,
                'Test suite to run: all, field, search, concurrent, filecache',
                'all'
            )
            ->addOption(
                'generate-data',
                'g',
                InputOption::VALUE_NONE,
                'Generate test data before running tests'
            )
            ->addOption(
                'records',
                'r',
                InputOption::VALUE_REQUIRED,
                'Number of metadata records to generate',
                '10000000'
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
        $output->writeln('║         MetaVox Performance Test Suite                        ║');
        $output->writeln('║                                                                ║');
        $output->writeln('║  Testing scalability with millions of metadata records        ║');
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
     * Generate test data
     */
    private function runDataGeneration(OutputInterface $output, int $records): bool {
        $output->writeln("<info>Generating test data...</info>");
        $output->writeln("Target records: " . number_format($records));
        $output->writeln('');
        $output->writeln("This may take a while. Progress will be shown below.");
        $output->writeln('');

        try {
            $generator = new DataGenerator(
                $this->fieldService,
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
            $output->writeln("<info>✓ Data generation complete!</info>");
            return true;

        } catch (\Exception $e) {
            $output->writeln('');
            $output->writeln("<error>✗ Data generation failed: {$e->getMessage()}</error>");
            $this->logger->error('Performance test data generation failed', [
                'exception' => $e,
            ]);
            return false;
        }
    }

    /**
     * Run cleanup
     */
    private function runCleanup(OutputInterface $output): int {
        $output->writeln("<info>Cleaning up test data...</info>");
        $output->writeln('');

        try {
            $generator = new DataGenerator(
                $this->fieldService,
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
        $output->writeln("<info>Running test suite: $suite</info>");
        $output->writeln('');

        $startTime = microtime(true);
        $success = true;

        try {
            switch ($suite) {
                case 'all':
                    $this->runFieldTests($output);
                    $this->runSearchTests($output);
                    $this->runConcurrencyTests($output);
                    $this->runFileCacheTests($output);
                    break;

                case 'field':
                    $this->runFieldTests($output);
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
                    $output->writeln("Valid suites: all, field, search, concurrent, filecache");
                    return 1;
            }

        } catch (\Exception $e) {
            $output->writeln('');
            $output->writeln("<error>✗ Test suite failed: {$e->getMessage()}</error>");
            $this->logger->error('Performance test failed', [
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
            $output->writeln("<info>✓ All tests completed successfully!</info>");
            $output->writeln('');
            $output->writeln("Results saved in: tests/Performance/results/");
            return 0;
        } else {
            $output->writeln("<error>✗ Some tests failed</error>");
            return 1;
        }
    }

    private function runFieldTests(OutputInterface $output): void {
        $output->writeln("<comment>→ Running Field Service tests...</comment>");

        $test = new FieldServiceTest(
            $this->fieldService,
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
        $output->writeln("<comment>→ Running Search Index Service tests...</comment>");

        $test = new SearchServiceTest(
            $this->fieldService,
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
        $output->writeln("<comment>→ Running Concurrency tests...</comment>");

        $test = new ConcurrencyTest(
            $this->fieldService,
            $this->searchService,
            $this->db,
            $this->userSession,
            $this->rootFolder,
            $this->logger
        );

        $test->run();
        $output->writeln('');
    }

    private function runFileCacheTests(OutputInterface $output): void {
        $output->writeln("<comment>→ Running FilCache Integration tests...</comment>");

        $test = new FileCacheIntegrationTest(
            $this->fieldService,
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
