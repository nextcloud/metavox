<?php

/**
 * MetaVox Performance Test Configuration
 *
 * Kopieer dit bestand naar config.php en pas aan voor jouw omgeving
 */

return [
    // Aantal warmup iteraties voor elke benchmark
    'warmup_iterations' => 3,

    // Aantal test iteraties voor nauwkeurige metingen
    'test_iterations' => 10,

    // Aantal concurrent users voor concurrency tests
    'concurrent_users' => 10,

    // Batch size voor bulk operaties
    'batch_size' => 1000,

    // Maximum aantal metadata records om te genereren
    // Voor 10 miljoen records:
    'max_records' => 10000000,

    // Timeout voor lange operaties (in seconden)
    'timeout_seconds' => 3600, // 1 uur

    // Performance thresholds (in milliseconden)
    'thresholds' => [
        'getAllFields' => [
            'target' => 100,
            'warning' => 500,
            'critical' => 1000,
        ],
        'getFieldById' => [
            'target' => 5,
            'warning' => 20,
            'critical' => 50,
        ],
        'saveFieldValue' => [
            'target' => 10,
            'warning' => 50,
            'critical' => 100,
        ],
        'getFieldMetadata' => [
            'target' => 20,
            'warning' => 100,
            'critical' => 200,
        ],
        'getBulkFileMetadata_100' => [
            'target' => 500,
            'warning' => 2000,
            'critical' => 5000,
        ],
        'simple_filter' => [
            'target' => 200,
            'warning' => 1000,
            'critical' => 2000,
        ],
        'complex_filter_5' => [
            'target' => 500,
            'warning' => 2000,
            'critical' => 5000,
        ],
        'search_fulltext' => [
            'target' => 100,
            'warning' => 500,
            'critical' => 1000,
        ],
        'search_index_update' => [
            'target' => 50,
            'warning' => 200,
            'critical' => 500,
        ],
    ],

    // Throughput thresholds (operaties per seconde)
    'throughput_thresholds' => [
        'read_ops' => [
            'target' => 1000,
            'warning' => 500,
            'critical' => 100,
        ],
        'write_ops' => [
            'target' => 100,
            'warning' => 50,
            'critical' => 10,
        ],
    ],
];
