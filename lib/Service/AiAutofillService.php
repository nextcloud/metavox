<?php

declare(strict_types=1);

namespace OCA\MetaVox\Service;

use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\TaskProcessing\IManager as ITaskManager;
use OCP\TaskProcessing\Task;
use Psr\Log\LoggerInterface;

class AiAutofillService {

    private ITaskManager $taskManager;
    private IRootFolder $rootFolder;
    private FieldService $fieldService;
    private IConfig $config;
    private LoggerInterface $logger;

    private const APP_ID = 'metavox';
    private const MAX_CONTENT_LENGTH = 32000;
    private const SUPPORTED_TEXT_MIMES = [
        'text/plain', 'text/csv', 'text/html', 'text/xml', 'text/markdown',
        'application/json', 'application/xml', 'application/xhtml+xml',
    ];

    public function __construct(
        ITaskManager $taskManager,
        IRootFolder $rootFolder,
        FieldService $fieldService,
        IConfig $config,
        LoggerInterface $logger
    ) {
        $this->taskManager = $taskManager;
        $this->rootFolder = $rootFolder;
        $this->fieldService = $fieldService;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Check if AI is enabled by admin
     */
    public function isEnabledByAdmin(): bool {
        return $this->config->getAppValue(self::APP_ID, 'ai_enabled', 'true') === 'true';
    }

    /**
     * Enable or disable AI autofill
     */
    public function setEnabled(bool $enabled): void {
        $this->config->setAppValue(self::APP_ID, 'ai_enabled', $enabled ? 'true' : 'false');
    }

    private const TASK_TYPES = ['core:text2text', 'core:text2text:chat'];

    /**
     * Check if AI text generation is available and enabled
     */
    public function isAvailable(): bool {
        if (!$this->isEnabledByAdmin()) {
            return false;
        }
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
    public function generateMetadata(int $fileId, int $groupfolderId, string $userId, array $rejectedSuggestions = []): array {
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
        $prompt = $this->buildPrompt($aiFields, $fileContent['content'], $fileContent['name'], $rejectedSuggestions);

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

        // Try to read file content based on type
        $assistantMimes = [
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.oasis.opendocument.text',
        ];

        if ($this->isTextMime($mimetype)) {
            try {
                $raw = $node->getContent();
                $content = mb_substr($raw, 0, self::MAX_CONTENT_LENGTH);
            } catch (\Exception $e) {
                // Fall back to filename-only
            }
        } elseif (in_array($mimetype, $assistantMimes, true)) {
            // Use Nextcloud Assistant's native parse-file API for PDF, DOCX, ODT
            $content = $this->extractTextViaAssistant($node->getId());
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
     * Extract text from PDF, DOCX, or ODT using the same libraries as Nextcloud Assistant.
     * Loads smalot/pdfparser and phpoffice/phpword from the Assistant app's vendor directory.
     * Path is resolved dynamically via OC_App::getAppPath('assistant').
     * Falls back to empty string if Assistant app is not installed.
     */
    private function extractTextViaAssistant(int $fileId): string {
        try {
            // Dynamically resolve the assistant app path
            try {
                $assistantPath = \OC::$server->getAppManager()->getAppPath('assistant');
            } catch (\Exception $e) {
                $this->logger->debug('MetaVox AI: Assistant app not installed, cannot parse file');
                return '';
            }

            $autoloader = $assistantPath . '/vendor/autoload.php';
            if (!file_exists($autoloader)) {
                $this->logger->debug('MetaVox AI: Assistant vendor autoloader not found at ' . $autoloader);
                return '';
            }

            require_once $autoloader;

            // Get the file node
            $userFolder = null;
            // Try to get the node from any mount
            $nodes = $this->rootFolder->getById($fileId);
            if (empty($nodes)) {
                return '';
            }
            $node = $nodes[0];
            $mimetype = $node->getMimeType();

            if ($mimetype === 'application/pdf') {
                return $this->parsePdf($node);
            } elseif ($mimetype === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
                return $this->parseDocx($node);
            } elseif ($mimetype === 'application/vnd.oasis.opendocument.text') {
                return $this->parseOdt($node);
            }

            return '';
        } catch (\Exception $e) {
            $this->logger->debug('MetaVox AI: file parsing failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Parse PDF using smalot/pdfparser (same as Nextcloud Assistant)
     */
    private function parsePdf($node): string {
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseContent($node->getContent());
            $text = $pdf->getText();
            return mb_substr(trim($text), 0, self::MAX_CONTENT_LENGTH);
        } catch (\Exception $e) {
            $this->logger->debug('MetaVox AI: PDF parsing failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Parse DOCX using phpoffice/phpword (same as Nextcloud Assistant)
     */
    private function parseDocx($node): string {
        try {
            $tmpFile = tempnam(sys_get_temp_dir(), 'metavox_docx_');
            file_put_contents($tmpFile, $node->getContent());

            $reader = \PhpOffice\PhpWord\IOFactory::createReader('Word2007');
            $phpWord = $reader->load($tmpFile);
            unlink($tmpFile);

            $text = '';
            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getElements() as $element) {
                    $text .= $this->extractPhpWordElementText($element) . "\n";
                }
            }
            return mb_substr(trim($text), 0, self::MAX_CONTENT_LENGTH);
        } catch (\Exception $e) {
            $this->logger->debug('MetaVox AI: DOCX parsing failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Parse ODT using phpoffice/phpword (same as Nextcloud Assistant)
     */
    private function parseOdt($node): string {
        try {
            $tmpFile = tempnam(sys_get_temp_dir(), 'metavox_odt_');
            file_put_contents($tmpFile, $node->getContent());

            $reader = \PhpOffice\PhpWord\IOFactory::createReader('ODText');
            $phpWord = $reader->load($tmpFile);
            unlink($tmpFile);

            $text = '';
            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getElements() as $element) {
                    $text .= $this->extractPhpWordElementText($element) . "\n";
                }
            }
            return mb_substr(trim($text), 0, self::MAX_CONTENT_LENGTH);
        } catch (\Exception $e) {
            $this->logger->debug('MetaVox AI: ODT parsing failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Recursively extract text from PhpWord elements
     */
    private function extractPhpWordElementText($element): string {
        $text = '';
        if (method_exists($element, 'getText')) {
            $t = $element->getText();
            if (is_string($t)) {
                $text .= $t;
            } elseif (is_object($t) && method_exists($t, 'getText')) {
                $text .= $t->getText();
            }
        }
        if (method_exists($element, 'getElements')) {
            foreach ($element->getElements() as $child) {
                $text .= $this->extractPhpWordElementText($child);
            }
        }
        return $text;
    }

    /**
     * Build the AI prompt with field definitions and file content
     */
    private function buildPrompt(array $fields, string $fileContent, string $fileName, array $rejectedSuggestions = []): string {
        $fieldDescriptions = [];
        foreach ($fields as $field) {
            $desc = '"' . $field['field_name'] . '" (' . $field['field_type'];
            $desc .= ', label: "' . $field['field_label'] . '"';

            if (!empty($field['field_description'])) {
                $desc .= ', description: "' . $field['field_description'] . '"';
            }

            if (in_array($field['field_type'], ['select', 'multiselect', 'dropdown'], true) && !empty($field['field_options'])) {
                $options = is_array($field['field_options'])
                    ? $field['field_options']
                    : array_filter(explode("\n", (string)$field['field_options']));
                // Quote each option so the AI knows the exact values
                $quoted = array_map(fn($o) => '"' . $o . '"', $options);
                $desc .= ', ALLOWED VALUES: [' . implode(', ', $quoted) . ']';
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
                    $desc .= 'MUST return exactly one of the ALLOWED VALUES listed above, or skip this field. Do NOT invent new values.';
                    break;
                case 'multiselect':
                    $desc .= 'MUST return one or more of the ALLOWED VALUES as semicolon-separated string (e.g. "val1;#val2"), or skip this field. Do NOT invent new values.';
                    break;
                case 'checkbox':
                    $desc .= 'return "1" for yes or "0" for no';
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

        $rejectedBlock = '';
        if (!empty($rejectedSuggestions)) {
            $rejectedLines = [];
            foreach ($rejectedSuggestions as $fieldName => $oldValue) {
                $rejectedLines[] = '- "' . $fieldName . '": rejected value was "' . $oldValue . '"';
            }
            $rejectedBlock = "\n\nPREVIOUSLY REJECTED SUGGESTIONS (the user did not accept these — provide DIFFERENT values):\n" . implode("\n", $rejectedLines);
        }

        return <<<PROMPT
Analyze the following file and extract metadata values.
Use the field label and description to understand the context and tone for each field.

File: {$fileName}

Fields to fill:
{$fieldsBlock}

IMPORTANT RULES:
1. Return ONLY valid JSON with field names as keys and suggested values as strings.
2. For select/dropdown/multiselect fields: you MUST use EXACTLY one of the ALLOWED VALUES listed. Do NOT invent or rephrase options. If none of the allowed values match, skip the field entirely.
3. Match the tone and style implied by each field's label and description.
4. Try your best to fill as many fields as possible. Use the file name, path, type, and any available context to make reasonable suggestions. Only skip a field if you truly have no basis for a suggestion.
5. For checkbox fields, always provide a value ("1" or "0") based on your best judgment.{$rejectedBlock}

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
        } elseif (preg_match('/(\{.*)/s', $response, $matches)) {
            // JSON was truncated (no closing brace) — try to repair
            $json = $matches[1];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            // Attempt to fix truncated JSON: remove trailing incomplete entry and close
            $repaired = preg_replace('/,\s*"[^"]*"?\s*:?\s*"?[^"]*$/', '', $json);
            $repaired = rtrim($repaired, ", \n\r\t") . '}';
            $decoded = json_decode($repaired, true);
        }
        if (!is_array($decoded)) {
            return [];
        }

        // Validate suggestions against field definitions
        $validFieldNames = [];
        $fieldTypes = [];
        $fieldOptions = [];
        foreach ($fields as $field) {
            $validFieldNames[] = $field['field_name'];
            $fieldTypes[$field['field_name']] = $field['field_type'];
            if (!empty($field['field_options'])) {
                $fieldOptions[$field['field_name']] = is_array($field['field_options'])
                    ? $field['field_options']
                    : array_filter(explode("\n", (string)$field['field_options']));
            }
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
            $stringValue = is_bool($value) ? ($value ? '1' : '0') : (string)$value;

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
                case 'select':
                case 'dropdown':
                    // Must match one of the allowed options
                    if (isset($fieldOptions[$fieldName])) {
                        if (!in_array($stringValue, $fieldOptions[$fieldName], true)) {
                            // Try case-insensitive match
                            $matched = false;
                            foreach ($fieldOptions[$fieldName] as $opt) {
                                if (strcasecmp($opt, $stringValue) === 0) {
                                    $stringValue = $opt; // Use exact option value
                                    $matched = true;
                                    break;
                                }
                            }
                            if (!$matched) {
                                error_log('MetaVox AI: dropped invalid dropdown value "' . $stringValue . '" for field ' . $fieldName . ' (allowed: ' . implode(', ', $fieldOptions[$fieldName]) . ')');
                                continue 2; // Skip invalid value
                            }
                        }
                    }
                    break;
                case 'multiselect':
                    // Validate each value in the semicolon-separated list
                    if (isset($fieldOptions[$fieldName])) {
                        $parts = preg_split('/[;#]+/', $stringValue);
                        $validParts = [];
                        foreach ($parts as $part) {
                            $part = trim($part);
                            if (empty($part)) continue;
                            if (in_array($part, $fieldOptions[$fieldName], true)) {
                                $validParts[] = $part;
                            } else {
                                // Try case-insensitive match
                                foreach ($fieldOptions[$fieldName] as $opt) {
                                    if (strcasecmp($opt, $part) === 0) {
                                        $validParts[] = $opt;
                                        break;
                                    }
                                }
                            }
                        }
                        if (empty($validParts)) {
                            continue 2;
                        }
                        $stringValue = implode(';#', $validParts);
                    }
                    break;
            }

            $suggestions[$fieldName] = $stringValue;
        }

        return $suggestions;
    }
}
