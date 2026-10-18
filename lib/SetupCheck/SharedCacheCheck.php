<?php

declare(strict_types=1);

namespace OCA\MetaVox\SetupCheck;

use OCP\IConfig;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Presence and editing locks live in the distributed cache. APCu, also when
 * Nextcloud falls back to it, is per web server.
 */
class SharedCacheCheck implements ISetupCheck {

    public function __construct(
        private readonly IConfig $config,
        private readonly IL10N $l,
    ) {
    }

    public function getCategory(): string {
        return 'system';
    }

    public function getName(): string {
        // TRANSLATORS Name of a check in the admin overview "Setup checks"; MetaVox is the app name, do not translate it
        return $this->l->t('MetaVox shared cache');
    }

    public function run(): SetupResult {
        $cache = ltrim((string)$this->config->getSystemValue('memcache.distributed', ''), '\\');
        if ($cache === '' || $cache === 'OC\Memcache\APCu') {
            // TRANSLATORS Shown in the admin overview "Setup checks"; Redis is the name of a cache server, keep it as is
            return SetupResult::info($this->l->t('No shared cache such as Redis is set up. With more than one web server, presence and editing locks need one.'));
        }
        // TRANSLATORS Shown in the admin overview "Setup checks" when a shared cache server is set up
        return SetupResult::success($this->l->t('A shared cache is set up, so presence and editing locks work across all web servers.'));
    }
}
