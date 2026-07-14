$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$xamppRoot = if ($env:XAMPP_ROOT) { $env:XAMPP_ROOT } else { 'C:\xampp' }
$frontendHost = if ($env:FRONTEND_HOST) { $env:FRONTEND_HOST } else { '127.0.0.1' }
$frontendPort = if ($env:FRONTEND_PORT) { [int]$env:FRONTEND_PORT } else { 3040 }
$backendHealthUrl = if ($env:MDL_BACKEND_HEALTH_URL) { $env:MDL_BACKEND_HEALTH_URL } else { 'http://localhost/multilingual-library-startup/backend/health' }
$vite = Join-Path $root 'node_modules\.bin\vite.cmd'
$EnableWhisperFallbackStt = $true
$EnableTransformerStt = $true
$EnableSpeechT5Tts = $true
$EnableMmsTts = $true
$EnableSmartTranslation = $true
$flags = Join-Path $PSScriptRoot 'speech_service_flags.ps1'
if (Test-Path -LiteralPath $flags) {
    . $flags
}

function Initialize-AiCache {
    $preferredRoot = if (Test-Path -LiteralPath 'D:\') { 'D:\smart-digital-library-cache' } else { Join-Path $root 'models\cache' }
    $cacheRoot = if ($env:SMART_LIBRARY_AI_CACHE) { $env:SMART_LIBRARY_AI_CACHE } else { $preferredRoot }
    New-Item -ItemType Directory -Force -Path $cacheRoot | Out-Null

    $env:SMART_LIBRARY_AI_CACHE = $cacheRoot
    $env:HF_HOME = if ($env:HF_HOME) { $env:HF_HOME } else { Join-Path $cacheRoot 'huggingface' }
    $env:TRANSFORMERS_CACHE = if ($env:TRANSFORMERS_CACHE) { $env:TRANSFORMERS_CACHE } else { Join-Path $env:HF_HOME 'transformers' }
    $env:HF_DATASETS_CACHE = if ($env:HF_DATASETS_CACHE) { $env:HF_DATASETS_CACHE } else { Join-Path $env:HF_HOME 'datasets' }
    $env:TORCH_HOME = if ($env:TORCH_HOME) { $env:TORCH_HOME } else { Join-Path $cacheRoot 'torch' }
    New-Item -ItemType Directory -Force -Path $env:HF_HOME, $env:TRANSFORMERS_CACHE, $env:HF_DATASETS_CACHE, $env:TORCH_HOME | Out-Null

    Write-Host "[cache] AI models: $cacheRoot" -ForegroundColor DarkCyan
}

function Get-PortListener([int]$Port) {
    $pattern = "^\s*TCP\s+\S+:$Port\s+\S+\s+LISTENING\s+\d+"
    return netstat -ano -p tcp | Select-String -Pattern $pattern | Select-Object -First 1
}

function Wait-ForPort([string]$Name, [int]$Port, [int]$TimeoutSeconds) {
    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    do {
        if (Get-PortListener $Port) {
            Write-Host "[ready] $Name on port $Port" -ForegroundColor Green
            return
        }
        Start-Sleep -Milliseconds 500
    } while ((Get-Date) -lt $deadline)

    throw "$Name did not start on port $Port within $TimeoutSeconds seconds."
}

function Start-XamppService([string]$Name, [int]$Port, [string]$StarterName, [int]$TimeoutSeconds = 60) {
    if (Get-PortListener $Port) {
        Write-Host "[ready] $Name already running on port $Port" -ForegroundColor Green
        return
    }

    $starter = Join-Path $xamppRoot $StarterName
    if (-not (Test-Path -LiteralPath $starter)) {
        throw "$Name starter was not found at $starter."
    }

    Write-Host "[start] $Name" -ForegroundColor Cyan
    Start-Process -FilePath 'cmd.exe' `
        -ArgumentList @('/d', '/s', '/c', "`"$starter`"") `
        -WorkingDirectory $xamppRoot `
        -WindowStyle Hidden
    Wait-ForPort $Name $Port $TimeoutSeconds
}

function Invoke-JsonPost([string]$Name, [string]$Url, [hashtable]$Payload, [int]$TimeoutSeconds = 60) {
    $body = $Payload | ConvertTo-Json -Depth 6
    try {
        return Invoke-RestMethod -Uri $Url -Method Post -ContentType 'application/json; charset=utf-8' -Body $body -TimeoutSec $TimeoutSeconds
    } catch {
        throw "$Name failed: $($_.Exception.Message)"
    }
}

function Warmup-AiServices {
    if ($EnableSmartTranslation) {
        Write-Host '[warmup] English to French translation model' -ForegroundColor Cyan
        $enFr = Invoke-JsonPost 'English to French translation warmup' 'http://127.0.0.1:8001/translate' @{
            text = 'Students can read and listen to library pages.'
            source_language = 'en'
            target_language = 'fr'
            quality_mode = 'fast'
        } 120
        if (-not $enFr.success -or [string]::IsNullOrWhiteSpace($enFr.translated_text)) {
            throw 'English to French translation warmup returned no translated text.'
        }
        Write-Host '[ready] English to French translation model warmed' -ForegroundColor Green

        Write-Host '[warmup] French to English translation model' -ForegroundColor Cyan
        $frEn = Invoke-JsonPost 'French to English translation warmup' 'http://127.0.0.1:8001/translate' @{
            text = 'Les etudiants peuvent lire et ecouter les pages de la bibliotheque.'
            source_language = 'fr'
            target_language = 'en'
            quality_mode = 'fast'
        } 120
        if (-not $frEn.success -or [string]::IsNullOrWhiteSpace($frEn.translated_text)) {
            throw 'French to English translation warmup returned no translated text.'
        }
        Write-Host '[ready] French to English translation model warmed' -ForegroundColor Green
    }

    if ($EnableMmsTts) {
        Write-Host '[warmup] French MMS narration model' -ForegroundColor Cyan
        $mms = Invoke-JsonPost 'French MMS narration warmup' 'http://127.0.0.1:5009/synthesize' @{
            text = 'La narration francaise est prete.'
            language = 'fr'
        } 120
        if (-not $mms.success -or [string]::IsNullOrWhiteSpace($mms.audio_base64)) {
            throw 'French MMS narration warmup returned no audio.'
        }
        Write-Host '[ready] French MMS narration model warmed' -ForegroundColor Green
    }
}

Set-Location $root

if (-not (Test-Path -LiteralPath $vite)) {
    throw 'Frontend dependencies are missing. Run npm install, then npm run dev.'
}

Write-Host ''
Write-Host 'MULTILINGUAL DIGITAL LIBRARY development system' -ForegroundColor Cyan
Write-Host '---------------------------------------'
Initialize-AiCache

Start-XamppService 'Apache' 80 'apache_start.bat' 120
Start-XamppService 'MySQL' 3306 'mysql_start.bat'

Write-Host '[start] Speech services' -ForegroundColor Cyan
& (Join-Path $PSScriptRoot 'start_ai_services.ps1') | Format-Table -AutoSize
if ($EnableWhisperFallbackStt) { Wait-ForPort 'Whisper fallback STT' 5001 120 } else { Write-Host '[skip] Whisper fallback STT disabled' -ForegroundColor Yellow }
if ($EnableTransformerStt) { Wait-ForPort 'Wav2Vec2 Transformer STT' 5006 120 } else { Write-Host '[skip] Wav2Vec2 Transformer STT disabled' -ForegroundColor Yellow }
if ($EnableSpeechT5Tts) { Wait-ForPort 'SpeechT5 TTS service' 5007 180 } else { Write-Host '[skip] SpeechT5 TTS disabled' -ForegroundColor Yellow }
if ($EnableMmsTts) { Wait-ForPort 'MMS French TTS service' 5009 180 } else { Write-Host '[skip] MMS French TTS disabled' -ForegroundColor Yellow }
if ($EnableSmartTranslation) { Wait-ForPort 'Smart translation service' 8001 180 } else { Write-Host '[skip] Smart translation service disabled' -ForegroundColor Yellow }
Warmup-AiServices

$backend = Invoke-RestMethod -Uri $backendHealthUrl -TimeoutSec 10
$backendStatus = if ($backend.data -and $backend.data.status) { $backend.data.status } else { '' }
if ($backend.data.database -ne 'connected' -or -not $backend.data.uploads_writable) {
    throw 'The PHP backend is not healthy. Check Apache and MySQL.'
}
Write-Host '[ready] PHP backend, database, and uploads' -ForegroundColor Green

if ($EnableSpeechT5Tts) {
    $ttsReady = $backend.data.tts -and (
        ($backend.data.tts.service -and $backend.data.tts.service.success) -or
        ($backend.data.tts.primary -and $backend.data.tts.primary.success)
    )
    if (-not $ttsReady) {
        throw 'SpeechT5 is not preloaded according to backend health. Run scripts/start_ai_services.ps1 and check the SpeechT5 output.'
    }
    Write-Host '[ready] SpeechT5 TTS preload verified by backend health' -ForegroundColor Green
} else {
    Write-Host '[skip] SpeechT5 TTS preload check disabled' -ForegroundColor Yellow
}

Write-Host ''
Write-Host "Application: http://${frontendHost}:${frontendPort}" -ForegroundColor Yellow
Write-Host 'Press Ctrl+C to stop the Vite development server.'
Write-Host ''

$frontendUrl = "http://${frontendHost}:${frontendPort}"
if (Get-PortListener $frontendPort) {
    try {
        $frontendResponse = Invoke-WebRequest -Uri "${frontendUrl}/login" -UseBasicParsing -TimeoutSec 5
        if ($frontendResponse.Content -notmatch 'id="root"|/src/main.jsx|Rwanda Library|Digital Library') {
            throw 'The response does not look like the Rwanda Library frontend.'
        }
        Write-Host "[ready] Vite frontend already running on ${frontendUrl}" -ForegroundColor Green
        return
    } catch {
        throw "Port ${frontendPort} is already in use, but the Rwanda Library frontend did not respond at /login. Set FRONTEND_PORT to an open port or stop the conflicting process."
    }
}

& $vite --host $frontendHost --port $frontendPort --strictPort
