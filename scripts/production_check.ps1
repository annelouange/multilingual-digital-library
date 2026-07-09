param(
    [string]$EnvironmentFile = '.env.production',
    [switch]$SkipBuild,
    [switch]$SkipHealth
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

function Read-EnvFile([string]$Path) {
    $values = @{}
    if (-not (Test-Path -LiteralPath $Path)) {
        return $values
    }
    foreach ($line in Get-Content -LiteralPath $Path) {
        $trimmed = $line.Trim()
        if ($trimmed -eq '' -or $trimmed.StartsWith('#') -or $trimmed -notmatch '=') {
            continue
        }
        $parts = $trimmed.Split('=', 2)
        $values[$parts[0].Trim()] = $parts[1].Trim().Trim('"')
    }
    return $values
}

function Get-Setting([hashtable]$EnvValues, [string]$Name, [string]$Default = '') {
    if ($EnvValues.ContainsKey($Name)) { return [string]$EnvValues[$Name] }
    $value = [Environment]::GetEnvironmentVariable($Name)
    if ($value) { return $value }
    return $Default
}

function Assert-NotPlaceholder([string]$Name, [string]$Value) {
    if ($Value -match 'replace-|your-|example\.com|change-me|password') {
        throw "$Name still looks like a placeholder: $Value"
    }
}

$envValues = Read-EnvFile $EnvironmentFile
$appEnv = Get-Setting $envValues 'APP_ENV' 'local'
$backendHealthUrl = Get-Setting $envValues 'MDL_BACKEND_HEALTH_URL' 'http://localhost/digital-library/backend/health'

if ($appEnv -eq 'production') {
    foreach ($name in @('APP_URL', 'APP_ALLOWED_ORIGINS', 'VITE_API_BASE_URL', 'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD')) {
        $value = Get-Setting $envValues $name
        if (-not $value) { throw "$name is required for production" }
        Assert-NotPlaceholder $name $value
    }
    if ((Get-Setting $envValues 'ENFORCE_HTTPS' 'false') -ne 'true') {
        throw 'ENFORCE_HTTPS must be true in production'
    }
}

$php = 'php'
if (Test-Path -LiteralPath 'C:\xampp\php\php.exe') {
    $php = 'C:\xampp\php\php.exe'
}

$phpFiles = @(
    'backend/index.php',
    'backend/config/app.php',
    'backend/config/cors.php',
    'backend/config/security.php',
    'backend/config/database.php',
    'backend/services/ActivityLogService.php',
    'backend/services/StorageService.php',
    'backend/services/TenantService.php',
    'backend/services/TranslationService.php',
    'backend/routes/api.php'
)
foreach ($file in $phpFiles) {
    & $php -l $file | Out-Host
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

if (-not $SkipBuild) {
    npm.cmd run build
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

if (-not $SkipHealth -and $backendHealthUrl) {
    try {
        $health = Invoke-RestMethod -Uri $backendHealthUrl -TimeoutSec 20
        if (-not $health.success) { throw 'Health response success=false' }
        Write-Host "[ready] Backend health: $($health.data.status)" -ForegroundColor Green
    } catch {
        throw "Backend health check failed at ${backendHealthUrl}: $($_.Exception.Message)"
    }
}

Write-Host '[pass] Production checks completed' -ForegroundColor Green
