<?php

declare(strict_types=1);

namespace OCA\MetaVox\SetupCheck;

use OCP\IDBConnection;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * MetaVox is built and tested for MySQL, MariaDB and PostgreSQL. SQLite is for
 * trying it out, as Nextcloud says of itself: the search filter then reads
 * every value instead of the search index.
 */
class SqliteCheck implements ISetupCheck {

    public function __construct(
        private readonly IDBConnection $db,
        private readonly IL10N $l,
    ) {
    }

    public function getCategory(): string {
        return 'system';
    }

    public function getName(): string {
        // TRANSLATORS Name of a check in the administration overview of Nextcloud; MetaVox is the app name, do not translate it
        return $this->l->t('MetaVox database');
    }

    public function run(): SetupResult {
        if ($this->db->getDatabaseProvider() === IDBConnection::PLATFORM_SQLITE) {
            // TRANSLATORS Warning in the administration overview when Nextcloud runs on SQLite; MySQL, MariaDB, PostgreSQL and SQLite are database names
            return SetupResult::warning($this->l->t('MetaVox is built and tested for MySQL, MariaDB and PostgreSQL. On SQLite it works for trying it out, without the search index. For real use, switch to one of those.'));
        }
        // TRANSLATORS Shown in the administration overview when the database is one MetaVox supports
        return SetupResult::success($this->l->t('MetaVox is built and tested for this database.'));
    }
}
