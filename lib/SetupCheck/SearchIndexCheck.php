<?php

declare(strict_types=1);

namespace OCA\MetaVox\SetupCheck;

use OCA\MetaVox\Service\SearchWordsService;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/** Free-text search knows a file once the fill job has done it. */
class SearchIndexCheck implements ISetupCheck {

    public function __construct(
        private readonly SearchWordsService $searchWords,
        private readonly IL10N $l,
    ) {
    }

    public function getCategory(): string {
        return 'system';
    }

    public function getName(): string {
        // TRANSLATORS Name of a check in the admin overview "Setup checks"; MetaVox is the app name, do not translate it
        return $this->l->t('MetaVox search index');
    }

    public function run(): SetupResult {
        $progress = $this->searchWords->fillProgress();
        if ($progress === null) {
            // TRANSLATORS Shown in the admin overview "Setup checks" when the search index is complete
            return SetupResult::success($this->l->t('Free-text search finds the metadata of all files.'));
        }
        // TRANSLATORS Shown in the admin overview "Setup checks"; %1$s is the number of files done, %2$s the total number of files, e.g. "1200 of 5000 files"
        return SetupResult::info($this->l->t('The search index is being built: %1$s of %2$s files. Until then, free-text search finds only the files already done.',
            [$progress['done'], $progress['total']]));
    }
}
