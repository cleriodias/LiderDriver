$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$deployDir = Join-Path $root 'deploy'
$publishSettingsPath = Join-Path $root 'liderdriver.PublishSettings'

if (-not (Test-Path -LiteralPath $deployDir)) {
    throw 'A pasta deploy nao existe. Rode primeiro scripts/build-azure-package.ps1.'
}

if (-not (Test-Path -LiteralPath $publishSettingsPath)) {
    throw 'Arquivo liderdriver.PublishSettings nao encontrado.'
}

[xml]$xml = Get-Content -LiteralPath $publishSettingsPath
$profile = $xml.publishData.publishProfile | Where-Object { $_.publishMethod -eq 'ZipDeploy' } | Select-Object -First 1

if (-not $profile) {
    throw 'Publish profile ZipDeploy nao encontrado.'
}

$publishUrl = [string] $profile.publishUrl
$username = [string] $profile.userName
$password = [string] $profile.userPWD

if ([string]::IsNullOrWhiteSpace($publishUrl) -or [string]::IsNullOrWhiteSpace($username) -or [string]::IsNullOrWhiteSpace($password)) {
    throw 'Publish profile incompleto.'
}

$scmHost = $publishUrl.Split(':')[0]

function Upload-File {
    param(
        [Parameter(Mandatory = $true)][string] $SourcePath,
        [Parameter(Mandatory = $true)][string] $TargetPath
    )

    $uri = "https://$scmHost/api/vfs/site/wwwroot/$TargetPath"
    $response = curl.exe `
        --retry 5 `
        --retry-all-errors `
        --retry-delay 5 `
        --connect-timeout 20 `
        -u "${username}:${password}" `
        -X PUT `
        --data-binary "@$SourcePath" `
        -H "If-Match: *" `
        -o upload-response.txt `
        -w "%{http_code}" `
        $uri

    Write-Host "Upload $TargetPath : HTTP $response"

    if (Test-Path -LiteralPath 'upload-response.txt') {
        $body = Get-Content -LiteralPath 'upload-response.txt' -Raw
        if (-not [string]::IsNullOrWhiteSpace($body)) {
            Write-Host $body
        }
        Remove-Item -LiteralPath 'upload-response.txt' -Force
    }

    if ($response -notin @('200', '201', '204')) {
        throw "Falha ao publicar $TargetPath"
    }
}

Get-ChildItem -Path $deployDir -Recurse -File | ForEach-Object {
    $relativePath = $_.FullName.Substring($deployDir.Length + 1) -replace '\\', '/'
    Upload-File -SourcePath $_.FullName -TargetPath $relativePath
}

Write-Host 'Publicacao concluida com Kudu VFS.'
