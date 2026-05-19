$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$deployDir = Join-Path $root 'deploy'
$endpointsDir = Join-Path $root 'endpoints'
$webConfigPath = Join-Path $root 'web.config'
$zipPath = Join-Path $root 'azure-package.zip'
$requiredFiles = @(
    (Join-Path $endpointsDir '_bootstrap.php'),
    (Join-Path $endpointsDir 'g.php'),
    (Join-Path $endpointsDir 'auth\google-mobile.php')
)

if (-not (Test-Path -LiteralPath $endpointsDir)) {
    throw 'A pasta endpoints nao existe.'
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
Copy-Item -LiteralPath $endpointsDir -Destination (Join-Path $deployDir 'endpoints') -Recurse
Copy-Item -LiteralPath $webConfigPath -Destination (Join-Path $deployDir 'web.config')

$packagedGateway = Join-Path $deployDir 'endpoints\g.php'
$packagedGoogleMobile = Join-Path $deployDir 'endpoints\auth\google-mobile.php'

if (-not (Test-Path -LiteralPath $packagedGateway) -or -not (Test-Path -LiteralPath $packagedGoogleMobile)) {
    throw 'Os endpoints publicos obrigatorios nao foram copiados para a pasta deploy.'
}

Compress-Archive -Path (Join-Path $deployDir '*') -DestinationPath $zipPath -Force

Write-Host "Pacote criado em: $zipPath"
