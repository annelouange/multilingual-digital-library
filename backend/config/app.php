<?php

$defaultOrigins = [
    'http://localhost:3000',
    'http://localhost:3001',
    'http://localhost:4173',
    'http://localhost:5173',
    'http://127.0.0.1:3000',
    'http://127.0.0.1:3001',
    'http://127.0.0.1:4173',
    'http://127.0.0.1:5173',
    'http://localhost:3040',
    'http://127.0.0.1:3040',
];

if (!function_exists('env_value')) {
    function env_value(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }
        return $_ENV[$key] ?? $default;
    }
}

if (!function_exists('env_bool')) {
    function env_bool(string $key, bool $default = false): bool
    {
        $value = env_value($key, null);
        if ($value === null || $value === '') {
            return $default;
        }
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}

if (!function_exists('env_int')) {
    function env_int(string $key, int $default): int
    {
        $value = env_value($key, null);
        if ($value === null || $value === '') {
            return $default;
        }
        return max(0, (int)$value);
    }
}

$configuredOrigins = env_value('APP_ALLOWED_ORIGINS', env_value('ALLOWED_ORIGINS', ''));
$allowedOrigins = array_values(array_filter(array_map('trim', explode(',', (string)$configuredOrigins))));
$transformerSttUrl = env_value('TRANSFORMER_STT_URL', env_value('STT_SERVICE_URL', 'http://127.0.0.1:5006/api/stt/transcribe'));

return [
    'name' => env_value('APP_NAME', 'MULTILINGUAL DIGITAL LIBRARY'),
    'env' => env_value('APP_ENV', 'local'),
    'app_url' => env_value('APP_URL', ''),
    'token_ttl_hours' => env_int('TOKEN_TTL_HOURS', 12),
    'allowed_origins' => $allowedOrigins ?: $defaultOrigins,
    'cors_allow_credentials' => env_bool('CORS_ALLOW_CREDENTIALS', true),
    'enforce_https' => env_bool('ENFORCE_HTTPS', false),
    'secure_headers' => env_bool('SECURE_HEADERS', true),
    'storage_disk' => env_value('STORAGE_DISK', 'local'),
    'storage_public_base_url' => env_value('STORAGE_PUBLIC_BASE_URL', ''),
    's3_endpoint' => env_value('S3_ENDPOINT', ''),
    's3_bucket' => env_value('S3_BUCKET', ''),
    's3_region' => env_value('S3_REGION', ''),
    's3_key' => env_value('S3_KEY', ''),
    's3_secret' => env_value('S3_SECRET', ''),
    'upload_dir' => env_value('UPLOAD_DIR', __DIR__ . '/../uploads'),
    'max_book_upload_bytes' => env_int('MAX_BOOK_UPLOAD_BYTES', 50 * 1024 * 1024),
    'max_lecture_note_upload_bytes' => env_int('MAX_LECTURE_NOTE_UPLOAD_BYTES', 25 * 1024 * 1024),
    'stt_service_url' => $transformerSttUrl,
    'transformer_stt_health_url' => env_value('TRANSFORMER_STT_HEALTH_URL', 'http://127.0.0.1:5006/health'),
    'transformer_stt_url' => $transformerSttUrl,
    'whisper_stt_health_url' => env_value('WHISPER_STT_HEALTH_URL', 'http://127.0.0.1:5001/health'),
    'whisper_stt_url' => env_value('WHISPER_STT_URL', 'http://127.0.0.1:5001/transcribe'),
    'speecht5_tts_health_url' => env_value('SPEECHT5_TTS_HEALTH_URL', 'http://127.0.0.1:5007/health'),
    'speecht5_tts_url' => env_value('SPEECHT5_TTS_URL', 'http://127.0.0.1:5007/synthesize'),
    'mms_tts_health_url' => env_value('MMS_TTS_HEALTH_URL', 'http://127.0.0.1:5009/health'),
    'mms_tts_url' => env_value('MMS_TTS_URL', 'http://127.0.0.1:5009/synthesize'),
    'model_api_fallback_1_url' => env_value('MODEL_API_FALLBACK_1_URL', ''),
    'model_api_fallback_1_key' => env_value('MODEL_API_FALLBACK_1_KEY', ''),
    'model_api_fallback_2_url' => env_value('MODEL_API_FALLBACK_2_URL', ''),
    'model_api_fallback_2_key' => env_value('MODEL_API_FALLBACK_2_KEY', ''),
    'nllb_translation_health_url' => env_value('NLLB_TRANSLATION_HEALTH_URL', 'http://127.0.0.1:5008/health'),
    'nllb_translation_url' => env_value('NLLB_TRANSLATION_URL', 'http://127.0.0.1:5008/translate'),
];
