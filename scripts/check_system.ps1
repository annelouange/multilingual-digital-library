$ErrorActionPreference = 'Stop'
$EnableWhisperFallbackStt = $true
$EnableTransformerStt = $true
$EnableSpeechT5Tts = $true
$flags = Join-Path $PSScriptRoot 'speech_service_flags.ps1'
if (Test-Path -LiteralPath $flags) {
    . $flags
}
$frontendHost = if ($env:FRONTEND_HOST) { $env:FRONTEND_HOST } else { '127.0.0.1' }
$frontendPort = if ($env:FRONTEND_PORT) { [int]$env:FRONTEND_PORT } else { 3000 }
$backendHealthUrl = if ($env:MDL_BACKEND_HEALTH_URL) { $env:MDL_BACKEND_HEALTH_URL } else { 'http://localhost/digital-library/backend/health' }

$checks = @(
    @{ Name = 'Frontend'; Url = "http://${frontendHost}:${frontendPort}/login"; Enabled = $true },
    @{ Name = 'Backend'; Url = $backendHealthUrl; Enabled = $true },
    @{ Name = 'Open-vocabulary STT'; Url = 'http://127.0.0.1:5001/health'; Enabled = $EnableWhisperFallbackStt },
    @{ Name = 'Transformer STT'; Url = 'http://127.0.0.1:5006/health'; Enabled = $EnableTransformerStt },
    @{ Name = 'SpeechT5 TTS service'; Url = 'http://127.0.0.1:5007/health'; Enabled = $EnableSpeechT5Tts }
)

function Test-PortListener([int]$Port) {
    $pattern = "^\s*TCP\s+\S+:$Port\s+\S+\s+LISTENING\s+\d+"
    return [bool](netstat -ano -p tcp | Select-String -Pattern $pattern | Select-Object -First 1)
}

foreach ($check in $checks) {
    if (-not $check.Enabled) {
        [pscustomobject]@{ Service = $check.Name; Status = 'SKIP'; HttpStatus = ''; Url = 'disabled in scripts/speech_service_flags.ps1' }
        continue
    }
    try {
        $response = Invoke-WebRequest -Uri $check.Url -UseBasicParsing -TimeoutSec 5
        [pscustomobject]@{ Service = $check.Name; Status = 'PASS'; HttpStatus = $response.StatusCode; Url = $check.Url }
    } catch {
        [pscustomobject]@{ Service = $check.Name; Status = 'FAIL'; HttpStatus = ''; Url = $check.Url }
    }
}

try {
    $ttsReady = $false
    if ($EnableSpeechT5Tts) {
        $backendHealth = Invoke-RestMethod -Uri $backendHealthUrl -TimeoutSec 30
        $ttsReady = $backendHealth.data.tts -and (
            ($backendHealth.data.tts.service -and $backendHealth.data.tts.service.success) -or
            ($backendHealth.data.tts.primary -and $backendHealth.data.tts.primary.success)
        )
    }
    [pscustomobject]@{
        Service = 'SpeechT5 TTS preload'
        Status = $(if (-not $EnableSpeechT5Tts) { 'SKIP' } elseif ($ttsReady) { 'PASS' } else { 'FAIL' })
        HttpStatus = ''
        Url = $(if ($EnableSpeechT5Tts) { 'backend /health data.tts.primary' } else { 'disabled in scripts/speech_service_flags.ps1' })
    }
} catch {
    [pscustomobject]@{ Service = 'SpeechT5 TTS preload'; Status = 'FAIL'; HttpStatus = ''; Url = 'backend /health data.tts.primary' }
}

$ports = @(
    @{ Port = 80; Enabled = $true },
    @{ Port = $frontendPort; Enabled = $true },
    @{ Port = 3306; Enabled = $true },
    @{ Port = 5001; Enabled = $EnableWhisperFallbackStt },
    @{ Port = 5006; Enabled = $EnableTransformerStt },
    @{ Port = 5007; Enabled = $EnableSpeechT5Tts }
)
foreach ($port in $ports) {
    if (-not $port.Enabled) {
        [pscustomobject]@{ Service = "Port $($port.Port)"; Status = 'SKIP'; HttpStatus = ''; Url = 'disabled in scripts/speech_service_flags.ps1' }
        continue
    }
    $listening = Test-PortListener $port.Port
    [pscustomobject]@{ Service = "Port $($port.Port)"; Status = $(if ($listening) { 'PASS' } else { 'FAIL' }); HttpStatus = ''; Url = '' }
}
