<?php

declare(strict_types=1);

namespace OCA\MetaVox\Flow;

use OCA\MetaVox\Service\FieldService;
use OCA\MetaVox\Service\GroupfolderRootService;
use OCA\MetaVox\Service\TeamFolderAccessService;
use OCA\WorkflowEngine\Entity\File;
use OCP\Files\IHomeStorage;
use OCP\Files\Storage\IStorage;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\WorkflowEngine\IManager;
use OCP\WorkflowEngine\ICheck;
use OCP\WorkflowEngine\IEntity;
use OCP\WorkflowEngine\IFileCheck;
use Psr\Log\LoggerInterface;

/**
 * Flow Check: MetaVox Metadata Check
 *
 * Allows Flow rules to check file metadata values.
 * Can be used with files_accesscontrol to restrict access based on metadata.
 *
 * Example use cases:
 * - Block access to files where 'classification' = 'confidential'
 * - Only allow download if 'status' = 'approved'
 */
class MetadataCheck implements ICheck, IFileCheck {

    protected ?IStorage $storage = null;
    protected ?string $path = null;
    protected bool $isDir = false;

    public function __construct(
        private IL10N $l,
        private IURLGenerator $urlGenerator,
        private LoggerInterface $logger,
        private FieldService $fieldService,
        private IUserSession $userSession,
        private TeamFolderAccessService $teamFolders,
        private GroupfolderRootService $groupfolderRoots,
    ) {
    }

    /**
     * @return string Localized display name for this check
     */
    public function getDisplayName(): string {
        // TRANSLATORS Name of the check in the Flow (workflow) rule editor; MetaVox is the app name, do not translate it
        return $this->l->t('MetaVox metadata');
    }

    /**
     * @return array List of entity classes this check supports
     */
    public function supportedEntities(): array {
        return [File::class];
    }

    /**
     * @param int $scope The scope (admin or user)
     * @return bool Whether this check is available for the given scope
     */
    public function isAvailableForScope(int $scope): bool {
        // Metadata rules are the admin's: a user rule could test values of files
        // the user only reaches through a share.
        return $scope === IManager::SCOPE_ADMIN;
    }

    /**
     * Set file information for the check
     */
    public function setFileInfo(IStorage $storage, string $path, bool $isDir = false): void {
        $this->storage = $storage;
        $this->path = $path;
        $this->isDir = $isDir;
    }

    /**
     * Set the entity subject (required by IEntityCheck)
     */
    public function setEntitySubject(IEntity $entity, mixed $subject): void {
        // The file info is set via setFileInfo() for IFileCheck
        // This method is required by IEntityCheck interface
    }

    /**
     * All supported operators
     */
    private const VALID_OPERATORS = [
        // Text/general operators
        'is', '!is', 'contains', '!contains', 'matches', '!matches',
        // Empty checks (all types)
        'empty', '!empty',
        // Date operators
        'before', 'after',
        // Number operators
        'greater', 'less', 'greaterOrEqual', 'lessOrEqual',
        // Select operators
        'oneOf', 'containsAll',
        // Checkbox operators
        'isTrue', 'isFalse',
    ];

    /**
     * Validate the check configuration
     *
     * @param string $operator The comparison operator
     * @param string $value JSON config with field_name and expected value
     * @throws \UnexpectedValueException if configuration is invalid
     */
    public function validateCheck($operator, $value): void {
        // Nextcloud checks the scope for the operation, not for each check: a
        // user rule posted to the API would get through. Saving is done by the
        // rule's author, so refuse anyone but an admin here.
        $user = $this->userSession->getUser();
        if ($user !== null && !$this->teamFolders->isAdmin($user->getUID())) {
            // TRANSLATORS Error message in the Flow (workflow) rule editor when someone other than an administrator saves the check
            throw new \UnexpectedValueException($this->l->t('The metadata check is only available to administrators'));
        }

        $config = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // TRANSLATORS Error message in the Flow (workflow) rule editor when the saved check cannot be read
            throw new \UnexpectedValueException($this->l->t('Invalid configuration format'));
        }

        // Use operator from config if present, otherwise use the $operator parameter
        $actualOperator = $config['operator'] ?? $operator;

        if (!in_array($actualOperator, self::VALID_OPERATORS)) {
            throw new \UnexpectedValueException(
                // TRANSLATORS Error message in the Flow (workflow) rule editor; the operator is how the field is compared, e.g. equals, contains
                $this->l->t('Invalid operator')
            );
        }

        if (empty($config['field_name'])) {
            // TRANSLATORS Error message in the Flow (workflow) rule editor when no metadata field is chosen
            throw new \UnexpectedValueException($this->l->t('Metadata field name is required'));
        }

        // Value is not required for empty checks and checkbox operators
        $noValueOperators = ['empty', '!empty', 'isTrue', 'isFalse'];
        if (!in_array($actualOperator, $noValueOperators) && !isset($config['value'])) {
            // TRANSLATORS Error message in the Flow (workflow) rule editor when the value to compare with is empty
            throw new \UnexpectedValueException($this->l->t('Comparison value is required'));
        }
    }

    /**
     * Execute the check
     *
     * @param string $operator The comparison operator
     * @param string $value JSON config with field_name, value, and optionally groupfolder_id
     * @return bool Whether the check passes
     */
    public function executeCheck($operator, $value): bool {
        if ($this->storage === null || $this->path === null) {
            return false;
        }

        // Skip directories
        if ($this->isDir) {
            return false;
        }

        // Skip home storage (only check groupfolder files)
        if ($this->storage->instanceOfStorage(IHomeStorage::class)) {
            return false;
        }

        $config = json_decode($value, true);
        if (!$config) {
            return false;
        }

        $fieldName = $config['field_name'] ?? '';
        $expectedValue = $config['value'] ?? '';
        $groupfolderId = empty($config['groupfolder_id']) ? null : (int)$config['groupfolder_id'];
        // Use operator from config if present, otherwise use the $operator parameter
        $actualOperator = $config['operator'] ?? $operator;

        if (empty($fieldName)) {
            return false;
        }

        try {
            // Get the file ID from the storage and path
            $fileId = $this->getFileId();
            if ($fileId === null) {
                return false;
            }

            // The team folder the file is in, from where it lives; not in one
            // (trash and versions included): the rule doesn't apply. A rule for
            // one team folder only applies to files in that one.
            $fileGroupfolderId = $this->groupfolderRoots->getGroupfolderIdForFile($fileId);
            if ($fileGroupfolderId === null || ($groupfolderId !== null && $groupfolderId !== $fileGroupfolderId)) {
                return false;
            }

            // Get the metadata for this file
            $metadata = $this->fieldService->getGroupfolderFileMetadata($fileGroupfolderId, $fileId);

            // Find the field value
            $actualValue = null;
            $fieldType = '';
            foreach ($metadata as $field) {
                if ($field['field_name'] === $fieldName) {
                    $actualValue = $field['value'];
                    $fieldType = (string)($field['field_type'] ?? '');
                    break;
                }
            }

            // Perform the comparison
            return $this->compare($actualOperator, $actualValue, $expectedValue, $fieldType);

        } catch (\Exception $e) {
            $this->logger->warning('MetaVox Flow check failed: ' . $e->getMessage(), [
                'exception' => $e,
                'path' => $this->path,
            ]);
            return false;
        }
    }

    /**
     * Get the file ID from storage and path
     */
    private function getFileId(): ?int {
        try {
            $cache = $this->storage->getCache();
            $entry = $cache->get($this->path);
            if ($entry) {
                return (int)$entry->getId();
            }
        } catch (\Exception $e) {
            $this->logger->debug('Could not get file ID: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * Compare the actual value with the expected value using the operator. A
     * choice field reads as the grid's highlight rules read it, tested on the
     * same cases (tests/fixtures/value-rules-matrix.json).
     */
    private function compare(string $operator, ?string $actualValue, string $expectedValue, string $fieldType = ''): bool {
        if (FieldService::holdsList($fieldType)) {
            return $this->compareItems($operator, self::items($actualValue ?? ''), $expectedValue);
        }
        if (\in_array($fieldType, ['select', 'dropdown'], true)) {
            return $this->compareChoice($operator, $actualValue ?? '', $expectedValue);
        }

        // Handle empty/null values
        $isEmpty = $actualValue === null || $actualValue === '';

        switch ($operator) {
            // Empty checks (all field types)
            case 'empty':
                return $isEmpty;
            case '!empty':
                return !$isEmpty;

            // Checkbox operators (no value needed)
            case 'isTrue':
                return $this->normalizeBoolean($actualValue ?? '') === '1';
            case 'isFalse':
                return $this->normalizeBoolean($actualValue ?? '') === '0' || $isEmpty;

            // Text/general operators
            case 'is':
                $normalizedActual = $this->normalizeBoolean($actualValue ?? '');
                $normalizedExpected = $this->normalizeBoolean($expectedValue);
                return strtolower($normalizedActual) === strtolower($normalizedExpected);
            case '!is':
                $normalizedActual = $this->normalizeBoolean($actualValue ?? '');
                $normalizedExpected = $this->normalizeBoolean($expectedValue);
                return strtolower($normalizedActual) !== strtolower($normalizedExpected);
            case 'contains':
                return stripos($actualValue ?? '', $expectedValue) !== false;
            case '!contains':
                return stripos($actualValue ?? '', $expectedValue) === false;
            case 'matches':
                return @preg_match($expectedValue, $actualValue ?? '') === 1;
            case '!matches':
                return @preg_match($expectedValue, $actualValue ?? '') !== 1;

            // Date operators
            case 'before':
                return $this->compareDates($actualValue, $expectedValue, '<');
            case 'after':
                return $this->compareDates($actualValue, $expectedValue, '>');

            // Number operators
            case 'greater':
                return $this->compareNumbers($actualValue, $expectedValue, '>');
            case 'less':
                return $this->compareNumbers($actualValue, $expectedValue, '<');
            case 'greaterOrEqual':
                return $this->compareNumbers($actualValue, $expectedValue, '>=');
            case 'lessOrEqual':
                return $this->compareNumbers($actualValue, $expectedValue, '<=');

            // Select operators (multi-value)
            case 'oneOf':
                return $this->checkOneOf($actualValue, $expectedValue);
            case 'containsAll':
                return $this->checkContainsAll($actualValue, $expectedValue);

            default:
                return false;
        }
    }

    /**
     * A multi-select or file link, per item: "is one of" (and "contains", as
     * older rules have it) when it has any of the options, "contains all" when
     * it has every one.
     *
     * @param list<string> $items
     */
    private function compareItems(string $operator, array $items, string $expected): bool {
        if ($operator === 'empty' || $operator === '!empty') {
            return ($items === []) === ($operator === 'empty');
        }
        // A People field with several: "is" someone means they are in it.
        if ($operator === 'is' || $operator === '!is') {
            $in = \in_array(self::lower(trim($expected)), array_map([self::class, 'lower'], $items), true);
            return $operator === 'is' ? $in : !$in;
        }
        if ($items === []) {
            return false;
        }
        $have = array_map([self::class, 'lower'], $items);
        $wanted = array_map(fn (string $item): string => self::lower(trim($item)), self::wanted($expected));
        return match ($operator) {
            'oneOf', 'contains' => array_intersect($wanted, $have) !== [],
            'containsAll' => $wanted !== [] && array_diff($wanted, $have) === [],
            default => false,
        };
    }

    /** One option of a dropdown, compared without case and without the spaces around what the rule says. */
    private function compareChoice(string $operator, string $actual, string $expected): bool {
        $empty = $actual === '';
        $value = self::lower($actual);
        return match ($operator) {
            'empty' => $empty,
            '!empty' => !$empty,
            'is' => !$empty && $value === self::lower(trim($expected)),
            '!is' => $empty || $value !== self::lower(trim($expected)),
            'oneOf' => !$empty && \in_array($value, array_map(fn (string $v): string => self::lower(trim($v)), self::wanted($expected)), true),
            default => false,
        };
    }

    /** The items of a stored list value, as the grid splits them. */
    private static function items(string $value): array {
        return array_values(array_filter(array_map('trim', explode(';#', $value)), fn (string $item): bool => $item !== ''));
    }

    /** What a rule asks for: its list, or the one text it holds. @return list<string> */
    private static function wanted(string $expected): array {
        $list = json_decode($expected, true);
        return \is_array($list) ? array_values(array_map('strval', array_filter($list, 'is_scalar'))) : [$expected];
    }

    private static function lower(string $value): string {
        return mb_strtolower($value, 'UTF-8');
    }

    /**
     * Compare two date values
     */
    private function compareDates(?string $actual, string $expected, string $op): bool {
        if ($actual === null || $actual === '') {
            return false;
        }

        $actualTime = strtotime($actual);
        $expectedTime = strtotime($expected);

        if ($actualTime === false || $expectedTime === false) {
            return false;
        }

        return match($op) {
            '<' => $actualTime < $expectedTime,
            '>' => $actualTime > $expectedTime,
            '<=' => $actualTime <= $expectedTime,
            '>=' => $actualTime >= $expectedTime,
            default => false,
        };
    }

    /**
     * Compare two numeric values
     */
    private function compareNumbers(?string $actual, string $expected, string $op): bool {
        if ($actual === null || $actual === '' || !is_numeric($actual)) {
            return false;
        }
        if (!is_numeric($expected)) {
            return false;
        }

        $actualNum = (float)$actual;
        $expectedNum = (float)$expected;

        return match($op) {
            '<' => $actualNum < $expectedNum,
            '>' => $actualNum > $expectedNum,
            '<=' => $actualNum <= $expectedNum,
            '>=' => $actualNum >= $expectedNum,
            default => false,
        };
    }

    /**
     * Check if actual value is one of the expected values (JSON array)
     */
    private function checkOneOf(?string $actual, string $expected): bool {
        if ($actual === null || $actual === '') {
            return false;
        }

        $allowedValues = json_decode($expected, true);
        if (!is_array($allowedValues)) {
            // Single value fallback
            return strtolower($actual) === strtolower($expected);
        }

        $actualLower = strtolower($actual);
        foreach ($allowedValues as $allowed) {
            if (strtolower((string)$allowed) === $actualLower) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if actual value contains all expected values (for multiselect)
     */
    private function checkContainsAll(?string $actual, string $expected): bool {
        if ($actual === null || $actual === '') {
            return false;
        }

        $requiredValues = json_decode($expected, true);
        if (!is_array($requiredValues)) {
            return stripos($actual, $expected) !== false;
        }

        // Actual might be JSON array or comma-separated
        $actualValues = json_decode($actual, true);
        if (!is_array($actualValues)) {
            $actualValues = array_map('trim', explode(',', $actual));
        }

        $actualLower = array_map('strtolower', array_map('strval', $actualValues));

        foreach ($requiredValues as $required) {
            if (!in_array(strtolower((string)$required), $actualLower, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Normalize boolean string values for consistent comparison
     * Converts various boolean representations to '1' or '0'
     */
    private function normalizeBoolean(string $value): string {
        $lower = strtolower($value);

        // Check for truthy values
        if (in_array($lower, ['true', '1', 'yes', 'on'], true)) {
            return '1';
        }

        // Check for falsy values
        if (in_array($lower, ['false', '0', 'no', 'off'], true)) {
            return '0';
        }

        // Return original value for non-boolean strings
        return $value;
    }
}
