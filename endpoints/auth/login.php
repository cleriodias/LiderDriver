<?php

declare(strict_types=1);

require __DIR__ . '/../_bootstrap.php';

$payload = input_json();
$identifier = trim((string) ($payload['identifier'] ?? ''));
$password = trim((string) ($payload['password'] ?? ''));
$unitName = trim((string) ($payload['unitName'] ?? ''));

if ($identifier === '' || $password === '') {
    json_response([
        'ok' => false,
        'error' => 'Usuario e senha sao obrigatorios.',
    ], 422);
}

$database = database_health();

if ($database['status'] !== 'online') {
    json_response([
        'ok' => false,
        'error' => 'Nao foi possivel validar a conectividade com o banco Azure SQL Server.',
        'database' => $database,
    ], 500);
}

json_response([
    'ok' => true,
    'token' => hash('sha256', $identifier . '|' . gmdate('c')),
    'identifier' => $identifier,
    'display_name' => $identifier,
    'unit_name' => $unitName,
    'login_at' => gmdate('c'),
    'message' => 'Acesso inicial liberado. A validacao real de usuarios entra na proxima etapa.',
]);

