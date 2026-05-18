<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$database = database_health();
$status = $database['status'] === 'online' ? 200 : 500;

json_response([
    'ok' => $database['status'] === 'online',
    'environment' => current_environment(),
    'database' => $database,
    'generated_at' => gmdate('c'),
], $status);

