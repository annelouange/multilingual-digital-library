$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$python = if ($env:PYTHON_BIN) { $env:PYTHON_BIN } elseif (Get-Command python -ErrorAction SilentlyContinue) { (Get-Command python).Source } else { 'C:\Users\Anne Louange\AppData\Local\Programs\Python\Python311\python.exe' }
$logDirectory = if ($env:LOG_DIR) { $env:LOG_DIR } else { Join-Path $root 'logs' }
$tmpDirectory = if ($env:TMP_DIR) { $env:TMP_DIR } else { Join-Path $root 'tmp' }
$EnableWhisperFallbackStt = $true
$EnableTransformerStt = $true
$EnableSpeechT5Tts = $true
$EnableMmsTts = $true
$EnableNllbTranslation = $true
$EnableSmartTranslation = $true
$flags = Join-Path $PSScriptRoot 'speech_service_flags.ps1'
if (Test-Path -LiteralPath $flags) {
    . $flags
}
New-Item -ItemType Directory -Force -Path $logDirectory | Out-Null
New-Item -ItemType Directory -Force -Path $tmpDirectory | Out-Null

function Get-PortListener([int]$Port) {
    $pattern = "^\s*TCP\s+\S+:$Port\s+\S+\s+LISTENING\s+\d+"
    return netstat -ano -p tcp | Select-String -Pattern $pattern | Select-Object -First 1
}

$services = @(
    @{
        Name = 'Open-vocabulary STT'
        Enabled = $EnableWhisperFallbackStt
        Port = 5001
        Script = 'scripts\whisper_stt_service.py'
        Output = 'whisper-stt.out.log'
        Error = 'whisper-stt.err.log'
    },
    @{
        Name = 'Transformer STT'
        Enabled = $EnableTransformerStt
        Port = 5006
        Script = 'scripts\transformer_speech_service.py'
        Output = 'transformer-stt.out.log'
        Error = 'transformer-stt.err.log'
    },
    @{
        Name = 'MMS Multilingual TTS service'
        Enabled = $EnableMmsTts
        Port = 5009
        Script = 'scripts\mms_tts_service.py'
        Output = 'mms-tts.out.log'
        Error = 'mms-tts.err.log'
    },
    @{
        Name = 'SpeechT5 TTS service'
        Enabled = $EnableSpeechT5Tts
        Port = 5007
        Script = 'scripts\speecht5_tts_service.py'
        Output = 'speecht5-tts.out.log'
        Error = 'speecht5-tts.err.log'
    },
    @{
        Name = 'Smart Translation AI service'
        Enabled = $EnableSmartTranslation
        Port = 8001
        Script = '-m uvicorn app.main:app --host 127.0.0.1 --port 8001'
        WorkingDirectory = 'ai-service'
        Output = 'smart-translation.out.log'
        Error = 'smart-translation.err.log'
    },
    @{
        Name = 'Legacy NLLB Translation'
        Enabled = $EnableNllbTranslation
        Port = 5008
        Script = 'scripts\nllb_translation_service.py'
        Output = 'nllb-translation.out.log'
        Error = 'nllb-translation.err.log'
    }
)

foreach ($service in $services) {
    if (-not $service.Enabled) {
        [pscustomobject]@{ Service = $service.Name; Status = 'DISABLED'; ProcessId = ''; Port = $service.Port }
        continue
    }
    $listener = Get-PortListener $service.Port
    if (!$listener) {
        $serviceWorkingDirectory = if ($service.WorkingDirectory) { Join-Path $root $service.WorkingDirectory } else { $root }
        $process = Start-Process `
            -FilePath $python `
            -ArgumentList $service.Script `
            -WorkingDirectory $serviceWorkingDirectory `
            -WindowStyle Hidden `
            -RedirectStandardOutput (Join-Path $logDirectory $service.Output) `
            -RedirectStandardError (Join-Path $logDirectory $service.Error) `
            -PassThru
        [pscustomobject]@{ Service = $service.Name; Status = 'STARTED'; ProcessId = $process.Id; Port = $service.Port }
    } else {
        [pscustomobject]@{ Service = $service.Name; Status = 'RUNNING'; ProcessId = $listener.OwningProcess; Port = $service.Port }
    }
}

if (-not $EnableSpeechT5Tts) {
    [pscustomobject]@{
        Service = 'SpeechT5 TTS'
        Status = 'PRELOAD_SKIPPED_DISABLED'
        ProcessId = ''
        Port = 5007
        PreloadBytes = ''
    }
    return
}

$preloadFile = Join-Path $tmpDirectory 'speecht5-preload.wav'
$ttsServiceHealth = $null
$ttsHealthUrl = if ($env:SPEECHT5_TTS_HEALTH_URL) { $env:SPEECHT5_TTS_HEALTH_URL } else { 'http://127.0.0.1:5007/health' }
try {
    $ttsServiceHealth = Invoke-RestMethod -Uri $ttsHealthUrl -TimeoutSec 10
} catch {
    $ttsServiceHealth = $null
}
if ($ttsServiceHealth -and $ttsServiceHealth.success) {
    [pscustomobject]@{
        Service = 'SpeechT5 TTS'
        Status = 'SERVICE_READY'
        ProcessId = ''
        Port = 5007
        PreloadBytes = ''
    }
    return
}

$ttsOutput = & $python 'scripts\speecht5_synthesize.py' --health --preload-file $preloadFile 2>&1
if ($LASTEXITCODE -eq 0) {
    $ttsHealth = $null
    foreach ($line in $ttsOutput) {
        try {
            $candidate = $line | ConvertFrom-Json
            if ($null -ne $candidate.success) {
                $ttsHealth = $candidate
            }
        } catch {
        }
    }
    if ($ttsHealth.success -and $ttsHealth.preload.ready) {
        [pscustomobject]@{
            Service = 'SpeechT5 TTS'
            Status = 'PRELOADED'
            ProcessId = ''
            Port = ''
            PreloadBytes = $ttsHealth.preload.bytes
        }
        return
    }
}

$ttsOutput = & $python 'scripts\speecht5_synthesize.py' --preload --preload-file $preloadFile 2>&1
if ($LASTEXITCODE -ne 0) {
    throw "SpeechT5 preload failed: $($ttsOutput -join [Environment]::NewLine)"
}

$ttsJson = $null
foreach ($line in $ttsOutput) {
    try {
        $candidate = $line | ConvertFrom-Json
        if ($null -ne $candidate.success) {
            $ttsJson = $candidate
        }
    } catch {
    }
}
if (-not $ttsJson.success -or -not $ttsJson.preload.ready) {
    throw "SpeechT5 preload did not produce a ready WAV: $($ttsOutput -join [Environment]::NewLine)"
}

[pscustomobject]@{
    Service = 'SpeechT5 TTS'
    Status = 'PRELOADED'
    ProcessId = ''
    Port = ''
    PreloadBytes = $ttsJson.preload.bytes
}
