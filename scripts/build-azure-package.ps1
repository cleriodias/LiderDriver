$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$deployDir = Join-Path $root 'deploy'
$distDir = Join-Path $root 'dist'
$endpointsDir = Join-Path $root 'endpoints'
$webConfigPath = Join-Path $root 'web.config'
$zipPath = Join-Path $root 'azure-package.zip'
$requiredFiles = @(
    (Join-Path $endpointsDir '_bootstrap.php'),
    (Join-Path $endpointsDir 'g.php'),
    (Join-Path $endpointsDir 'auth\google-mobile.php'),
    (Join-Path $distDir 'index.html')
)
$spaRoutes = @(
    'login',
    'landing',
    'dashboard',
    'servicos',
    'solicitacoes'
)

if (-not (Test-Path -LiteralPath $endpointsDir)) {
    throw 'A pasta endpoints nao existe.'
}

if (-not (Test-Path -LiteralPath $distDir)) {
    throw 'A pasta dist nao existe. Gere primeiro o build web.'
}

foreach ($requiredFile in $requiredFiles) {
    if (-not (Test-Path -LiteralPath $requiredFile)) {
        throw "Arquivo obrigatorio ausente: $requiredFile"
    }
}

if (Test-Path -LiteralPath $deployDir) {
    Remove-Item -LiteralPath $deployDir -Recurse -Force
}

if (Test-Path -LiteralPath $zipPath) {
    Remove-Item -LiteralPath $zipPath -Force
}

New-Item -ItemType Directory -Path $deployDir | Out-Null
Get-ChildItem -LiteralPath $distDir | ForEach-Object {
    Copy-Item -LiteralPath $_.FullName -Destination (Join-Path $deployDir $_.Name) -Recurse
}
Copy-Item -LiteralPath $endpointsDir -Destination (Join-Path $deployDir 'endpoints') -Recurse
Copy-Item -LiteralPath $webConfigPath -Destination (Join-Path $deployDir 'web.config')

$deployIndexPath = Join-Path $deployDir 'index.html'

foreach ($route in $spaRoutes) {
    $routeDir = Join-Path $deployDir $route
    New-Item -ItemType Directory -Path $routeDir -Force | Out-Null
    Copy-Item -LiteralPath $deployIndexPath -Destination (Join-Path $routeDir 'index.html') -Force
}

$packagedGateway = Join-Path $deployDir 'endpoints\g.php'
$packagedGoogleMobile = Join-Path $deployDir 'endpoints\auth\google-mobile.php'
$packagedIndex = Join-Path $deployDir 'index.html'

if (-not (Test-Path -LiteralPath $packagedGateway) -or -not (Test-Path -LiteralPath $packagedGoogleMobile) -or -not (Test-Path -LiteralPath $packagedIndex)) {
    throw 'Os arquivos obrigatorios da landing e dos endpoints nao foram copiados para a pasta deploy.'
}

Compress-Archive -Path (Join-Path $deployDir '*') -DestinationPath $zipPath -Force

Write-Host "Pacote criado em: $zipPath"
