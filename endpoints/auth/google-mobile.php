<?php

declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../_bootstrap.php';

function google_client_id(): string
{
    return env_value(
        'GOOGLE_CLIENT_ID',
        '955736336306-0rovunqpcs0er6o1dog9360mh57tbcv4.apps.googleusercontent.com'
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
        throw new RuntimeException('Nao foi possivel validar o token do Google.');
    }

    $data = json_decode($response, true);

    if (!is_array($data)) {
        throw new RuntimeException('Resposta invalida ao validar o token do Google.');
    }

    if (($data['aud'] ?? '') !== google_client_id()) {
        throw new RuntimeException('O token Google informado nao pertence a este aplicativo.');
    }

    $emailVerified = (string) ($data['email_verified'] ?? '');

    if (!in_array($emailVerified, ['true', '1'], true)) {
        throw new RuntimeException('A conta Google informada nao possui email verificado.');
    }

    return $data;
}

function decode_payload_token(string $payload): string
{
    if ($payload === '') {
        return '';
    }

    $normalized = strtr($payload, '-_', '+/');
    $padding = strlen($normalized) % 4;

    if ($padding > 0) {
        $normalized .= str_repeat('=', 4 - $padding);
    }

    $decoded = base64_decode($normalized, true);

    if (!is_string($decoded) || $decoded === '') {
        return '';
    }

    $data = json_decode($decoded, true);

    if (!is_array($data)) {
        return '';
    }

    $value = trim((string) ($data['id'] ?? ''));
    return $value;
}

function decode_gateway_request(string $payload): array
{
    $payload = trim($payload);

    if ($payload === '') {
        return [];
    }

    $data = json_decode($payload, true);
    return is_array($data) ? $data : [];
}

function is_allowed_redirect_uri(string $redirectUri): bool
{
    if (stripos($redirectUri, 'liderdriver://') === 0) {
        return true;
    }

    if (!preg_match('/^https?:\/\//i', $redirectUri)) {
        return false;
    }

    $parts = parse_url($redirectUri);

    if (!is_array($parts)) {
        return false;
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    $path = (string) ($parts['path'] ?? '');

    if ($scheme === 'https' && $host === 'liderdriver.azurewebsites.net' && in_array($path, ['/login', '/login/'], true)) {
        return true;
    }

    if ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1'], true)) {
        return true;
    }

    return false;
}

function append_session_to_redirect_uri(string $redirectUri, array $sessionPayload): string
{
    $separator = strpos($redirectUri, '?') === false ? '?' : '&';
    $encodedSession = rawurlencode((string) json_encode($sessionPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    return $redirectUri . $separator . 'session=' . $encodedSession;
}

function map_service_item(array $item): array
{
    return [
        'id' => (int) ($item['id'] ?? 0),
        'slug' => (string) ($item['slug'] ?? ''),
        'name' => (string) ($item['nome'] ?? ''),
        'short_description' => (string) ($item['descricao_curta'] ?? ''),
        'full_description' => (string) ($item['descricao_completa'] ?? ''),
        'price' => (float) ($item['valor'] ?? 0),
        'sort_order' => (int) ($item['ordem_exibicao'] ?? 0),
        'is_active' => (bool) ($item['ativo'] ?? false),
    ];
}

function map_lead_item(array $item): array
{
    return [
        'id' => (int) ($item['id'] ?? 0),
        'name' => (string) ($item['nome'] ?? ''),
        'phone' => (string) ($item['telefone'] ?? ''),
        'origin' => (string) ($item['origem'] ?? ''),
        'destination' => (string) ($item['destino'] ?? ''),
        'travel_date' => (string) ($item['data_viagem'] ?? ''),
        'travel_time' => (string) ($item['hora_inicio'] ?? ''),
        'notes' => (string) ($item['observacoes'] ?? ''),
        'plan_slug' => (string) ($item['plano_slug'] ?? ''),
        'plan_name' => (string) ($item['plano_nome'] ?? ''),
        'status' => (string) ($item['status_atendimento'] ?? 'Novo'),
        'created_at' => isset($item['created_at']) ? (string) $item['created_at'] : '',
        'requester_email' => (string) ($item['solicitante_email'] ?? ''),
        'requester_name' => (string) ($item['solicitante_nome'] ?? ''),
        'requester_auth_provider' => (string) ($item['solicitante_auth_provider'] ?? ''),
        'driver_email' => (string) ($item['motorista_email'] ?? ''),
        'driver_name' => (string) ($item['motorista_nome'] ?? ''),
        'captured_at' => isset($item['capturado_em']) ? (string) $item['capturado_em'] : '',
        'started_at' => isset($item['iniciado_em']) ? (string) $item['iniciado_em'] : '',
        'finished_at' => isset($item['finalizado_em']) ? (string) $item['finalizado_em'] : '',
        'cancelled_at' => isset($item['cancelado_em']) ? (string) $item['cancelado_em'] : '',
        'cancelled_by_email' => (string) ($item['cancelado_por_email'] ?? ''),
        'cancelled_by_name' => (string) ($item['cancelado_por_nome'] ?? ''),
        'cancellation_reason' => (string) ($item['motivo_cancelamento'] ?? ''),
    ];
}

function dispatch_gateway_request(array $payload): void
{
    $action = trim((string) ($payload['a'] ?? ''));
    $data = isset($payload['d']) && is_array($payload['d']) ? $payload['d'] : [];

    switch ($action) {
        case 'dbs':
            $database = database_health();
            $status = $database['status'] === 'online' ? 200 : 500;
            $today = date('Y-m-d');

            json_response([
                'ok' => $database['status'] === 'online',
                'environment' => current_environment(),
                'generated_at' => gmdate('c'),
                'database' => $database,
                'modules' => [
                    [
                        'slug' => 'landing-page',
                        'title' => 'Landing Page executiva',
                        'description' => 'Pagina comercial abastecida pelo cadastro dos planos Standard, Gold, Platinum e Black.',
                        'status_label' => 'Estrutura pronta',
                        'reference_date' => $today,
                    ],
                    [
                        'slug' => 'cadastro-servicos',
                        'title' => 'Cadastro de servicos',
                        'description' => 'Area administrativa para editar valores e descricoes dos planos de transporte executivo.',
                        'status_label' => 'Em andamento',
                        'reference_date' => $today,
                    ],
                    [
                        'slug' => 'captacao-comercial',
                        'title' => 'Captacao comercial',
                        'description' => 'Formulario da landing page gravando solicitacoes de interesse em transporte executivo.',
                        'status_label' => 'Lead tracking inicial pronto',
                        'reference_date' => $today,
                    ],
                ],
            ], $status);

        case 'svl':
            $items = array_map('map_service_item', fetch_services());

            json_response([
                'ok' => true,
                'items' => $items,
            ]);

        case 'svs':
            $item = save_service($data);

            json_response([
                'ok' => true,
                'item' => map_service_item($item),
            ]);

        case 'ldl':
            $previousQuery = $_GET;
            $_GET = [];

            if (isset($data['status']) && trim((string) $data['status']) !== '' && (string) $data['status'] !== 'all') {
                $_GET['status'] = (string) $data['status'];
            }

            if (isset($data['startDate']) && trim((string) $data['startDate']) !== '') {
                $_GET['start_date'] = (string) $data['startDate'];
            }

            if (isset($data['endDate']) && trim((string) $data['endDate']) !== '') {
                $_GET['end_date'] = (string) $data['endDate'];
            }

            if (isset($data['requesterEmail']) && trim((string) $data['requesterEmail']) !== '') {
                $_GET['requester_email'] = (string) $data['requesterEmail'];
            }

            if (isset($data['driverEmail']) && trim((string) $data['driverEmail']) !== '') {
                $_GET['driver_email'] = (string) $data['driverEmail'];
            }

            try {
                $items = array_map('map_lead_item', fetch_leads());
            } finally {
                $_GET = $previousQuery;
            }

            json_response([
                'ok' => true,
                'items' => $items,
            ]);

        case 'lds':
            $item = save_lead($data);

            json_response([
                'ok' => true,
                'item' => map_lead_item($item),
            ], 201);

        case 'ldt':
            $item = take_lead_for_driver($data);

            json_response([
                'ok' => true,
                'item' => map_lead_item($item),
            ]);

        case 'ldc':
            $item = cancel_lead($data);

            json_response([
                'ok' => true,
                'item' => map_lead_item($item),
            ]);

        case 'ldu':
            $item = update_lead_status($data);

            json_response([
                'ok' => true,
                'item' => map_lead_item($item),
            ]);
    }

    json_response([
        'ok' => false,
        'error' => 'Acao de gateway invalida.',
    ], 422);
}

function render_status_page(string $title, string $message, bool $isError = false, ?string $redirectUrl = null): void
{
    header('Content-Type: text/html; charset=utf-8');
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
    $safeRedirectUrl = $redirectUrl !== null ? htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8') : null;
    $statusClass = $isError ? 'status error' : 'status';
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $safeTitle; ?></title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f6efe8;
            --card: #fff8f2;
            --line: rgba(110, 74, 52, 0.14);
            --text: #22150e;
            --muted: #6b5a4b;
            --accent: #7a3418;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            font-family: Arial, sans-serif;
            background: linear-gradient(180deg, #fbf4ed 0%, #f6ede5 100%);
            color: var(--text);
        }

        .card {
            width: min(100%, 420px);
            border-radius: 28px;
            background: var(--card);
            border: 1px solid var(--line);
            padding: 28px;
            box-shadow: 0 20px 50px rgba(63, 34, 16, 0.12);
        }

        .badge {
            display: inline-block;
            padding: 8px 12px;
            border-radius: 999px;
            background: var(--accent);
            color: #ffffff;
            font-weight: 700;
            font-size: 12px;
            letter-spacing: 0.08em;
        }

        h1 {
            margin: 18px 0 10px;
            font-size: 30px;
            line-height: 1.12;
        }

        .status {
            margin-top: 18px;
            padding: 14px 16px;
            border-radius: 16px;
            background: #fff;
            border: 1px solid var(--line);
            color: var(--muted);
            line-height: 1.6;
        }

        .status.error {
            color: #8f1f1f;
            border-color: rgba(143, 31, 31, 0.2);
            background: #fff5f5;
        }

        a.action {
            display: inline-block;
            margin-top: 18px;
            color: #ffffff;
            background: var(--accent);
            padding: 12px 16px;
            border-radius: 14px;
            text-decoration: none;
            font-weight: 700;
        }
    </style>
    <?php if ($safeRedirectUrl !== null): ?>
    <script>
        window.addEventListener('load', () => {
            window.location.replace(<?php echo json_encode($redirectUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>);
        });
    </script>
    <?php endif; ?>
</head>
<body>
    <main class="card">
        <span class="badge">LIDER DRIVER</span>
        <h1><?php echo $safeTitle; ?></h1>
        <div class="<?php echo $statusClass; ?>"><?php echo $safeMessage; ?></div>
        <?php if ($safeRedirectUrl !== null): ?>
            <a class="action" href="<?php echo $safeRedirectUrl; ?>">Voltar para o app</a>
        <?php endif; ?>
    </main>
</body>
</html>
    <?php
    exit;
}

$gatewayRequest = $requestMethod === 'POST'
    ? decode_gateway_request((string) ($_POST['q'] ?? ''))
    : [];

if ($gatewayRequest !== []) {
    dispatch_gateway_request($gatewayRequest);
}

$requestData = $requestMethod === 'POST' ? $_POST : $_GET;

$redirectUri = trim((string) ($requestData['redirect_uri'] ?? $_GET['redirect_uri'] ?? ''));
$defaultRedirectUri = 'liderdriver://oauth-callback';

if ($redirectUri === '') {
    $redirectUri = $defaultRedirectUri;
}

if (!is_allowed_redirect_uri($redirectUri)) {
    http_response_code(400);
    echo 'Redirect URI invalida.';
    exit;
}

$payloadValue = trim((string) ($requestData['payload'] ?? ''));
$idToken = decode_payload_token($payloadValue);

if ($idToken === '') {
    $idToken = trim((string) ($_SERVER['HTTP_X_GOOGLE_TOKEN'] ?? $requestData['google_token'] ?? $requestData['credential'] ?? ''));
}

if ($idToken !== '') {
    try {
        $database = database_health();

        if ($database['status'] !== 'online') {
            throw new RuntimeException('Nao foi possivel validar a conectividade com o banco Azure SQL Server.');
        }

        $googleUser = fetch_google_token_info($idToken);
        $email = trim((string) ($googleUser['email'] ?? ''));
        $name = trim((string) ($googleUser['name'] ?? $email));
        $photoUrl = trim((string) ($googleUser['picture'] ?? ''));

        if ($email === '') {
            throw new RuntimeException('Nao foi possivel identificar o email da conta Google.');
        }

        $sessionPayload = [
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
        ];

        $targetUrl = append_session_to_redirect_uri($redirectUri, $sessionPayload);
        render_status_page('Retornando ao app', 'Login concluido. Voce sera redirecionado automaticamente para o Lider Driver.', false, $targetUrl);
    } catch (Throwable $exception) {
        render_status_page('Falha no login Google', $exception->getMessage(), true);
    }
}

header('Content-Type: text/html; charset=utf-8');
$googleClientId = google_client_id();
$selfPath = './google-mobile.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entrar com Google</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f6efe8;
            --card: #fff8f2;
            --line: rgba(110, 74, 52, 0.14);
            --text: #22150e;
            --muted: #6b5a4b;
            --accent: #7a3418;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            font-family: Arial, sans-serif;
            background: linear-gradient(180deg, #fbf4ed 0%, #f6ede5 100%);
            color: var(--text);
        }

        .card {
            width: min(100%, 420px);
            border-radius: 28px;
            background: var(--card);
            border: 1px solid var(--line);
            padding: 28px;
            box-shadow: 0 20px 50px rgba(63, 34, 16, 0.12);
        }

        .badge {
            display: inline-block;
            padding: 8px 12px;
            border-radius: 999px;
            background: var(--accent);
            color: #ffffff;
            font-weight: 700;
            font-size: 12px;
            letter-spacing: 0.08em;
        }

        h1 {
            margin: 18px 0 10px;
            font-size: 30px;
            line-height: 1.12;
        }

        p {
            margin: 0 0 18px;
            color: var(--muted);
            line-height: 1.65;
        }

        #google-login-button {
            min-height: 44px;
        }

        .status {
            margin-top: 18px;
            padding: 14px 16px;
            border-radius: 16px;
            background: #fff;
            border: 1px solid var(--line);
            color: var(--muted);
            line-height: 1.55;
        }

        .status.error {
            color: #8f1f1f;
            border-color: rgba(143, 31, 31, 0.2);
            background: #fff5f5;
        }
    </style>
</head>
<body>
    <main class="card">
        <span class="badge">LIDER DRIVER</span>
        <h1>Entrar com Google</h1>
        <p>Conclua sua autenticacao no navegador. Assim que o Google validar sua conta, voce volta automaticamente para o app.</p>
        <div id="google-login-button"></div>
        <div id="status" class="status">Aguardando autenticacao...</div>
    </main>

    <script>
        const googleClientId = <?php echo json_encode($googleClientId, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
        const redirectUri = <?php echo json_encode($redirectUri, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
        const selfPath = <?php echo json_encode($selfPath, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
        const statusElement = document.getElementById('status');

        function encodePayload(value) {
            const json = JSON.stringify({ id: value });
            return btoa(json).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
        }

        function setStatus(message, isError = false) {
            statusElement.textContent = message;
            statusElement.classList.toggle('error', isError);
        }

        function decodeJwtPayload(token) {
            const parts = String(token || '').split('.');

            if (parts.length < 2) {
                throw new Error('Token Google invalido.');
            }

            const payload = parts[1]
                .replace(/-/g, '+')
                .replace(/_/g, '/');
            const padding = payload.length % 4;
            const normalized = padding ? payload + '='.repeat(4 - padding) : payload;
            const json = atob(normalized);
            return JSON.parse(json);
        }

        function validateGooglePayload(payload) {
            const nowInSeconds = Math.floor(Date.now() / 1000);

            if (payload.aud !== googleClientId) {
                throw new Error('A conta Google retornou um token de outro aplicativo.');
            }

            if (!payload.email) {
                throw new Error('Nao foi possivel identificar o email da conta Google.');
            }

            if (!(payload.email_verified === true || payload.email_verified === 'true' || payload.email_verified === '1')) {
                throw new Error('A conta Google informada nao possui email verificado.');
            }

            if (payload.exp && Number(payload.exp) < nowInSeconds) {
                throw new Error('O token Google expirou. Tente novamente.');
            }

            return payload;
        }

        function buildSessionPayload(payload) {
            const email = String(payload.email || '').trim();
            const displayName = String(payload.name || email).trim() || email;
            const photoUrl = String(payload.picture || '').trim();
            const loginAt = new Date().toISOString();

            return {
                ok: true,
                token: `${String(payload.sub || email)}|google|${Date.now()}`,
                identifier: email,
                email,
                display_name: displayName,
                unit_name: '',
                login_at: loginAt,
                auth_provider: 'google',
                photo_url: photoUrl,
                message: 'Login Google realizado com sucesso.'
            };
        }

        function handleGoogleCredential(response) {
            try {
                setStatus('Validando sua conta e retornando ao app...');
                const payload = validateGooglePayload(decodeJwtPayload(response.credential));
                const sessionPayload = buildSessionPayload(payload);
                const separator = redirectUri.includes('?') ? '&' : '?';
                const targetUrl = `${redirectUri}${separator}session=${encodeURIComponent(JSON.stringify(sessionPayload))}`;
                window.location.replace(targetUrl);
            } catch (error) {
                setStatus(error instanceof Error ? error.message : 'Falha ao concluir o login Google.', true);
            }
        }

        function initializeGoogle() {
            if (!window.google || !window.google.accounts || !window.google.accounts.id) {
                setStatus('O carregamento do Google falhou. Tente novamente em alguns instantes.', true);
                return;
            }

            window.google.accounts.id.initialize({
                client_id: googleClientId,
                callback: handleGoogleCredential
            });

            window.google.accounts.id.renderButton(
                document.getElementById('google-login-button'),
                {
                    theme: 'outline',
                    size: 'large',
                    shape: 'pill',
                    width: 280,
                    text: 'continue_with'
                }
            );

            window.google.accounts.id.prompt();
            setStatus('Use sua conta Google para continuar no Lider Driver.');
        }
    </script>
    <script async defer src="https://accounts.google.com/gsi/client" onload="initializeGoogle()"></script>
</body>
</html>
