<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCP\Files\IRootFolder;
use OCP\TaskProcessing\IManager as ITaskManager;
use OCP\TaskProcessing\Task;

class AiAutofillService {

    private ITaskManager $taskManager;
    private IRootFolder $rootFolder;
    private FieldService $fieldService;

    private const MAX_CONTENT_LENGTH = 32000;
    private const SUPPORTED_TEXT_MIMES = [
        'text/plain', 'text/csv', 'text/html', 'text/xml', 'text/markdown',
        'application/json', 'application/xml', 'application/xhtml+xml',
    ];

    public function __construct(
        ITaskManager $taskManager,
        IRootFolder $rootFolder,
        FieldService $fieldService
    ) {
        $this->taskManager = $taskManager;
        $this->rootFolder = $rootFolder;
        $this->fieldService = $fieldService;
    }

    private const TASK_TYPES = ['core:text2text', 'core:text2text:chat'];

    /**
     * Check if AI text generation is available
     */
    public function isAvailable(): bool {
        try {
            $types = $this->taskManager->getAvailableTaskTypes();
            foreach (self::TASK_TYPES as $type) {
                if (isset($types[$type])) {
                    return true;
                }
            }
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get the best available task type
     */
    private function getTaskType(): string {
        $types = $this->taskManager->getAvailableTaskTypes();
        foreach (self::TASK_TYPES as $type) {
            if (isset($types[$type])) {
                return $type;
            }
        }
        throw new \RuntimeException('No AI text generation provider available');
    }

    /**
     * Generate metadata suggestions for a file using AI
     */
    public function generateMetadata(int $fileId, int $groupfolderId, string $userId): array {
        // Get assigned fields for this groupfolder
        $fields = $this->fieldService->getAssignedFieldsWithDataForGroupfolder($groupfolderId);

        // Filter to only file-level fields (not groupfolder-level) and exclude user/filelink
        $aiFields = [];
        foreach ($fields as $field) {
            $appliesToGf = (int)($field['applies_to_groupfolder'] ?? 0);
            if ($appliesToGf === 1) {
                continue;
            }
            if (in_array($field['field_type'], ['user', 'filelink'], true)) {
                continue;
            }
            $aiFields[] = $field;
        }

        if (empty($aiFields)) {
            return [];
        }

        // Get file content
        $fileContent = $this->getFileContent($fileId, $userId);

        // Build prompt
        $prompt = $this->buildPrompt($aiFields, $fileContent['content'], $fileContent['name']);

        // Schedule TaskProcessing task (async — background workers pick it up)
        $taskType = $this->getTaskType();
        error_log('MetaVox AI: using task type ' . $taskType);

        $task = new Task(
            $taskType,
            ['input' => $prompt],
            'metavox',
            $userId,
        );

        $this->taskManager->scheduleTask($task);
        $taskId = $task->getId();
        error_log('MetaVox AI: scheduled task ' . $taskId);

        // Poll for completion (background workers process almost instantly)
        $maxWait = 120; // seconds
        $waited = 0;
        $pollInterval = 1; // second

        while ($waited < $maxWait) {
            sleep($pollInterval);
            $waited += $pollInterval;

            $task = $this->taskManager->getTask($taskId);
            $status = $task->getStatus();

            if ($status === Task::STATUS_SUCCESSFUL) {
                break;
            }
            if ($status === Task::STATUS_FAILED || $status === Task::STATUS_CANCELLED) {
                $errorMsg = $task->getErrorMessage() ?? 'Unknown error';
                error_log('MetaVox AI task ' . $taskId . ' failed: ' . $errorMsg);
                throw new \RuntimeException('AI task failed: ' . $errorMsg);
            }

            // Increase poll interval after first few seconds
            if ($waited > 5) {
                $pollInterval = 2;
            }
        }

        if ($task->getStatus() !== Task::STATUS_SUCCESSFUL) {
            error_log('MetaVox AI task ' . $taskId . ' timed out after ' . $waited . 's');
            throw new \RuntimeException('AI task timed out');
        }

        $output = $task->getOutput();
        $aiResponse = $output['output'] ?? '';

        if (empty($aiResponse)) {
            error_log('MetaVox AI: empty response from task ' . $taskId);
            return [];
        }

        // Parse JSON from AI response
        $suggestions = $this->parseAiResponse($aiResponse, $aiFields);
        error_log('MetaVox AI: parsed ' . \count($suggestions) . ' suggestions from task ' . $taskId);
        return $suggestions;
    }

    /**
     * Read file content from Nextcloud storage
     */
    private function getFileContent(int $fileId, string $userId): array {
        $userFolder = $this->rootFolder->getUserFolder($userId);
        $nodes = $userFolder->getById($fileId);

        if (empty($nodes)) {
            return ['name' => 'unknown', 'content' => '', 'mimetype' => ''];
        }

        $node = $nodes[0];
        $name = $node->getName();
        $mimetype = $node->getMimeType();
        $content = '';

        // Try to read text-based files
        if ($this->isTextMime($mimetype)) {
            try {
                $raw = $node->getContent();
                $content = mb_substr($raw, 0, self::MAX_CONTENT_LENGTH);
            } catch (\Exception $e) {
                // Fall back to filename-only
            }
        }

        // If no content, provide file info as context
        if (empty($content)) {
            $content = "File name: {$name}\nFile type: {$mimetype}\nFile size: {$node->getSize()} bytes\nPath: {$node->getPath()}";
        }

        return [
            'name' => $name,
            'content' => $content,
            'mimetype' => $mimetype,
        ];
    }

    private function isTextMime(string $mimetype): bool {
        if (str_starts_with($mimetype, 'text/')) {
            return true;
        }
        return in_array($mimetype, self::SUPPORTED_TEXT_MIMES, true);
    }

    /**
     * Build the AI prompt with field definitions and file content
     */
    private function buildPrompt(array $fields, string $fileContent, string $fileName): string {
        $fieldDescriptions = [];
        foreach ($fields as $field) {
            $desc = '"' . $field['field_name'] . '" (' . $field['field_type'];
            $desc .= ', label: "' . $field['field_label'] . '"';

            if (!empty($field['field_description'])) {
                $desc .= ', description: "' . $field['field_description'] . '"';
            }

            if (in_array($field['field_type'], ['select', 'multiselect', 'dropdown'], true) && !empty($field['field_options'])) {
                $options = is_array($field['field_options']) ? $field['field_options'] : [];
                $desc .= ', options: ' . implode(', ', $options);
            }

            $desc .= '): ';

            switch ($field['field_type']) {
                case 'text':
                case 'textarea':
                    $desc .= 'free text';
                    break;
                case 'number':
                    $desc .= 'return ONLY a number';
                    break;
                case 'date':
                    $desc .= 'return in YYYY-MM-DD format';
                    break;
                case 'select':
                case 'dropdown':
                    $desc .= 'pick exactly one of the options listed above';
                    break;
                case 'multiselect':
                    $desc .= 'pick one or more of the options, return as semicolon-separated values';
                    break;
                case 'checkbox':
                    $desc .= 'return 1 for yes or 0 for no';
                    break;
                case 'url':
                    $desc .= 'return a valid URL';
                    break;
                default:
                    $desc .= 'free text';
            }

            $fieldDescriptions[] = '- ' . $desc;
        }

        $fieldsBlock = implode("\n", $fieldDescriptions);

        return <<<PROMPT
Analyze the following file and extract metadata values.
Use the field label and description to understand the context and tone for each field.

File: {$fileName}

Fields to fill:
{$fieldsBlock}

Return ONLY valid JSON with field names as keys and suggested values as strings.
Skip fields you cannot determine from the content.
Use exact option values for select/multiselect fields.
Match the tone and style implied by each field's label and description.

File content:
{$fileContent}
PROMPT;
    }

    /**
     * Parse AI response JSON into field suggestions
     */
    private function parseAiResponse(string $response, array $fields): array {
        // Try to extract JSON from the response (AI might wrap it in markdown code blocks)
        $json = $response;
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $response, $matches)) {
            $json = $matches[1];
        } elseif (preg_match('/(\{.*\})/s', $response, $matches)) {
            $json = $matches[1];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        // Validate suggestions against field definitions
        $validFieldNames = [];
        $fieldTypes = [];
        foreach ($fields as $field) {
            $validFieldNames[] = $field['field_name'];
            $fieldTypes[$field['field_name']] = $field['field_type'];
        }

        $suggestions = [];
        foreach ($decoded as $fieldName => $value) {
            if (!in_array($fieldName, $validFieldNames, true)) {
                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }

            // Basic type validation
            $type = $fieldTypes[$fieldName] ?? 'text';
            $stringValue = (string)$value;

            switch ($type) {
                case 'number':
                    if (!is_numeric($stringValue)) {
                        continue 2;
                    }
                    break;
                case 'date':
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $stringValue)) {
                        continue 2;
                    }
                    break;
                case 'checkbox':
                    $stringValue = ($stringValue === '1' || strtolower($stringValue) === 'true' || strtolower($stringValue) === 'yes') ? '1' : '0';
                    break;
            }

            $suggestions[$fieldName] = $stringValue;
        }

        return $suggestions;
    }
}
