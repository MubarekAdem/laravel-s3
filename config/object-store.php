<?php

return [
    'region' => env('OBJECT_STORE_REGION', 'us-east-1'),
    'root' => env('OBJECT_STORE_PATH', storage_path('app/object-store')),
    'max_bytes' => (int) env('OBJECT_STORE_MAX_BYTES', 104857600),
    'trash_days' => (int) env('OBJECT_STORE_TRASH_DAYS', 30),
    'multipart_hours' => (int) env('OBJECT_STORE_MULTIPART_HOURS', 24),
];
