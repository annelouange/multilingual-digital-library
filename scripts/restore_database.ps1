param(
    [Parameter(Mandatory = $true)]
    [string]$BackupFile,
    [switch]$ConfirmRestore
)

$ErrorActionPreference = 'Stop'
if (-not $ConfirmRestore) {
    throw 'Restore is destructive to current database state. Re-run with -ConfirmRestore after creating a fresh backup.'
}

$mysql = 'C:\xampp\mysql\bin\mysql.exe'
$resolvedBackup = [System.IO.Path]::GetFullPath($BackupFile)
if (-not (Test-Path -LiteralPath $mysql)) {
    throw "mysql was not found at $mysql"
}
if (-not (Test-Path -LiteralPath $resolvedBackup)) {
    throw "Backup file not found: $resolvedBackup"
}

Get-Content -LiteralPath $resolvedBackup -Raw | & $mysql --user=root --host=127.0.0.1 --port=3306
if ($LASTEXITCODE -ne 0) {
    throw 'Database restore failed.'
}

Write-Output "Restored $resolvedBackup"
