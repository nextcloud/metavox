<?php

declare(strict_types=1);

namespace OCA\MetaVox\Validation;

use OCA\MetaVox\L10n\Translatable;
use OCP\IUserManager;

/**
 * What a field takes, per field type, and the one form it is stored in. Every
 * route that writes a value asks here first, so a value is accepted or refused
 * the same way whether it comes from the grid, the sidebar, a bulk edit or the
 * API. '' is no value.
 */
class FieldValue {

    /** @var array<string, bool> accounts already looked up in this request */
    private array $accounts = [];

    public function __construct(
        private readonly IUserManager $users,
        private readonly LinkSettings $links,
    ) {
    }

    /**
     * The value as it is stored for this field.
     *
     * @param array $field a field as FieldService reads it (field_type, field_options)
     * @throws InvalidValue
     */
    public function stored(array $field, mixed $value): string {
        if ($value === null) {
            return '';
        }
        $options = $field['field_options'] ?? null;
        $stored = match ((string)($field['field_type'] ?? 'text')) {
            'number' => self::number($value),
            'date' => self::date($value, FieldOptions::includeTime($options)),
            'select', 'dropdown' => self::choice($value, $options),
            'multiselect', 'multi_select' => self::choices($value, $options),
            'checkbox', 'boolean' => self::tick($value),
            'url' => $this->url($value),
            'user' => $this->account($value),
            'filelink' => self::fileLinks($value),
            default => self::text($value),
        };
        // PostgreSQL cuts text off at a NUL character without a word; MySQL keeps it.
        if (str_contains($stored, "\0")) {
            // TRANSLATORS Error under a metadata field whose text contains an invisible character (NUL) that cannot be stored
            throw new InvalidValue(Translatable::t('This text contains a character that cannot be saved'));
        }
        return $stored;
    }

    /**
     * Whether the field must keep a value. Never a checkbox: it always has an
     * answer, ticked or not, so a required flag 2.x let an admin set on one
     * means nothing.
     */
    public static function isRequired(array $field): bool {
        return !empty($field['is_required'])
            && !in_array($field['field_type'] ?? '', ['checkbox', 'boolean'], true);
    }

    /**
     * A required field keeps a value.
     *
     * @throws InvalidValue
     */
    public static function requireFilled(array $field, string $stored): void {
        if (!self::isRequired($field)) {
            return;
        }
        if (trim($stored) === '') {
            // TRANSLATORS Error under a required metadata field that was left empty
            throw new InvalidValue(Translatable::t('This field is required'));
        }
    }

    /** A real day written as exactly YYYY-MM-DD. Not checkdate(): that refuses year 0. */
    public static function isDay(mixed $value): bool {
        if (!is_string($value) || !preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $value, $m)) {
            return false;
        }
        [$year, $month, $day] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        if ($month < 1 || $month > 12 || $day < 1) {
            return false;
        }
        $leap = $year % 4 === 0 && ($year % 100 !== 0 || $year % 400 === 0);
        return $day <= [31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31][$month - 1];
    }

    private static function text(mixed $value): string {
        if (is_int($value) || (is_float($value) && is_finite($value))) {
            return self::numberText($value);
        }
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            // TRANSLATORS Error under a text field that got a list, a yes/no or unreadable characters instead of text
            throw new InvalidValue(Translatable::t('Enter text'));
        }
        return $value;
    }

    private static function number(mixed $value): string {
        if (is_int($value) || (is_float($value) && is_finite($value))) {
            return self::numberText($value);
        }
        if (is_string($value)) {
            // The whitespace is_numeric allows, so the stored text is the number alone.
            $value = trim($value, " \t\n\r\v\f");
            if ($value === '' || (is_numeric($value) && is_finite((float)$value))) {
                return $value;
            }
        }
        // TRANSLATORS Error under a number field; the decimal separator is a point, as in 3.5
        throw new InvalidValue(Translatable::t('Enter a number, such as 12 or 3.5'));
    }

    private static function numberText(int|float $value): string {
        return is_int($value) ? (string)$value : json_encode($value);
    }

    private static function date(mixed $value, bool $withTime): string {
        $value = is_string($value) ? trim($value) : null;
        if ($value === '') {
            return '';
        }
        if (!$withTime) {
            if (self::isDay($value)) {
                return $value;
            }
            // TRANSLATORS Error under a date field; keep YYYY-MM-DD as it is, it is the format the value must have
            throw new InvalidValue(Translatable::t('Enter a date as YYYY-MM-DD'));
        }
        // A browser's date-time input leaves out the seconds.
        if ($value !== null && preg_match('/^(.{10})T([0-9]{2}):([0-9]{2})(?::([0-9]{2}))?\z/', $value, $m)
            && self::isDay($m[1]) && (int)$m[2] < 24 && (int)$m[3] < 60 && (int)($m[4] ?? 0) < 60) {
            return $m[1] . 'T' . $m[2] . ':' . $m[3] . ':' . ($m[4] ?? '00');
        }
        // TRANSLATORS Error under a date and time field; keep YYYY-MM-DDTHH:MM:SS as it is, it is the format the value must have
        throw new InvalidValue(Translatable::t('Enter a date and time as YYYY-MM-DDTHH:MM:SS'));
    }

    private static function choice(mixed $value, mixed $options): string {
        $value = self::scalarText($value);
        if ($value === '') {
            return '';
        }
        $option = $value === null ? null : self::option($value, FieldOptions::values($options));
        if ($option === null) {
            // TRANSLATORS Error under a dropdown field that got a value which is not in its list of options
            throw new InvalidValue(Translatable::t('Choose one of the options of this field'));
        }
        return $option;
    }

    private static function choices(mixed $value, mixed $options): string {
        $parts = match (true) {
            is_string($value) => explode(';#', $value),
            is_array($value) && array_is_list($value) => $value,
            default => [null],
        };
        $values = FieldOptions::values($options);
        $chosen = [];
        foreach ($parts as $part) {
            $part = self::scalarText($part);
            if ($part === '') {
                continue;
            }
            $option = $part === null ? null : self::option($part, $values);
            if ($option === null) {
                // TRANSLATORS Error under a multi-select field that got a value which is not in its list of options
                throw new InvalidValue(Translatable::t('Choose only options of this field'));
            }
            $chosen[$option] = true;
        }
        return implode(';#', array_map('strval', array_keys($chosen)));
    }

    /** The option as written in the field, also when the case differs; null when there is none. */
    private static function option(string $value, array $values): ?string {
        if (in_array($value, $values, true)) {
            return $value;
        }
        $lower = mb_strtolower($value);
        $matches = array_values(array_filter($values, fn (string $option): bool => mb_strtolower($option) === $lower));
        return \count($matches) === 1 ? $matches[0] : null;
    }

    /** Text, a number as text, or null when it is neither (a list, a yes/no). */
    private static function scalarText(mixed $value): ?string {
        if (is_int($value) || (is_float($value) && is_finite($value))) {
            return self::numberText($value);
        }
        return is_string($value) ? trim($value) : null;
    }

    private static function tick(mixed $value): string {
        $value = is_string($value) ? strtolower(trim($value)) : $value;
        return match ($value) {
            true, 1, '1', 'true' => '1',
            false, 0, '0', 'false' => '0',
            '' => '',
            // TRANSLATORS Error under a checkbox field that got something else than ticked (1) or not ticked (0)
            default => throw new InvalidValue(Translatable::t('Use 1 for ticked or 0 for not ticked')),
        };
    }

    private function url(mixed $value): string {
        $value = is_string($value) ? trim($value) : null;
        if ($value === '') {
            return '';
        }
        if ($value === null || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            // TRANSLATORS Error under a web address field (URL) that got something that is no address
            throw new InvalidValue(Translatable::t('Enter a web address'));
        }
        if ($this->links->webOnly()) {
            if (!preg_match('#^(https?://\S+|mailto:\S+)\z#i', $value)) {
                // TRANSLATORS Error under a web address field when the administration allows only web and email addresses; keep http://, https:// and mailto: as they are
                throw new InvalidValue(Translatable::t('Enter an address that starts with http://, https:// or mailto:'));
            }
        } else {
            // A browser ignores spaces and control characters in a scheme: "java script:" still runs.
            $scheme = preg_match('#^([^:/?\#]+):#', preg_replace('/\s+/', '', $value), $m) ? strtolower($m[1]) : '';
            if (in_array($scheme, ['javascript', 'data', 'vbscript'], true)) {
                // TRANSLATORS Error under a web address field that got a link which would run code, such as javascript:
                throw new InvalidValue(Translatable::t('This kind of link is not allowed'));
            }
        }

        $domain = self::domain($value);
        if ($domain !== null && self::onList($domain, $this->links->blockedDomains())) {
            // TRANSLATORS Error under a web address field when the administration blocked the domain of the link, such as all .ru addresses
            throw new InvalidValue(Translatable::t('Links to this domain are not allowed'));
        }
        $allowed = $this->links->allowedDomains();
        if ($allowed !== [] && ($domain === null || !self::onList($domain, $allowed))) {
            // TRANSLATORS Error under a web address field when the administration allows links to some domains only
            throw new InvalidValue(Translatable::t('Only links to approved domains are allowed'));
        }
        return $value;
    }

    /** The domain a link points at, as a browser reads it; null for one without, such as tel:. */
    private static function domain(string $value): ?string {
        if (preg_match('/^mailto:([^?]*)/i', $value, $m)) {
            $at = strrpos($m[1], '@');
            $host = $at === false ? '' : substr($m[1], $at + 1);
        } else {
            // Without a scheme the field shows it as a web address; a browser reads \ as /.
            $url = preg_match('#^[a-z][a-z0-9+.\-]*:#i', $value) ? $value : 'https://' . $value;
            $host = (string)(parse_url(str_replace('\\', '/', $url), PHP_URL_HOST) ?? '');
        }
        $host = rtrim(strtolower(trim($host, '[]')), '.');
        return $host === '' ? null : LinkSettings::ascii($host);
    }

    /** Whether a domain is on a list, itself or as a subdomain of one. */
    private static function onList(string $domain, array $list): bool {
        foreach ($list as $entry) {
            if ($domain === $entry || str_ends_with($domain, '.' . $entry)) {
                return true;
            }
        }
        return false;
    }

    private function account(mixed $value): string {
        $value = is_string($value) ? trim($value) : null;
        if ($value === '') {
            return '';
        }
        if ($value === null || !($this->accounts[$value] ??= $this->users->userExists($value))) {
            // TRANSLATORS Error under an account field (a person) that got a name which is no account on this server
            throw new InvalidValue(Translatable::t('Choose an existing account'));
        }
        return $value;
    }

    /** File links are put in their stored form by FileReferenceService; here only text or a list of it. */
    private static function fileLinks(mixed $value): string {
        if (is_array($value) && array_is_list($value)) {
            $value = implode(';#', array_map(fn ($link) => self::scalarText($link) ?? throw self::notALink(), $value));
        }
        if (is_int($value)) {
            return (string)$value;
        }
        return is_string($value) ? trim($value) : throw self::notALink();
    }

    private static function notALink(): InvalidValue {
        // TRANSLATORS Error under a file link field that got something that is no link to a file
        return new InvalidValue(Translatable::t('Enter a link to a file'));
    }
}
