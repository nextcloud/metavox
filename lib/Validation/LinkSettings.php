<?php

declare(strict_types=1);

namespace OCA\MetaVox\Validation;

use OCA\MetaVox\AppInfo\Application;
use OCA\MetaVox\L10n\Translatable;
use OCP\IAppConfig;

/**
 * What a URL field accepts. By default anything but a link that runs code
 * (javascript:, data:, vbscript:); the admin can narrow it to web and email
 * addresses only, block domains, or allow only some.
 */
class LinkSettings {

    public const WEB_ONLY = 'url_web_only';
    public const BLOCKED = 'url_blocked_domains';
    public const ALLOWED = 'url_allowed_domains';

    private const MAX_DOMAINS = 500;

    public function __construct(
        private readonly IAppConfig $appConfig,
    ) {
    }

    public function webOnly(): bool {
        try {
            return $this->appConfig->getValueBool(Application::APP_ID, self::WEB_ONLY, false);
        } catch (\Throwable) {
            return false;
        }
    }

    public function setWebOnly(bool $on): void {
        $this->appConfig->setValueBool(Application::APP_ID, self::WEB_ONLY, $on);
    }

    /** @return list<string> domains no link may point at, with their subdomains */
    public function blockedDomains(): array {
        return $this->domains(self::BLOCKED);
    }

    /** @return list<string> the only domains a link may point at; none is any */
    public function allowedDomains(): array {
        return $this->domains(self::ALLOWED);
    }

    /**
     * @param self::BLOCKED|self::ALLOWED $key
     * @throws InvalidValue
     */
    public function setDomains(string $key, mixed $domains): void {
        $this->appConfig->setValueArray(Application::APP_ID, $key, self::domainList($domains));
    }

    /** @return list<string> */
    private function domains(string $key): array {
        try {
            $list = $this->appConfig->getValueArray(Application::APP_ID, $key, []);
        } catch (\Throwable) {
            return [];
        }
        return array_values(array_filter($list, 'is_string'));
    }

    /**
     * Domains as an admin types them: one per line or a list, and ".ru",
     * "Example.com" or "https://example.com/page" all mean the domain itself.
     *
     * @return list<string>
     * @throws InvalidValue
     */
    public static function domainList(mixed $domains): array {
        $entries = match (true) {
            is_string($domains) => preg_split('/[\s,]+/', $domains),
            is_array($domains) && array_is_list($domains) => $domains,
            default => [null],
        };
        $list = [];
        foreach ($entries as $entry) {
            if (!is_string($entry)) {
                throw self::notADomain();
            }
            $domain = strtolower(trim($entry));
            $domain = preg_replace('#^[a-z][a-z0-9+.\-]*://#', '', $domain);
            $domain = trim(explode('/', $domain)[0], '.*');
            if ($domain === '') {
                continue;
            }
            $domain = self::ascii($domain);
            if (!preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*\z/', $domain)) {
                throw self::notADomain();
            }
            $list[$domain] = true;
        }
        if (\count($list) > self::MAX_DOMAINS) {
            // TRANSLATORS Error under a list of domains in the administration settings
            throw new InvalidValue(Translatable::t('Enter at most 500 domains'));
        }
        return array_map('strval', array_keys($list));
    }

    /** A host as the ASCII Nextcloud and browsers compare it in: münchen.de is xn--mnchen-3ya.de. */
    public static function ascii(string $host): string {
        if (!function_exists('idn_to_ascii') || preg_match('/^[\x00-\x7F]*\z/', $host)) {
            return $host;
        }
        return idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $host;
    }

    private static function notADomain(): InvalidValue {
        // TRANSLATORS Error under a list of domains in the administration settings; keep example.com and .ru as they are
        return new InvalidValue(Translatable::t('Enter domains such as example.com or .ru, one per line'));
    }
}
