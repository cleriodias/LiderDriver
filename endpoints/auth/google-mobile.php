<?php

declare(strict_types=1);

require __DIR__ . '/../_bootstrap.php';

header('Content-Type: text/html; charset=utf-8');

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

$googleClientId = env_value(
    'GOOGLE_CLIENT_ID',
    '955736336306-i0ph791ks9ak4nkbub8hr4tcu19mqj35.apps.googleusercontent.com'
);
$loginEndpoint = './login.php';
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

        * {
            box-sizing: border-box;
        }

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
        const loginEndpoint = <?php echo json_encode($loginEndpoint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
        const redirectUri = <?php echo json_encode($redirectUri, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
        const statusElement = document.getElementById('status');

        function setStatus(message, isError = false) {
            statusElement.textContent = message;
            statusElement.classList.toggle('error', isError);
        }

        async function handleGoogleCredential(response) {
            setStatus('Validando sua conta e retornando ao app...');

            try {
                const request = await fetch(loginEndpoint, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        provider: 'google',
                        idToken: response.credential
                    })
                });

                const payload = await request.json().catch(() => null);

                if (!request.ok || !payload || !payload.ok) {
                    throw new Error(payload && (payload.error || payload.message) ? (payload.error || payload.message) : 'Nao foi possivel concluir a autenticacao Google.');
                }

                const targetUrl = `${redirectUri}?session=${encodeURIComponent(JSON.stringify(payload))}`;
                window.location.replace(targetUrl);
            } catch (error) {
                setStatus(error instanceof Error ? error.message : 'Falha ao concluir o login com Google.', true);
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
