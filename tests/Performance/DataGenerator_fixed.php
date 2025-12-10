<?php

// Alleen de createFieldDefinitions methode met gefixte field keys

    /**
     * Maak verschillende field types via FieldService
     */
    private function createFieldDefinitions(): void {
        $this->log("Creating field definitions...");

        $fieldTypes = [
            [
                'field_name' => 'perf_test_title',
                'field_label' => 'Title',
                'field_type' => 'text',
                'field_description' => 'Document title',
                'is_required' => true,
                'field_options' => [],
            ],
            [
                'field_name' => 'perf_test_description',
                'field_label' => 'Description',
                'field_type' => 'textarea',
                'field_description' => 'Document description',
                'is_required' => false,
                'field_options' => [],
            ],
            [
                'field_name' => 'perf_test_category',
                'field_label' => 'Category',
                'field_type' => 'select',
                'field_description' => 'Document category',
                'is_required' => false,
                'field_options' => [
                    'financial',
                    'hr',
                    'legal',
                    'marketing',
                    'sales',
                    'support',
                    'technical',
                    'other',
                ],
            ],
            [
                'field_name' => 'perf_test_status',
                'field_label' => 'Status',
                'field_type' => 'select',
                'field_description' => 'Document status',
                'is_required' => true,
                'field_options' => [
                    'draft',
                    'review',
                    'approved',
                    'archived',
                ],
            ],
            [
                'field_name' => 'perf_test_priority',
                'field_label' => 'Priority',
                'field_type' => 'number',
                'field_description' => 'Priority level (1-5)',
                'is_required' => false,
                'field_options' => [],
            ],
            [
                'field_name' => 'perf_test_created_date',
                'field_label' => 'Created Date',
                'field_type' => 'date',
                'field_description' => 'Creation date',
                'is_required' => false,
                'field_options' => [],
            ],
            [
                'field_name' => 'perf_test_due_date',
                'field_label' => 'Due Date',
                'field_type' => 'date',
                'field_description' => 'Due date',
                'is_required' => false,
                'field_options' => [],
            ],
            [
                'field_name' => 'perf_test_archived',
                'field_label' => 'Archived',
                'field_type' => 'checkbox',
                'field_description' => 'Is archived',
                'is_required' => false,
                'field_options' => [],
            ],
            [
                'field_name' => 'perf_test_tags',
                'field_label' => 'Tags',
                'field_type' => 'multiselect',
                'field_description' => 'Document tags',
                'is_required' => false,
                'field_options' => [
                    'urgent',
                    'confidential',
                    'public',
                    'internal',
                    'external',
                    'review-needed',
                    'approved',
                ],
            ],
            [
                'field_name' => 'perf_test_department',
                'field_label' => 'Department',
                'field_type' => 'select',
                'field_description' => 'Owning department',
                'is_required' => false,
                'field_options' => [
                    'engineering',
                    'finance',
                    'hr',
                    'legal',
                    'marketing',
                    'operations',
                    'sales',
                ],
            ],
        ];

        foreach ($fieldTypes as $fieldData) {
            // Add scope
            $fieldData['scope'] = 'global';

            $field = $this->measure(
                fn() => $this->fieldService->createField($fieldData),
                'create_field',
                ['field_name' => $fieldData['field_name']]
            );

            $this->createdFields[] = $field;
            $this->log("Created field: {$fieldData['field_name']} (ID: {$field})");
        }

        $this->log("Created " . count($this->createdFields) . " field definitions");
    }
