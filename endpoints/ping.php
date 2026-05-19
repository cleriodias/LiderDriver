<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'ping' => 'pong',
    'ts' => gmdate('c'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
