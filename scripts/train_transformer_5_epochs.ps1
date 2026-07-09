$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$python = if ($env:PYTHON_EXE) { $env:PYTHON_EXE } else { 'C:\Users\Anne Louange\AppData\Local\Programs\Python\Python311\python.exe' }
$log = Join-Path $root 'transformer_finetune_5_epochs.log'

Set-Location $root

Write-Host 'Starting real 5-epoch Wav2Vec2-CTC head fine-tuning.' -ForegroundColor Cyan
Write-Host 'This is not training from scratch; it fine-tunes only the CTC output head.' -ForegroundColor Cyan
Write-Host 'On CPU, this can take many hours. Keep the computer awake until it finishes.' -ForegroundColor Yellow

& $python 'scripts\transformer_train_complete.py' `
    --base-model 'transformer_model' `
    --output-dir 'transformer_model_candidate_5epochs' `
    --promote-dir 'transformer_model' `
    --metrics-path 'models\stt\transformer_metrics_5epochs.json' `
    --max-train-samples 640 `
    --max-eval-samples 40 `
    --max-test-samples 60 `
    --max-audio-seconds 8 `
    --batch-size 1 `
    --learning-rate 1e-5 `
    --epochs 5 `
    --time-budget-minutes 1500 `
    --train-mode head `
    --promote `
    --promote-min-word-accuracy 75 `
    --preserve-metrics-unless-promoted `
    2>&1 | Tee-Object -FilePath $log

if ($LASTEXITCODE -ne 0) {
    throw "5-epoch fine-tuning failed. Check $log"
}

Write-Host "Done. Log: $log" -ForegroundColor Green
Write-Host 'Metrics: models\stt\transformer_metrics_5epochs.json' -ForegroundColor Green
