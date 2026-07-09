param(
    [string]$HealthUrl = $(if ($env:MDL_BACKEND_HEALTH_URL) { $env:MDL_BACKEND_HEALTH_URL } else { 'http://localhost/digital-library/backend/health' }),
    [int]$TimeoutSeconds = 20,
    [string]$LogPath = $(if ($env:MONITOR_LOG_PATH) { $env:MONITOR_LOG_PATH } else { Join-Path $PSScriptRoot '..\logs\health-monitor.log' })
)

$ErrorActionPreference = 'Stop'
$logDirectory = Split-Path -Parent $LogPath
if ($logDirectory) {
    New-Item -ItemType Directory -Force -Path $logDirectory | Out-Null
}

$timestamp = (Get-Date).ToString('s')
try {
    $response = Invoke-RestMethod -Uri $HealthUrl -TimeoutSec $TimeoutSeconds
    $status = if ($response.data -and $response.data.status) { $response.data.status } else { 'unknown' }
    if (-not $response.success -or $status -ne 'ok') {
        throw "Health status is $status"
    }
    "$timestamp PASS $HealthUrl status=$status" | Add-Content -LiteralPath $LogPath
    Write-Host "[pass] $HealthUrl status=$status" -ForegroundColor Green
} catch {
    "$timestamp FAIL $HealthUrl $($_.Exception.Message)" | Add-Content -LiteralPath $LogPath
    Write-Error "Health check failed: $($_.Exception.Message)"
    exit 1
}
