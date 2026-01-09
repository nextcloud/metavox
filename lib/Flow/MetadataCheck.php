<?php

declare(strict_types=1);

namespace OCA\MetaVox\Flow;

use OCA\MetaVox\Service\FieldService;
use OCA\WorkflowEngine\Entity\File;
use OCP\Files\IHomeStorage;
use OCP\Files\Storage\IStorage;
use OCP\IL10N;
use OCP\IURLGenerator;
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
    ) {
    }

    /**
     * @return string Localized display name for this check
     */
    public function getDisplayName(): string {
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
        // Available for all scopes
        return true;
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
        $config = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \UnexpectedValueException($this->l->t('Invalid configuration format'));
        }

        // Use operator from config if present, otherwise use the $operator parameter
        $actualOperator = $config['operator'] ?? $operator;

        if (!in_array($actualOperator, self::VALID_OPERATORS)) {
            throw new \UnexpectedValueException(
                $this->l->t('Invalid operator')
            );
        }

        if (empty($config['field_name'])) {
            throw new \UnexpectedValueException($this->l->t('Metadata field name is required'));
        }

        // Value is not required for empty checks and checkbox operators
        $noValueOperators = ['empty', '!empty', 'isTrue', 'isFalse'];
        if (!in_array($actualOperator, $noValueOperators) && !isset($config['value'])) {
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
        $groupfolderId = $config['groupfolder_id'] ?? null;
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

            // If no groupfolder specified, try to detect it
            if ($groupfolderId === null) {
                $groupfolderId = $this->detectGroupfolderId();
            }

            if ($groupfolderId === null) {
                return false;
            }

            // Get the metadata for this file
            $metadata = $this->fieldService->getGroupfolderFileMetadata((int)$groupfolderId, $fileId);

            // Find the field value
            $actualValue = null;
            foreach ($metadata as $field) {
                if ($field['field_name'] === $fieldName) {
                    $actualValue = $field['value'];
                    break;
                }
            }

            // Perform the comparison
            return $this->compare($actualOperator, $actualValue, $expectedValue);

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
     * Try to detect the groupfolder ID from the storage
     * Optimized order: database lookup first (fastest if file has metadata),
     * then storage ID checks (no I/O needed)
     */
    private function detectGroupfolderId(): ?int {
        try {
            // Method 1 (fastest): Check database if file already has metadata
            $fileId = $this->getFileId();
            if ($fileId !== null) {
                $groupfolderId = $this->findGroupfolderByFileId($fileId);
                if ($groupfolderId !== null) {
                    return $groupfolderId;
                }
            }

            // Method 2: Check storage ID for groupfolder pattern
            $storageId = $this->storage->getId();
            if (preg_match('/groupfolder[:\/_]+(\d+)/i', $storageId, $matches)) {
                return (int)$matches[1];
            }

            // Method 3: Check unwrapped storage ID
            $unwrappedStorage = $this->storage;
            while ($unwrappedStorage instanceof \OC\Files\Storage\Wrapper\Wrapper) {
                $unwrappedStorage = $unwrappedStorage->getWrapperStorage();
            }
            if ($unwrappedStorage !== $this->storage) {
                $unwrappedId = $unwrappedStorage->getId();
                if (preg_match('/groupfolder[:\/_]+(\d+)/i', $unwrappedId, $matches)) {
                    return (int)$matches[1];
                }
            }

            // Method 4: Try mount point (fallback)
            if (method_exists($this->storage, 'getMountPoint')) {
                $mountPoint = $this->storage->getMountPoint();
                if (preg_match('/^\/(\d+)\//', $mountPoint, $matches)) {
                    return (int)$matches[1];
                }
            }

        } catch (\Exception $e) {
            $this->logger->debug('MetaVox: Could not detect groupfolder ID: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Find groupfolder ID by looking up the file in the metadata database
     */
    private function findGroupfolderByFileId(int $fileId): ?int {
        try {
            $groupfolderId = $this->fieldService->getGroupfolderIdByFileId($fileId);
            if ($groupfolderId !== null) {
                return $groupfolderId;
            }
        } catch (\Exception $e) {
            $this->logger->debug('MetaVox: findGroupfolderByFileId error: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Compare the actual value with the expected value using the operator
     */
    private function compare(string $operator, ?string $actualValue, string $expectedValue): bool {
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
