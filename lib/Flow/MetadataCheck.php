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
     * Validate the check configuration
     *
     * @param string $operator The comparison operator
     * @param string $value JSON config with field_name and expected value
     * @throws \UnexpectedValueException if configuration is invalid
     */
    public function validateCheck($operator, $value): void {
        if (!in_array($operator, ['is', '!is', 'matches', '!matches', 'contains', '!contains'])) {
            throw new \UnexpectedValueException(
                $this->l->t('Invalid operator. Use: is, !is, matches, !matches, contains, !contains')
            );
        }

        $config = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \UnexpectedValueException($this->l->t('Invalid configuration format'));
        }

        if (empty($config['field_name'])) {
            throw new \UnexpectedValueException($this->l->t('Metadata field name is required'));
        }

        if (!isset($config['value'])) {
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
            return $this->compare($operator, $actualValue, $expectedValue);

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
        if ($actualValue === null || $actualValue === '') {
            $actualValue = '';
        }

        switch ($operator) {
            case 'is':
                return strtolower($actualValue) === strtolower($expectedValue);

            case '!is':
                return strtolower($actualValue) !== strtolower($expectedValue);

            case 'matches':
                return preg_match($expectedValue, $actualValue) === 1;

            case '!matches':
                return preg_match($expectedValue, $actualValue) !== 1;

            case 'contains':
                return stripos($actualValue, $expectedValue) !== false;

            case '!contains':
                return stripos($actualValue, $expectedValue) === false;

            default:
                return false;
        }
    }
}
