<?php

declare(strict_types=1);

require __DIR__ . '/../_bootstrap.php';

$payload = input_json();
$idToken = trim((string) ($payload['idToken'] ?? ''));

function google_client_id(): string
{
    return env_value(
        'GOOGLE_CLIENT_ID',
        '955736336306-i0ph791ks9ak4nkbub8hr4tcu19mqj35.apps.googleusercontent.com'
    );
}

function fetch_google_token_info(string $token): array
{
    $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($token);
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 15,
            'header' => "Accept: application/json\r\nUser-Agent: LiderDriver/1.0\r\n",
        ],
    ]);

    $response = @file_get_contents($url, false, $context);

    if (!is_string($response) || $response === '') {
        json_response([
            'ok' => false,
            'error' => 'Nao foi possivel validar o token do Google.',
        ], 401);
    }

    $data = json_decode($response, true);

    if (!is_array($data)) {
        json_response([
            'ok' => false,
            'error' => 'Resposta invalida ao validar o token do Google.',
        ], 401);
    }

    if (($data['aud'] ?? '') !== google_client_id()) {
        json_response([
            'ok' => false,
            'error' => 'O token Google informado nao pertence a este aplicativo.',
        ], 401);
    }

    $emailVerified = (string) ($data['email_verified'] ?? '');

    if (!in_array($emailVerified, ['true', '1'], true)) {
        json_response([
            'ok' => false,
            'error' => 'A conta Google informada nao possui email verificado.',
        ], 401);
    }

    return $data;
}

$database = database_health();

if ($database['status'] !== 'online') {
    json_response([
        'ok' => false,
        'error' => 'Nao foi possivel validar a conectividade com o banco Azure SQL Server.',
        'database' => $database,
    ], 500);
}

if ($idToken === '') {
    json_response([
        'ok' => false,
        'error' => 'Token Google obrigatorio.',
    ], 422);
}

$googleUser = fetch_google_token_info($idToken);
$email = trim((string) ($googleUser['email'] ?? ''));
$name = trim((string) ($googleUser['name'] ?? $payload['name'] ?? $email));
$photoUrl = trim((string) ($googleUser['picture'] ?? $payload['photoUrl'] ?? ''));

if ($email === '') {
    json_response([
        'ok' => false,
        'error' => 'Nao foi possivel identificar o email da conta Google.',
    ], 401);
}

json_response([
    'ok' => true,
    'token' => hash('sha256', $email . '|google|' . gmdate('c')),
    'identifier' => $email,
    'email' => $email,
    'display_name' => $name !== '' ? $name : $email,
    'unit_name' => '',
    'login_at' => gmdate('c'),
    'auth_provider' => 'google',
    'photo_url' => $photoUrl,
    'message' => 'Login Google realizado com sucesso.',
]);
