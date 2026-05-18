<?php

declare(strict_types=1);

require __DIR__ . '/../_bootstrap.php';

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

$redirectUri = trim((string) ($_GET['redirect_uri'] ?? ''));
$defaultRedirectUri = 'liderdriver://oauth-callback';

if ($redirectUri === '') {
    $redirectUri = $defaultRedirectUri;
}

if (stripos($redirectUri, 'liderdriver://') !== 0) {
    http_response_code(400);
    echo 'Redirect URI invalida.';
    exit;
}

$idToken = trim((string) ($_GET['credential'] ?? ''));

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

        $targetUrl = $redirectUri . '?session=' . rawurlencode((string) json_encode($sessionPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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

        function setStatus(message, isError = false) {
            statusElement.textContent = message;
            statusElement.classList.toggle('error', isError);
        }

        function handleGoogleCredential(response) {
            setStatus('Validando sua conta e retornando ao app...');
            const targetUrl = `${selfPath}?redirect_uri=${encodeURIComponent(redirectUri)}&credential=${encodeURIComponent(response.credential)}`;
            window.location.replace(targetUrl);
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
