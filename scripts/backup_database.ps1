param(
    [string]$Database = $(if ($env:DB_NAME) { $env:DB_NAME } else { 'multilingual_digital_library' }),
    [string]$OutputDirectory = $(if ($env:BACKUP_DIR) { $env:BACKUP_DIR } else { Join-Path $PSScriptRoot '..\backups' }),
    [int]$RetentionDays = $(if ($env:BACKUP_RETENTION_DAYS) { [int]$env:BACKUP_RETENTION_DAYS } else { 14 })
)

$ErrorActionPreference = 'Stop'
$dump = if ($env:MYSQLDUMP_BIN) { $env:MYSQLDUMP_BIN } else { 'C:\xampp\mysql\bin\mysqldump.exe' }
if (-not (Test-Path -LiteralPath $dump)) {
    throw "mysqldump was not found at $dump. Set MYSQLDUMP_BIN to the correct path."
}

$hostName = if ($env:DB_HOST) { $env:DB_HOST } else { '127.0.0.1' }
$port = if ($env:DB_PORT) { $env:DB_PORT } else { '3306' }
$user = if ($env:DB_USER) { $env:DB_USER } else { 'root' }
$password = if ($null -ne $env:DB_PASSWORD) { $env:DB_PASSWORD } else { '' }

$resolvedOutput = [System.IO.Path]::GetFullPath($OutputDirectory)
New-Item -ItemType Directory -Path $resolvedOutput -Force | Out-Null
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$target = Join-Path $resolvedOutput "$Database-$stamp.sql"

$args = @(
    "--user=$user",
    "--host=$hostName",
    "--port=$port",
    '--single-transaction',
    '--routines',
    '--triggers',
    '--events',
    '--databases',
    $Database
)
if ($password -ne '') {
    $args = @("--password=$password") + $args
}

& $dump @args | Set-Content -LiteralPath $target -Encoding utf8

if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $target) -or (Get-Item -LiteralPath $target).Length -eq 0) {
    throw 'Database backup failed.'
}

if ($RetentionDays -gt 0) {
    Get-ChildItem -LiteralPath $resolvedOutput -Filter "$Database-*.sql" |
        Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-$RetentionDays) } |
        Remove-Item -Force
}

Write-Output $target
