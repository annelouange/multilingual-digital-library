param(
    [Parameter(Mandatory = $true)]
    [string]$InputFile,

    [ValidateSet('fr-en', 'en-fr')]
    [string]$Direction = 'fr-en',

    [string]$OutputDir = '',
    [int]$TrainBatchSize = 16,
    [int]$EvalBatchSize = 16,
    [double]$Epochs = 3,
    [int]$MaxRows = 0,
    [double]$MinFreeGb = 8,
    [switch]$DryRun
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$script = Join-Path $PSScriptRoot 'train_opus_translation.py'

if (-not (Get-Command accelerate -ErrorAction SilentlyContinue)) {
    throw 'Hugging Face Accelerate is not installed or not on PATH. Install backend requirements, then run: accelerate config'
}

$arguments = @(
    'launch',
    $script,
    '--input', $InputFile,
    '--direction', $Direction,
    '--train-batch-size', $TrainBatchSize,
    '--eval-batch-size', $EvalBatchSize,
    '--epochs', $Epochs,
    '--min-free-gb', $MinFreeGb
)

if ($OutputDir) {
    $arguments += @('--output-dir', $OutputDir)
}
if ($MaxRows -gt 0) {
    $arguments += @('--max-rows', $MaxRows)
}
if ($DryRun) {
    $arguments += '--dry-run'
}

Set-Location $root
& accelerate @arguments
