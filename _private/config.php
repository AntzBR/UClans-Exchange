<?php
return [
    'max_upload_bytes' => 100 * 1024 * 1024,
    'max_extracted_files' => 2000,
    'max_extracted_bytes' => 300 * 1024 * 1024,
    'result_ttl' => 3600,
    'conservative_minor_distance' => 6,
    'templates_v8' => __DIR__ . '/templates/v8',
    'templates_v9' => __DIR__ . '/templates/v9',
    'sqlite_templates_v9' => __DIR__ . '/templates/sqlite-v9',
    'yaml_templates_v9' => __DIR__ . '/templates/yaml-v9',
    'runtime' => __DIR__ . '/runtime',
    'rate_limit_client_max' => 3,
    'rate_limit_client_window' => 600,
    'rate_limit_global_max' => 30,
    'rate_limit_global_window' => 3600,
];
