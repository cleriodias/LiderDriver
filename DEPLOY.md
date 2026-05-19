# Deploy dos endpoints na Azure

Esta base publica os endpoints PHP consumidos pelo app Android `Lider Driver`.

O objetivo e disponibilizar a pasta `endpoints/` em uma Azure Web App neste formato:

```text
https://liderdriver.azurewebsites.net/endpoints
```

## Estrutura inicial

- `endpoints/`: endpoints PHP da API mobile.
- `web.config`: configuracao minima para IIS/App Service.
- `deploy/`: pasta pronta para empacotar a publicacao.
- `deploy.env.example`: variaveis esperadas no App Service.
- `.github/workflows/deploy-azure.yml`: publica os arquivos via Kudu VFS.
- `scripts/build-azure-package.ps1`: monta localmente a pasta `deploy/`.
- `scripts/publish-azure-vfs.ps1`: publica localmente arquivo por arquivo na Azure.

## Variaveis obrigatorias

Configure em `App Service > Configuration > Application settings`:

```text
APP_ENV
DB_SERVER
DB_DATABASE
DB_USERNAME
DB_PASSWORD
DB_ENCRYPT
DB_TRUST_CERTIFICATE
DB_LOGIN_TIMEOUT
```

## Endpoints iniciais

- `GET /endpoints/health.php`: valida a conectividade com Azure SQL Server.
- `POST /endpoints/auth/login.php`: acesso inicial da fase 1.
- `GET /endpoints/mobile/dashboard/`: resumo inicial do painel mobile.
- `POST /endpoints/g.php`: gateway publico neutro para painel, planos e solicitacoes.

## Publicacao recomendada

Este projeto deve seguir o mesmo padrao do `C:\xampp\htdocs\apec-rodrigo`:

1. montar a pasta `deploy/`
2. publicar os arquivos individualmente via Kudu VFS
3. validar os endpoints publicos logo depois do envio

O Zip Deploy pode deixar arquivos presentes no `wwwroot`, mas ainda assim falhar no comportamento publico de alguns App Services.

### Build local do pacote

```powershell
.\scripts\build-azure-package.ps1
```

### Publicacao local na Azure

```powershell
.\scripts\publish-azure-vfs.ps1
```

## Observacao

Nesta primeira fase, o login ainda nao valida uma tabela real de usuarios. Ele apenas confirma a estrutura da requisicao e a conectividade com o banco. A proxima etapa deve mapear as tabelas existentes para substituir esse acesso inicial por autenticacao real.
