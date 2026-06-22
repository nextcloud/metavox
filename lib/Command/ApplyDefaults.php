<?php

declare(strict_types=1);

namespace OCA\MetaVox\Command;

use OCA\MetaVox\Service\DefaultsService;
use OCA\MetaVox\Service\SearchIndexService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ metavox:apply-defaults [--folder=ID] [--batch=N]
 *
 * Synchronously applies per-folder default values, with live progress. For
 * admins who do not want to wait for the 5-minute discovery cron — e.g. right
 * after a migration, or after configuring a new default on a large folder.
 *
 * Shares the exact data path as the background jobs (DefaultsService +
 * SearchIndexService::bulkUpdateFileIndex): idempotent, no push, bulk search
 * re-index. Runs in bounded batches so memory stays flat regardless of folder
 * size.
 */
class ApplyDefaults extends Command {

    /** Default files per batch; overridable with --batch. */
    private const DEFAULT_BATCH = 250;

    public function __construct(
        private readonly DefaultsService $defaultsService,
        private readonly SearchIndexService $searchIndexService,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('metavox:apply-defaults')
            ->setDescription('Apply per-folder default metadata values to files that are missing them')
            ->addOption('folder', null, InputOption::VALUE_REQUIRED, 'Limit to a single groupfolder id')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Files per batch', (string)self::DEFAULT_BATCH);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $batchSize = max(1, (int)$input->getOption('batch'));
        $folderOption = $input->getOption('folder');

        if ($folderOption !== null) {
            $groupfolderIds = [(int)$folderOption];
        } else {
            $groupfolderIds = $this->defaultsService->getGroupfoldersWithDefaults();
        }

        if (empty($groupfolderIds)) {
            $output->writeln('<info>No groupfolders with defaults to apply.</info>');
            return Command::SUCCESS;
        }

        $grandTotal = 0;
        foreach ($groupfolderIds as $groupfolderId) {
            $grandTotal += $this->processFolder($groupfolderId, $batchSize, $output);
        }

        $output->writeln(sprintf('<info>Done. Applied defaults to %d files.</info>', $grandTotal));
        return Command::SUCCESS;
    }

    private function processFolder(int $groupfolderId, int $batchSize, OutputInterface $output): int {
        $defaults = $this->defaultsService->getFolderDefaults($groupfolderId);
        if (empty($defaults)) {
            $output->writeln(sprintf('Folder %d: no file-field defaults configured, skipping.', $groupfolderId));
            return 0;
        }
        $fieldNames = array_keys($defaults);

        $output->writeln(sprintf('Folder %d: applying %d default(s)...', $groupfolderId, \count($defaults)));

        $cursor = 0;
        $applied = 0;
        while (true) {
            $fileIds = $this->defaultsService->findFilesMissingDefaults(
                $groupfolderId,
                $fieldNames,
                $batchSize,
                $cursor
            );
            if (empty($fileIds)) {
                break;
            }

            $this->defaultsService->bulkInsertDefaults($groupfolderId, $fileIds, $defaults);
            $this->searchIndexService->bulkUpdateFileIndex($fileIds);

            $applied += \count($fileIds);
            $cursor = max($fileIds);
            $output->writeln(sprintf('  ... %d files', $applied));
        }

        $output->writeln(sprintf('  Folder %d complete: %d files.', $groupfolderId, $applied));
        return $applied;
    }
}
