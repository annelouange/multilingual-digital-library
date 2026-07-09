<?php

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$path = '/' . trim(preg_replace('#^' . preg_quote($scriptDir, '#') . '#', '', $uriPath), '/');
$path = $path === '/' ? '/health' : $path;
if (str_starts_with($path, '/api/')) {
    $path = substr($path, 4);
}

function pdo(): PDO
{
    return Database::connection();
}

function body(): array
{
    return Request::input();
}

function app_config(): array
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/../config/app.php';
    }
    return $config;
}

function configured_url(string $key, string $default): string
{
    $config = app_config();
    return (string)($config[$key] ?? $default);
}

function configured_int(string $key, int $default): int
{
    $config = app_config();
    return max(0, (int)($config[$key] ?? $default));
}

function format_bytes(int $bytes): string
{
    $megabytes = $bytes / 1024 / 1024;
    return rtrim(rtrim(number_format($megabytes, 2, '.', ''), '0'), '.') . ' MB';
}

function service_json_get(string $url, int $timeout = 3): ?array
{
    if (!function_exists('curl_init')) {
        return null;
    }
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
    ]);
    $raw = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if ($raw === false || $status < 200 || $status >= 300) {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function service_upload_audio(string $url, array $file, int $timeout = 60, array $fields = []): ?array
{
    if (!function_exists('curl_init') || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return null;
    }
    $field = str_contains($url, '/api/') ? 'file' : 'audio';
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_POSTFIELDS => array_merge([
            $field => curl_file_create($file['tmp_name'], $file['type'] ?? 'audio/webm', $file['name'] ?? 'voice.webm'),
        ], $fields),
    ]);
    $raw = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if ($raw === false || $status < 200 || $status >= 300) {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function service_json_post(string $url, array $payload, int $timeout = 30): ?array
{
    if (!function_exists('curl_init')) {
        return null;
    }
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $raw = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if ($raw === false || $status < 200 || $status >= 300) {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function tts_preload_file(): string
{
    $directory = __DIR__ . '/../../tmp';
    if (!is_dir($directory)) {
        @mkdir($directory, 0775, true);
    }
    return $directory . '/speecht5-preload.wav';
}

function speecht5_health(bool $refresh = false): array
{
    static $cached = null;
    if (!$refresh && is_array($cached)) {
        return $cached;
    }

    $script = realpath(__DIR__ . '/../../scripts/speecht5_synthesize.py');
    if (!$script) {
        $cached = [
            'success' => false,
            'status' => 'script_missing',
            'provider' => 'speecht5',
            'error' => 'scripts/speecht5_synthesize.py was not found',
            'preload' => ['file' => tts_preload_file(), 'ready' => false, 'bytes' => 0],
        ];
        return $cached;
    }

    $command = 'python ' . escapeshellarg($script) .
        ' --health --preload-file ' . escapeshellarg(tts_preload_file());
    $result = run_json_command($command);
    if (!is_array($result)) {
        $result = [
            'success' => false,
            'status' => 'health_check_failed',
            'provider' => 'speecht5',
            'error' => 'SpeechT5 health check did not return JSON',
            'preload' => ['file' => tts_preload_file(), 'ready' => false, 'bytes' => 0],
        ];
    }

    $result['success'] = (bool)($result['success'] ?? false);
    $result['status'] = (string)($result['status'] ?? ($result['success'] ? 'ready' : 'not_ready'));
    $cached = $result;
    return $cached;
}

function warm_speecht5_model(): ?array
{
    $health = speecht5_health();
    if ($health['success'] ?? false) {
        return $health;
    }

    $script = realpath(__DIR__ . '/../../scripts/speecht5_synthesize.py');
    if (!$script) {
        return null;
    }

    $command = 'python ' . escapeshellarg($script) .
        ' --preload --preload-file ' . escapeshellarg(tts_preload_file());
    $result = run_json_command($command);
    if (!($result['success'] ?? false)) {
        return null;
    }

    return speecht5_health(true);
}

function tts_health(): array
{
    $speechT5 = speecht5_health();
    $serviceHealth = service_json_get(configured_url('speecht5_tts_health_url', 'http://127.0.0.1:5007/health'), 2);
    $fallbackScript = realpath(__DIR__ . '/../../scripts/gtts_synthesize.py');
    $fallbackReady = (bool)$fallbackScript;

    return [
        'status' => (($serviceHealth['success'] ?? false) || ($speechT5['success'] ?? false)) ? 'ready' : ($fallbackReady ? 'fallback_only' : 'not_ready'),
        'service' => $serviceHealth ?: [
            'success' => false,
            'status' => 'service_offline',
            'provider' => 'speecht5_service',
            'url' => preg_replace('#/[^/]*$#', '', configured_url('speecht5_tts_health_url', 'http://127.0.0.1:5007/health')),
        ],
        'primary' => $speechT5,
        'fallback' => [
            'provider' => 'gtts',
            'status' => $fallbackReady ? 'available' : 'script_missing',
            'script' => $fallbackScript ?: null,
        ],
    ];
}

function ai_model_status(): array
{
    $transformerHealth = service_json_get(configured_url('transformer_stt_health_url', 'http://127.0.0.1:5006/health'), 2);
    $transformerMetricsPath = __DIR__ . '/../../models/stt/transformer_metrics.json';
    $transformerMetrics = is_file($transformerMetricsPath) ? json_decode(file_get_contents($transformerMetricsPath), true) : null;
    $ttsHealth = tts_health();

    return [
        [
            'name' => 'Transformer English Speech-to-Text (PRIMARY)',
            'task' => 'speech_to_text_search_primary',
            'source' => $transformerMetrics['model_path'] ?? ($transformerHealth['model_path'] ?? 'facebook/wav2vec2-base-960h'),
            'status' => $transformerHealth ? (($transformerMetrics['production_ready'] ?? true) ? 'ready' : 'training') : 'service_offline',
            'accuracy_note' => $transformerMetrics
                ? 'Held-out word accuracy: ' . round((float)$transformerMetrics['overall_word_accuracy_percent'], 2) .
                    '%; sentence exact accuracy: ' . round((float)$transformerMetrics['overall_sentence_exact_accuracy_percent'], 2) . '%.'
                : 'Running Wav2Vec2 Transformer CTC with pretrained facebook/wav2vec2-base-960h weights. No local fine-tuned Transformer metrics file is present yet.',
            'verified_metrics' => [
                'dataset' => $transformerMetrics['dataset'] ?? 'LibriSpeech real audio smoke tests',
                'epochs' => $transformerMetrics['epochs_completed'] ?? 0,
                'word_accuracy_percent' => $transformerMetrics['overall_word_accuracy_percent'] ?? null,
                'sentence_exact_accuracy_percent' => $transformerMetrics['overall_sentence_exact_accuracy_percent'] ?? null,
                'production_ready' => (bool)($transformerMetrics['production_ready'] ?? false),
            ],
        ],
        [
            'name' => 'Whisper multilingual fallback STT',
            'task' => 'speech_to_text_search',
            'source' => 'OpenAI Whisper multilingual checkpoint',
            'status' => service_json_get(configured_url('whisper_stt_health_url', 'http://127.0.0.1:5001/health'), 2) ? 'ready' : 'service_offline',
            'accuracy_note' => 'Production fallback for multilingual book titles, topics, and navigation commands. Use tiny/base/small multilingual checkpoints for French, Kinyarwanda, and Kiswahili; low-confidence transcripts should stay reviewable.',
        ],
        [
            'name' => 'Text-to-Speech',
            'task' => 'text_to_speech',
            'source' => 'Microsoft SpeechT5 English TTS plus browser multilingual speech synthesis and gTTS fallback',
            'status' => $ttsHealth['status'],
            'accuracy_note' => (($ttsHealth['service']['success'] ?? false) || ($ttsHealth['primary']['success'] ?? false))
                ? 'English narration is generated locally with SpeechT5; French, Kinyarwanda, and Kiswahili guidance uses translated text plus the browser voice layer until dedicated multilingual TTS GPU services are provisioned.'
                : 'SpeechT5 is not preloaded. Run scripts/start_ai_services.ps1 or scripts/speecht5_synthesize.py --preload before a live demo.',
            'verified_metrics' => [
                'provider' => 'speecht5',
                'model' => $ttsHealth['primary']['model'] ?? 'microsoft/speecht5_tts',
                'service_ready' => (bool)($ttsHealth['service']['success'] ?? false),
                'preload_ready' => (bool)($ttsHealth['primary']['preload']['ready'] ?? false),
                'preload_bytes' => (int)($ttsHealth['primary']['preload']['bytes'] ?? 0),
                'fallback_available' => $ttsHealth['fallback']['status'] === 'available',
            ],
        ],
    ];
}

function local_open_vocabulary_transcribe(array $file, string $language = 'en'): ?array
{
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return null;
    }

    $language = strtolower(trim($language)) ?: 'en';
    $startedAt = microtime(true);
    $transformerResult = null;
    if ($language === 'en') {
        $transformerResult = service_upload_audio(configured_url('transformer_stt_url', 'http://127.0.0.1:5006/api/stt/transcribe'), $file, 18, ['language' => $language]);
    }
    if ($transformerResult) {
        $transformerResult['provider'] = 'local_wav2vec2';
        $transformerResult['processing_seconds'] = round(microtime(true) - $startedAt, 3);
        return $transformerResult;
    }

    $whisperResult = service_upload_audio(configured_url('whisper_stt_url', 'http://127.0.0.1:5001/transcribe'), $file, 35, ['language' => $language]);
    if ($whisperResult) {
        $whisperResult['provider'] = 'local_whisper_fallback';
        $whisperResult['processing_seconds'] = round(microtime(true) - $startedAt, 3);
    }
    return $whisperResult;
}

function admin_roles(): array
{
    return ['LIBRARIAN_ADMIN'];
}

function enforce_rate_limit(string $bucket, int $limit, int $windowSeconds): void
{
    $identity = ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' . $bucket;
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mdl-library-rate-limits';
    if (!is_dir($directory)) {
        @mkdir($directory, 0775, true);
    }
    $path = $directory . DIRECTORY_SEPARATOR . hash('sha256', $identity) . '.json';
    $now = time();
    $state = ['started_at' => $now, 'count' => 0];
    $handle = @fopen($path, 'c+');
    if (!$handle) {
        return;
    }
    try {
        if (!flock($handle, LOCK_EX)) {
            return;
        }
        $raw = stream_get_contents($handle);
        $stored = $raw ? json_decode($raw, true) : null;
        if (is_array($stored) && ($now - (int)($stored['started_at'] ?? 0)) < $windowSeconds) {
            $state = $stored;
        }
        $state['count'] = (int)($state['count'] ?? 0) + 1;
        if ($state['count'] > $limit) {
            $retryAfter = max(1, $windowSeconds - ($now - (int)$state['started_at']));
            header('Retry-After: ' . $retryAfter);
            Response::error('Too many requests. Please wait before trying again.', 429);
        }
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($state));
        fflush($handle);
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }
}

function validate_new_password(string $password): void
{
    if (strlen($password) < 10) {
        Response::error('Password must be at least 10 characters', 422);
    }
    if (!preg_match('/[a-z]/', $password)
        || !preg_match('/[A-Z]/', $password)
        || !preg_match('/\d/', $password)
        || !preg_match('/[^A-Za-z0-9]/', $password)) {
        Response::error('Password must include uppercase, lowercase, number, and symbol characters', 422);
    }
}

function parse_catalog_query(string $query): array
{
    $normalized = trim(preg_replace('/\s+/', ' ', mb_strtolower($query)));
    $availability = '';
    if (preg_match('/\b(available|in stock|can borrow)\b/u', $normalized)) {
        $availability = 'available';
        $normalized = preg_replace('/\b(available|in stock|can borrow)\b/u', ' ', $normalized);
    } elseif (preg_match('/\b(unavailable|out of stock)\b/u', $normalized)) {
        $availability = 'unavailable';
        $normalized = preg_replace('/\b(unavailable|out of stock)\b/u', ' ', $normalized);
    }

    $author = '';
    if (preg_match('/\b(?:by|author)\s+([a-z][a-z .\'-]{2,})$/iu', $normalized, $match)) {
        $author = trim($match[1]);
        $normalized = trim(substr($normalized, 0, (int)strpos($normalized, $match[0])));
    }

    $stopPhrases = [
        'please', 'find', 'show', 'search', 'look for', 'give me', 'i need', 'books', 'book',
        'resources', 'resource', 'about', 'on', 'for', 'students', 'student', 'in the library',
    ];
    foreach ($stopPhrases as $phrase) {
        $normalized = preg_replace('/\b' . preg_quote($phrase, '/') . '\b/u', ' ', $normalized);
    }
    $keywords = trim(preg_replace('/\s+/', ' ', $normalized));

    return [
        'original' => trim($query),
        'keywords' => $keywords,
        'author' => $author,
        'availability' => $availability,
    ];
}

function interpret_library_action(string $transcript): ?array
{
    $normalized = mb_strtolower(trim($transcript));
    if (preg_match('/\b(dashboard|home|accueil|tableau de bord|ahabanza|mwanzo)\b/u', $normalized)) {
        return ['type' => 'navigate', 'path' => '/search', 'label' => 'Open library search'];
    }
    if (preg_match('/\b(search|books|catalog|catalogue|recherche|livres|chercher|ibitabo|shaka|tafuta|vitabu|maktaba)\b/u', $normalized)) {
        return ['type' => 'navigate', 'path' => '/search', 'label' => 'Open library search'];
    }
    if (preg_match('/\b(borrowed books?|borrowed|emprunts|empruntes|ibyo natije|nimetazima)\b/u', $normalized)) {
        return ['type' => 'navigate', 'path' => '/student/borrowed', 'label' => 'Open my borrowed books'];
    }
    if (preg_match('/\b(favorites|favourite books?|favoris|ibyo nkunda|vipendwa)\b/u', $normalized)) {
        return ['type' => 'navigate', 'path' => '/student/favorites', 'label' => 'Open favorites'];
    }
    if (preg_match('/\b(bookmarks?|signets|marque-pages|udumenyetso|alama)\b/u', $normalized)) {
        return ['type' => 'navigate', 'path' => '/student/bookmarks', 'label' => 'Open bookmarks'];
    }
    if (preg_match('/\b(recommendations?|recommandations|ibyifuzo|mapendekezo)\b/u', $normalized)) {
        return ['type' => 'navigate', 'path' => '/recommendations', 'label' => 'Open recommendations'];
    }
    if (preg_match('/\b(notification|notifications|amatangazo|imenyesha|taarifa)\b/u', $normalized)) {
        return ['type' => 'navigate', 'path' => '/notifications', 'label' => 'Open notifications'];
    }
    return null;
}
function book_select_sql(): string
{
    return "SELECT b.id, b.institution_id, b.title, b.subtitle, b.isbn, b.edition, b.publisher, b.publication_year,
            b.language, b.description, b.keywords, b.tags, b.license_type, b.visibility, b.access_level,
            b.cover_image, b.total_pages, b.reading_level, b.has_audio, b.has_translation, b.has_summary,
            b.metadata_quality_score, b.last_indexed_at,
            b.total_copies, b.available_copies, b.status,
            a.full_name AS author, cat.name AS category, f.name AS faculty, d.name AS department, c.name AS course
            FROM books b
            LEFT JOIN authors a ON a.id = b.author_id
            LEFT JOIN categories cat ON cat.id = b.category_id
            LEFT JOIN faculties f ON f.id = b.faculty_id
            LEFT JOIN departments d ON d.id = b.department_id
            LEFT JOIN courses c ON c.id = b.course_id";
}

function book_files_for(int $bookId): array
{
    $stmt = pdo()->prepare('SELECT id, file_type, original_name, mime_type, file_size, status, created_at FROM book_files WHERE book_id=:book_id AND status="active" ORDER BY created_at DESC');
    $stmt->execute([':book_id' => $bookId]);
    return $stmt->fetchAll();
}

function classify_file_type(string $extension): string
{
    return match (strtolower($extension)) {
        'pdf' => 'pdf',
        'docx' => 'docx',
        'txt' => 'txt',
        'mp3', 'wav', 'm4a', 'ogg', 'webm' => 'audio',
        'jpg', 'jpeg', 'png', 'webp' => 'cover',
        default => 'other',
    };
}

function safe_upload_extension(string $name): string
{
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed = ['pdf', 'docx', 'txt', 'mp3', 'wav', 'm4a', 'ogg', 'webm', 'jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($extension, $allowed, true)) {
        Response::error('Unsupported file type', 422);
    }
    return $extension;
}

function run_json_command(string $command): ?array
{
    $output = [];
    $exitCode = 1;
    exec($command . ' 2>&1', $output, $exitCode);
    if ($exitCode !== 0 || !$output) {
        return null;
    }
    for ($index = count($output) - 1; $index >= 0; $index--) {
        $decoded = json_decode($output[$index], true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return null;
}

function convert_word_document_to_pdf(string $source, string $target): bool
{
    if (strtolower(pathinfo($source, PATHINFO_EXTENSION)) !== 'docx') {
        return false;
    }
    $pythonScript = realpath(__DIR__ . '/../../scripts/document_pipeline.py');
    if (!$pythonScript) {
        return false;
    }
    $command = 'python ' . escapeshellarg($pythonScript) . ' convert ' .
        escapeshellarg($source) . ' ' . escapeshellarg($target);
    $result = run_json_command($command);
    return ($result['success'] ?? false) && is_file($target) && filesize($target) > 0;
}

function extract_document_text(string $path, int $maxChars = 500000): ?array
{
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($extension, ['pdf', 'docx', 'txt'], true)) {
        return null;
    }
    $sidecar = $path . '.content.txt';
    if (is_file($sidecar)) {
        $text = trim((string)file_get_contents($sidecar));
        return [
            'text' => mb_substr($text, 0, $maxChars),
            'characters' => mb_strlen($text),
            'truncated' => mb_strlen($text) > $maxChars,
        ];
    }

    $pythonScript = realpath(__DIR__ . '/../../scripts/document_pipeline.py');
    if (!$pythonScript) {
        return null;
    }
    $command = 'python ' . escapeshellarg($pythonScript) . ' extract ' .
        escapeshellarg($path) . ' --max-chars ' . $maxChars;
    $result = run_json_command($command);
    if (!($result['success'] ?? false)) {
        return null;
    }
    $text = trim((string)($result['text'] ?? ''));
    if ($text !== '') {
        @file_put_contents($sidecar, $text);
    }
    return [
        'text' => $text,
        'characters' => (int)($result['characters'] ?? mb_strlen($text)),
        'truncated' => (bool)($result['truncated'] ?? false),
    ];
}

function extract_document_page_text(string $path, int $page = 1, int $maxChars = 3000): ?array
{
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($extension, ['pdf', 'docx', 'txt'], true)) {
        return null;
    }

    $page = max(1, $page);
    $sidecar = $path . '.page-' . $page . '.txt';
    $metaSidecar = $path . '.page-' . $page . '.json';
    if (is_file($sidecar) && is_file($metaSidecar)) {
        $text = trim((string)file_get_contents($sidecar));
        $meta = json_decode((string)file_get_contents($metaSidecar), true) ?: [];
        if (array_key_exists('is_blank', $meta)) {
            return [
                'text' => mb_substr($text, 0, $maxChars),
                'characters' => mb_strlen($text),
                'truncated' => mb_strlen($text) > $maxChars,
                'page' => (int)($meta['page'] ?? $page),
                'total_pages' => (int)($meta['total_pages'] ?? $page),
                'is_blank' => (bool)$meta['is_blank'],
                'cached' => true,
            ];
        }
    }

    $pythonScript = realpath(__DIR__ . '/../../scripts/document_pipeline.py');
    if (!$pythonScript) {
        return null;
    }
    $command = 'python ' . escapeshellarg($pythonScript) . ' extract-page ' .
        escapeshellarg($path) . ' --page ' . $page . ' --max-chars ' . $maxChars;
    $result = run_json_command($command);
    if (!($result['success'] ?? false)) {
        return null;
    }

    $text = trim((string)($result['text'] ?? ''));
    $actualPage = (int)($result['page'] ?? $page);
    $totalPages = max(1, (int)($result['total_pages'] ?? $actualPage));
    $isBlank = (bool)($result['is_blank'] ?? false);
    @file_put_contents($sidecar, $text);
    @file_put_contents($metaSidecar, json_encode([
        'page' => $actualPage,
        'total_pages' => $totalPages,
        'is_blank' => $isBlank,
    ]));

    return [
        'text' => $text,
        'characters' => (int)($result['characters'] ?? mb_strlen($text)),
        'truncated' => (bool)($result['truncated'] ?? false),
        'page' => $actualPage,
        'total_pages' => $totalPages,
        'is_blank' => $isBlank,
        'cached' => false,
    ];
}

function render_pdf_page_image(string $path, int $page = 1): ?array
{
    if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'pdf') {
        return null;
    }
    $page = max(1, $page);
    $target = $path . '.page-' . $page . '.jpg';
    if (!is_file($target) || filesize($target) === 0) {
        $script = realpath(__DIR__ . '/../../scripts/extract_pdf_cover.py');
        if (!$script) {
            return null;
        }
        $command = 'python ' . escapeshellarg($script) . ' ' .
            escapeshellarg($path) . ' ' . escapeshellarg($target) . ' ' . $page;
        $output = [];
        $exitCode = 1;
        exec($command . ' 2>&1', $output, $exitCode);
        if ($exitCode !== 0) {
            return null;
        }
        if (!is_file($target) || filesize($target) === 0) {
            return null;
        }
    }
    return [
        'absolute_path' => $target,
        'mime_type' => 'image/jpeg',
        'original_name' => 'page-' . $page . '.jpg',
    ];
}

function serve_image_file(array $file): void
{
    $absolute = $file['absolute_path'];
    if (!is_file($absolute)) {
        Response::error('Image not found', 404);
    }
    header('Content-Type: ' . ($file['mime_type'] ?? 'image/jpeg'));
    header('Content-Length: ' . filesize($absolute));
    header('Cache-Control: private, max-age=86400');
    readfile($absolute);
    exit;
}

function prepare_tts_storage(array $user): array
{
    $uploadRoot = realpath(__DIR__ . '/../uploads') ?: (__DIR__ . '/../uploads');
    $relativeDirectory = 'tts/' . (int)$user['id'];
    $targetDirectory = $uploadRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);
    if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
        Response::error('Could not prepare narration storage', 500);
    }

    return [$relativeDirectory, $targetDirectory];
}

function synthesize_with_script(string $script, string $text, string $target, string $language, string $tempPrefix): ?array
{
    $input = tempnam(sys_get_temp_dir(), $tempPrefix);
    if (!$input || file_put_contents($input, $text) === false) {
        Response::error('Could not prepare narration text', 500);
    }
    try {
        $command = 'python ' . escapeshellarg($script) . ' ' .
            escapeshellarg($input) . ' ' . escapeshellarg($target) . ' --lang ' . escapeshellarg($language);
        $result = run_json_command($command);
        if (!($result['success'] ?? false) || !is_file($target) || filesize($target) === 0) {
            @unlink($target);
            return null;
        }
        return $result;
    } finally {
        @unlink($input);
    }
}

function clean_narration_text(string $text): string
{
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $replacements = [
        "\xE2\x80\x98" => "'",
        "\xE2\x80\x99" => "'",
        "\xE2\x80\x9C" => '"',
        "\xE2\x80\x9D" => '"',
        "\xE2\x80\x93" => ', ',
        "\xE2\x80\x94" => ', ',
        "\xC2\xA0" => ' ',
        'ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¯ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â' => 'fi',
        'ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¯ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡' => 'fl',
    ];
    $text = strtr($text, $replacements);
    $text = preg_replace('/https?:\/\/\S+/i', ' ', $text) ?? $text;
    $text = preg_replace('/([A-Za-z])-\s+([A-Za-z])/', '$1$2', $text) ?? $text;
    $text = preg_replace('/\s+([,.;:!?])/', '$1', $text) ?? $text;
    $text = preg_replace('/([,.;:!?])([A-Za-z])/', '$1 $2', $text) ?? $text;
    $text = preg_replace('/[^A-Za-z0-9\s.,;:!?\'"()\/%-]/u', ' ', $text) ?? $text;
    $text = preg_replace('/\s{2,}/', ' ', $text) ?? $text;
    return trim($text);
}

function generate_gtts_audio(array $user, string $text, string $language = 'en', bool $failHard = true): ?array
{
    [$relativeDirectory, $targetDirectory] = prepare_tts_storage($user);
    $script = realpath(__DIR__ . '/../../scripts/gtts_synthesize.py');
    if (!$script) {
        if (!$failHard) {
            return null;
        }
        Response::error('The gTTS narration service is not installed', 503);
    }

    $filename = hash('sha256', 'gtts|' . $language . '|' . $text) . '.mp3';
    $target = $targetDirectory . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($target) || filesize($target) === 0) {
        $result = synthesize_with_script($script, $text, $target, $language, 'mdl-gtts-');
        if (!$result) {
            if (!$failHard) {
                return null;
            }
            Response::error('gTTS could not generate this narration. Check the internet connection and try again.', 503);
        }
    }

    return [
        'filename' => $filename,
        'file_path' => 'uploads/' . $relativeDirectory . '/' . $filename,
        'absolute_path' => $target,
        'mime_type' => 'audio/mpeg',
        'file_size' => filesize($target),
        'provider' => 'gtts',
    ];
}

function generate_speecht5_service_audio(string $text, string $target, string $language = 'en'): ?array
{
    $result = service_json_post(configured_url('speecht5_tts_url', 'http://127.0.0.1:5007/synthesize'), [
        'text' => $text,
        'language' => $language,
    ], 20);
    if (!($result['success'] ?? false) || empty($result['audio_base64'])) {
        return null;
    }

    $audio = base64_decode((string)$result['audio_base64'], true);
    if ($audio === false || strlen($audio) <= 44) {
        return null;
    }
    if (file_put_contents($target, $audio) === false || !is_file($target) || filesize($target) === 0) {
        @unlink($target);
        return null;
    }

    return $result;
}


function generate_mms_service_audio(string $text, string $target, string $language = 'en'): ?array
{
    $result = service_json_post(configured_url('mms_tts_url', 'http://127.0.0.1:5009/synthesize'), [
        'text' => $text,
        'language' => $language,
    ], 45);
    if (!($result['success'] ?? false) || empty($result['audio_base64'])) {
        return null;
    }

    $audio = base64_decode((string)$result['audio_base64'], true);
    if ($audio === false || strlen($audio) <= 44) {
        return null;
    }
    if (file_put_contents($target, $audio) === false || !is_file($target) || filesize($target) === 0) {
        @unlink($target);
        return null;
    }

    return $result;
}

function generate_mms_audio(array $user, string $text, string $language = 'en'): ?array
{
    [$relativeDirectory, $targetDirectory] = prepare_tts_storage($user);
    $filename = hash('sha256', 'mms|' . $language . '|' . $text) . '.wav';
    $target = $targetDirectory . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($target) || filesize($target) === 0) {
        $serviceResult = generate_mms_service_audio($text, $target, $language);
        if (!$serviceResult) {
            return null;
        }
    }

    return [
        'filename' => $filename,
        'file_path' => 'uploads/' . $relativeDirectory . '/' . $filename,
        'absolute_path' => $target,
        'mime_type' => 'audio/wav',
        'file_size' => filesize($target),
        'provider' => 'mms_tts',
    ];
}
function generate_speecht5_audio(array $user, string $text, string $language = 'en'): ?array
{
    [$relativeDirectory, $targetDirectory] = prepare_tts_storage($user);
    $script = realpath(__DIR__ . '/../../scripts/speecht5_synthesize.py');
    if (!$script) {
        return null;
    }

    $filename = hash('sha256', 'speecht5|' . $language . '|' . $text) . '.wav';
    $target = $targetDirectory . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($target) || filesize($target) === 0) {
        $serviceResult = generate_speecht5_service_audio($text, $target, $language);
        if (!$serviceResult) {
            if (!warm_speecht5_model()) {
                return null;
            }
            $result = synthesize_with_script($script, $text, $target, $language, 'mdl-speecht5-');
            if (!$result) {
                return null;
            }
        }
    }

    return [
        'filename' => $filename,
        'file_path' => 'uploads/' . $relativeDirectory . '/' . $filename,
        'absolute_path' => $target,
        'mime_type' => 'audio/wav',
        'file_size' => filesize($target),
        'provider' => 'speecht5',
    ];
}

function generate_narration_audio(array $user, string $text, string $language = 'en', string $preference = 'speecht5'): array
{
    $text = clean_narration_text($text);
    if ($text === '') {
        Response::error('Text is required for audio narration', 422);
    }
    if (mb_strlen($text) > 3500) {
        Response::error('Narration sections must be 3,500 characters or shorter', 422);
    }

    $language = strtolower(trim($language)) ?: 'en';
    if (!in_array($language, ['en', 'fr', 'rw', 'sw'], true)) {
        Response::error('Narration language is not supported', 422);
    }

    $preference = strtolower(trim($preference));
    if ($preference === 'mms' || in_array($language, ['fr', 'rw', 'sw'], true)) {
        $mms = generate_mms_audio($user, $text, $language);
        if ($mms) {
            return $mms;
        }
    }

    if (in_array($preference, ['clear', 'gtts', 'google'], true)) {
        $gtts = generate_gtts_audio($user, $text, $language, false);
        if ($gtts) {
            return $gtts;
        }
        $mms = generate_mms_audio($user, $text, $language);
        if ($mms) {
            return $mms;
        }
        $speechT5 = $language === 'en' ? generate_speecht5_audio($user, $text, $language) : null;
        if ($speechT5) {
            return $speechT5;
        }
        Response::error('No text-to-speech provider could generate this narration.', 503);
    }

    $speechT5 = $language === 'en' ? generate_speecht5_audio($user, $text, $language) : null;
    if ($speechT5) {
        return $speechT5;
    }

    $mms = generate_mms_audio($user, $text, $language);
    if ($mms) {
        return $mms;
    }

    return generate_gtts_audio($user, $text, $language);
}
function process_uploaded_library_file(array $file, string $relativeDirectory): array
{
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        Response::error('The file upload did not complete successfully', 422);
    }
    $maxBytes = configured_int('max_book_upload_bytes', 50 * 1024 * 1024);
    if ((int)($file['size'] ?? 0) > $maxBytes) {
        Response::error('Book files must be ' . format_bytes($maxBytes) . ' or smaller', 422);
    }

    $extension = safe_upload_extension($file['name'] ?? '');
    $uploadRoot = realpath(__DIR__ . '/../uploads') ?: (__DIR__ . '/../uploads');
    $normalizedDirectory = trim(str_replace(['\\', '..'], ['/', ''], $relativeDirectory), '/');
    $targetDirectory = $uploadRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalizedDirectory);
    if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
        Response::error('Could not prepare upload storage', 500);
    }

    $storedName = bin2hex(random_bytes(12)) . '.' . $extension;
    $target = $targetDirectory . DIRECTORY_SEPARATOR . $storedName;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        Response::error('Could not store uploaded file', 500);
    }

    $converted = false;
    $sourceName = basename((string)($file['name'] ?? $storedName));
    $finalExtension = $extension;
    $finalTarget = $target;
    $finalName = $sourceName;
    $mimeType = $file['type'] ?? null;

    if ($extension === 'docx') {
        $pdfStoredName = pathinfo($storedName, PATHINFO_FILENAME) . '.pdf';
        $pdfTarget = $targetDirectory . DIRECTORY_SEPARATOR . $pdfStoredName;
        if (!convert_word_document_to_pdf($target, $pdfTarget)) {
            @unlink($target);
            @unlink($pdfTarget);
            Response::error('The Word document could not be converted to PDF. Check that it is a valid DOCX file.', 422);
        }
        @unlink($target);
        $storedName = $pdfStoredName;
        $finalTarget = $pdfTarget;
        $finalExtension = 'pdf';
        $finalName = pathinfo($sourceName, PATHINFO_FILENAME) . '.pdf';
        $mimeType = 'application/pdf';
        $converted = true;
    }

    if (in_array($finalExtension, ['pdf', 'txt'], true)) {
        extract_document_text($finalTarget);
    }

    return [
        'file_type' => classify_file_type($finalExtension),
        'file_path' => 'uploads/' . $normalizedDirectory . '/' . $storedName,
        'original_name' => $finalName,
        'source_name' => $sourceName,
        'mime_type' => $mimeType,
        'file_size' => filesize($finalTarget),
        'converted_to_pdf' => $converted,
        'absolute_path' => $finalTarget,
    ];
}

function resolve_stored_upload(array $file): array
{
    $absolute = realpath(__DIR__ . '/../' . $file['file_path']);
    $uploadsRoot = realpath(__DIR__ . '/../uploads');
    if (!$absolute || !$uploadsRoot || !str_starts_with(str_replace('\\', '/', $absolute), str_replace('\\', '/', $uploadsRoot))) {
        Response::error('File storage path is invalid', 404);
    }
    $file['absolute_path'] = $absolute;
    return $file;
}

function stored_book_file(int $fileId): array
{
    $stmt = pdo()->prepare('SELECT * FROM book_files WHERE id=:id AND status="active" LIMIT 1');
    $stmt->execute([':id' => $fileId]);
    $file = $stmt->fetch();
    if (!$file) {
        Response::error('File not found', 404);
    }
    return resolve_stored_upload($file);
}

function serve_stored_file(array $file, bool $inline = false): void
{
    $absolute = $file['absolute_path'];
    $size = filesize($absolute);
    $start = 0;
    $end = $size - 1;
    $status = 200;

    header('Accept-Ranges: bytes');
    if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
        $requestedStart = $matches[1] !== '' ? (int)$matches[1] : 0;
        $requestedEnd = $matches[2] !== '' ? (int)$matches[2] : $end;
        if ($requestedStart <= $requestedEnd && $requestedStart < $size) {
            $start = $requestedStart;
            $end = min($requestedEnd, $end);
            $status = 206;
        }
    }

    $safeName = preg_replace('/[^A-Za-z0-9._ -]/', '_', basename($file['original_name'] ?: $absolute));
    http_response_code($status);
    header('Content-Type: ' . ($file['mime_type'] ?: 'application/octet-stream'));
    header('Content-Length: ' . (($end - $start) + 1));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $safeName . '"');
    if ($status === 206) {
        header("Content-Range: bytes {$start}-{$end}/{$size}");
    }

    $handle = fopen($absolute, 'rb');
    if (!$handle) {
        Response::error('Could not read stored file', 500);
    }
    fseek($handle, $start);
    $remaining = ($end - $start) + 1;
    while ($remaining > 0 && !feof($handle)) {
        $chunk = fread($handle, min(8192, $remaining));
        if ($chunk === false) {
            break;
        }
        echo $chunk;
        $remaining -= strlen($chunk);
    }
    fclose($handle);
    exit;
}

function optional_int(mixed $value, string $label): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    return Validator::int($value, $label);
}

function bounded_int(mixed $value, int $default, int $min, int $max): int
{
    if ($value === null || $value === '') {
        return max($min, min($default, $max));
    }
    $parsed = filter_var($value, FILTER_VALIDATE_INT);
    if ($parsed === false) {
        return $default;
    }
    return max($min, min((int)$parsed, $max));
}

function fetch_book(int $id, bool $forUpdate = false): array
{
    $sql = 'SELECT * FROM books WHERE id=:id' . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = pdo()->prepare($sql);
    $stmt->execute([':id' => $id]);
    $book = $stmt->fetch();
    if (!$book) {
        Response::error('Book not found', 404);
    }
    return $book;
}

function fetch_book_submission(int $id, bool $forUpdate = false): array
{
    $sql = 'SELECT * FROM book_submissions WHERE id=:id' . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = pdo()->prepare($sql);
    $stmt->execute([':id' => $id]);
    $submission = $stmt->fetch();
    if (!$submission) {
        Response::error('Book submission not found', 404);
    }
    return $submission;
}

function fetch_personal_book(int $id, int $userId): array
{
    $stmt = pdo()->prepare(
        'SELECT * FROM personal_books WHERE id=:id AND user_id=:user_id AND status="active" LIMIT 1'
    );
    $stmt->execute([':id' => $id, ':user_id' => $userId]);
    $book = $stmt->fetch();
    if (!$book) {
        Response::error('Private book not found', 404);
    }
    return $book;
}

function delete_stored_upload(array $file): void
{
    $resolved = resolve_stored_upload($file);
    @unlink($resolved['absolute_path'] . '.content.txt');
    @unlink($resolved['absolute_path']);
}

function fetch_reading_list(int $id): array
{
    $stmt = pdo()->prepare('SELECT * FROM reading_lists WHERE id=:id AND status="active"');
    $stmt->execute([':id' => $id]);
    $list = $stmt->fetch();
    if (!$list) {
        Response::error('Reading list not found', 404);
    }
    return $list;
}

function require_reading_list_manager(array $user, array $list): void
{
    if ($user['role_code'] !== 'LIBRARIAN_ADMIN' && (int)$list['lecturer_id'] !== (int)$user['id']) {
        Response::error('You cannot manage this reading list', 403);
    }
}

function normalize_log_status(string $status): string
{
    return in_array($status, ['success', 'empty_transcript', 'failed'], true) ? $status : 'success';
}

function route(string $method, string $path): void
{
    if ($method === 'GET' && $path === '/health') {
        $databaseOk = false;
        try {
            $databaseOk = pdo()->query('SELECT 1')->fetchColumn() === 1;
        } catch (Throwable) {
            $databaseOk = false;
        }
        $uploadsWritable = is_writable(__DIR__ . '/../uploads');
        $ttsHealth = tts_health();
        $speechT5Ready = (bool)($ttsHealth['primary']['success'] ?? false);
        Response::ok([
            'status' => ($databaseOk && $uploadsWritable && $speechT5Ready) ? 'ok' : 'degraded',
            'service' => 'Rwanda Library backend',
            'environment' => app_config()['env'] ?? 'local',
            'database' => $databaseOk ? 'connected' : 'unavailable',
            'uploads_writable' => $uploadsWritable,
            'limits' => [
                'book_upload_bytes' => configured_int('max_book_upload_bytes', 50 * 1024 * 1024),
                'lecture_note_upload_bytes' => configured_int('max_lecture_note_upload_bytes', 25 * 1024 * 1024),
            ],
            'tts' => $ttsHealth,
            'tenant' => class_exists('TenantService') ? TenantService::health() : ['status' => 'unavailable'],
            'storage' => class_exists('StorageService') ? StorageService::capabilities() : ['status' => 'unavailable'],
            'translation' => class_exists('TranslationService') ? TranslationService::health() : ['status' => 'unavailable'],
            'timestamp' => gmdate('c'),
        ]);
    }

    if ($method === 'POST' && $path === '/auth/register') {
        $data = body();
        Validator::require($data, ['full_name', 'email', 'password']);
        Validator::email($data['email']);
        if (strlen((string)$data['password']) < 8) {
            Response::error('Password must be at least 8 characters', 422);
        }
        $email = strtolower(trim((string)$data['email']));
        $existingUser = pdo()->prepare('SELECT id FROM users WHERE email=:email LIMIT 1');
        $existingUser->execute([':email' => $email]);
        if ($existingUser->fetch()) {
            Response::error('An account with this email already exists', 409);
        }

        $roleCode = 'STUDENT';
        $roleStmt = pdo()->prepare('SELECT id FROM roles WHERE code = :code AND status = "active"');
        $roleStmt->execute([':code' => $roleCode]);
        $role = $roleStmt->fetch();
        if (!$role) {
            Response::error('Invalid role', 422);
        }

        $stmt = pdo()->prepare(
            'INSERT INTO users (role_id, full_name, email, phone, gender, password_hash, status)
             VALUES (:role_id, :full_name, :email, :phone, :gender, :password_hash, "active")'
        );
        $stmt->execute([
            ':role_id' => $role['id'],
            ':full_name' => trim($data['full_name']),
            ':email' => $email,
            ':phone' => $data['phone'] ?? null,
            ':gender' => $data['gender'] ?? null,
            ':password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
        ]);
        $userId = (int)pdo()->lastInsertId();
        ActivityLogService::log(['id' => $userId, 'role_code' => $roleCode], 'register_user');
        Response::ok(['id' => $userId], 'User registered successfully');
    }

    if ($method === 'POST' && $path === '/auth/login') {
        enforce_rate_limit('login', 10, 300);
        $data = body();
        Validator::require($data, ['email', 'password']);
        Validator::email($data['email']);

        $stmt = pdo()->prepare(
            'SELECT u.*, r.code AS role_code, r.name AS role_name
             FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.email = :email LIMIT 1'
        );
        $stmt->execute([':email' => strtolower(trim($data['email']))]);
        $user = $stmt->fetch();
        $ok = $user && password_verify($data['password'], $user['password_hash']) && $user['status'] === 'active';

        $log = pdo()->prepare('INSERT INTO login_logs (user_id, email, ip_address, user_agent, status, failure_reason) VALUES (:user_id, :email, :ip, :ua, :status, :reason)');
        $log->execute([
            ':user_id' => $user['id'] ?? null,
            ':email' => strtolower(trim($data['email'])),
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ':status' => $ok ? 'success' : 'failed',
            ':reason' => $ok ? null : 'Invalid credentials or inactive account',
        ]);

        if (!$ok) {
            Response::error('Invalid credentials or inactive account', 401);
        }

        $token = bin2hex(random_bytes(32));
        $app = app_config();
        $update = pdo()->prepare('UPDATE users SET api_token_hash=:hash, token_expires_at=DATE_ADD(NOW(), INTERVAL :hours HOUR), last_login_at=NOW() WHERE id=:id');
        $update->bindValue(':hash', hash('sha256', $token));
        $update->bindValue(':hours', (int)$app['token_ttl_hours'], PDO::PARAM_INT);
        $update->bindValue(':id', (int)$user['id'], PDO::PARAM_INT);
        $update->execute();

        $loginInstitution = class_exists('TenantService') ? TenantService::forUser($user) : null;
        $loginInstitutionPayload = $loginInstitution ? [
            'id' => (int)$loginInstitution['id'],
            'name' => $loginInstitution['name'],
            'slug' => $loginInstitution['slug'],
            'role' => $loginInstitution['institution_role'] ?? null,
        ] : null;
        ActivityLogService::log($user, 'login');
        Response::ok([
            'token' => $token,
            'user' => [
                'id' => (int)$user['id'],
                'name' => $user['full_name'],
                'email' => $user['email'],
                'role' => strtolower($user['role_code']) === 'librarian_admin' ? 'librarian_admin' : strtolower($user['role_code']),
                'role_code' => $user['role_code'],
                'role_name' => $user['role_name'],
                'status' => $user['status'],
                'institution' => $loginInstitutionPayload,
            ],
        ], 'Login successful');
    }

    if ($method === 'GET' && $path === '/auth/me') {
        Response::ok(current_user(true));
    }

    if ($method === 'POST' && $path === '/auth/logout') {
        $user = current_user(false);
        if ($user) {
            pdo()->prepare('UPDATE users SET api_token_hash=NULL, token_expires_at=NULL WHERE id=:id')->execute([':id' => $user['id']]);
            ActivityLogService::log($user, 'logout');
        }
        Response::ok(null, 'Logged out');
    }

    if ($method === 'POST' && $path === '/auth/forgot-password') {
        enforce_rate_limit('forgot-password', 5, 900);
        $data = body();
        Validator::require($data, ['email']);
        Validator::email($data['email']);
        $email = strtolower(trim($data['email']));
        $stmt = pdo()->prepare('SELECT id FROM users WHERE email=:email AND status="active" LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();
        $response = null;
        if ($user) {
            $token = bin2hex(random_bytes(32));
            $db = pdo();
            $db->beginTransaction();
            try {
                $db->prepare('UPDATE password_resets SET status="expired" WHERE user_id=:user_id AND status="active"')
                    ->execute([':user_id' => $user['id']]);
                $db->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at, status) VALUES (:user_id, :hash, DATE_ADD(NOW(), INTERVAL 1 HOUR), "active")')
                    ->execute([':user_id' => $user['id'], ':hash' => hash('sha256', $token)]);
                $db->commit();
            } catch (Throwable $error) {
                $db->rollBack();
                throw $error;
            }
            $app = app_config();
            if (($app['env'] ?? 'production') === 'local') {
                $response = [
                    'reset_token' => $token,
                    'reset_url' => '/reset-password?token=' . rawurlencode($token) . '&email=' . rawurlencode($email),
                    'expires_in_minutes' => 60,
                ];
            }
        }
        Response::ok(
            $response,
            $response
                ? 'A secure reset link is ready below. It expires in 60 minutes.'
                : 'If an active account uses that email, password reset instructions have been sent.'
        );
    }

    if ($method === 'POST' && $path === '/auth/reset-password/validate') {
        $data = body();
        Validator::require($data, ['token', 'email']);
        Validator::email($data['email']);
        $email = strtolower(trim((string)$data['email']));
        $stmt = pdo()->prepare(
            'SELECT u.email, pr.expires_at
             FROM password_resets pr
             JOIN users u ON u.id=pr.user_id
             WHERE pr.token_hash=:hash AND u.email=:email
               AND pr.status="active" AND pr.used_at IS NULL
               AND pr.expires_at > NOW() AND u.status="active"
             LIMIT 1'
        );
        $stmt->execute([
            ':hash' => hash('sha256', trim((string)$data['token'])),
            ':email' => $email,
        ]);
        $reset = $stmt->fetch();
        if (!$reset) {
            Response::error('This reset link does not match the email account or has expired', 422);
        }
        Response::ok([
            'email' => $reset['email'],
            'expires_at' => $reset['expires_at'],
        ], 'Reset link verified');
    }

    if ($method === 'POST' && $path === '/auth/reset-password') {
        $data = body();
        Validator::require($data, ['token', 'email', 'password']);
        Validator::email($data['email']);
        $email = strtolower(trim((string)$data['email']));
        validate_new_password((string)$data['password']);
        if (isset($data['password_confirmation']) && !hash_equals((string)$data['password'], (string)$data['password_confirmation'])) {
            Response::error('Password confirmation does not match', 422);
        }

        $db = pdo();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'SELECT pr.id, pr.user_id, u.email
                 FROM password_resets pr
                 JOIN users u ON u.id=pr.user_id
                 WHERE pr.token_hash=:hash AND u.email=:email
                   AND pr.status="active" AND pr.used_at IS NULL
                   AND pr.expires_at > NOW() AND u.status="active"
                 LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([
                ':hash' => hash('sha256', trim((string)$data['token'])),
                ':email' => $email,
            ]);
            $reset = $stmt->fetch();
            if (!$reset) {
                $db->rollBack();
                Response::error('This reset link does not match the email account or has expired', 422);
            }
            $db->prepare('UPDATE users SET password_hash=:password_hash, api_token_hash=NULL, token_expires_at=NULL WHERE id=:id')
                ->execute([
                    ':password_hash' => password_hash((string)$data['password'], PASSWORD_DEFAULT),
                    ':id' => $reset['user_id'],
                ]);
            $db->prepare('UPDATE password_resets SET status="used", used_at=NOW() WHERE id=:id')
                ->execute([':id' => $reset['id']]);
            $db->prepare('UPDATE password_resets SET status="expired" WHERE user_id=:user_id AND status="active" AND id<>:id')
                ->execute([':user_id' => $reset['user_id'], ':id' => $reset['id']]);
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
        Response::ok(null, 'Password reset successfully. Sign in with your new password');
    }

    if ($method === 'PATCH' && $path === '/auth/password') {
        $user = current_user();
        $data = body();
        Validator::require($data, ['current_password', 'password']);
        validate_new_password((string)$data['password']);
        $stmt = pdo()->prepare('SELECT password_hash FROM users WHERE id=:id');
        $stmt->execute([':id' => $user['id']]);
        $hash = $stmt->fetchColumn();
        if (!$hash || !password_verify((string)$data['current_password'], $hash)) {
            Response::error('Current password is incorrect', 422);
        }
        pdo()->prepare('UPDATE users SET password_hash=:hash, api_token_hash=NULL, token_expires_at=NULL WHERE id=:id')
            ->execute([':hash' => password_hash((string)$data['password'], PASSWORD_DEFAULT), ':id' => $user['id']]);
        ActivityLogService::log($user, 'password_updated');
        Response::ok(null, 'Password updated. Sign in again with the new password');
    }

    if ($method === 'GET' && $path === '/users') {
        $user = current_user();
        require_role($user, admin_roles());
        $stmt = pdo()->query('SELECT u.id, u.full_name, u.email, u.phone, u.gender, u.status, r.code AS role_code, r.name AS role_name FROM users u JOIN roles r ON r.id=u.role_id ORDER BY u.created_at DESC');
        Response::ok($stmt->fetchAll());
    }

    if ($method === 'POST' && $path === '/users') {
        $user = current_user();
        require_role($user, admin_roles());
        $data = body();
        Validator::require($data, ['full_name', 'email', 'password', 'role_id']);
        $stmt = pdo()->prepare('INSERT INTO users (role_id, full_name, email, phone, gender, password_hash, status) VALUES (:role_id, :full_name, :email, :phone, :gender, :password_hash, :status)');
        $stmt->execute([
            ':role_id' => Validator::int($data['role_id'], 'role_id'),
            ':full_name' => $data['full_name'],
            ':email' => strtolower($data['email']),
            ':phone' => $data['phone'] ?? null,
            ':gender' => $data['gender'] ?? null,
            ':password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
            ':status' => $data['status'] ?? 'active',
        ]);
        Response::ok(['id' => (int)pdo()->lastInsertId()], 'User created');
    }

    if (preg_match('#^/users/(\d+)$#', $path, $m)) {
        $actor = current_user();
        require_role($actor, admin_roles());
        $id = Validator::int($m[1]);
        if ($method === 'GET') {
            $stmt = pdo()->prepare('SELECT u.id, u.full_name, u.email, u.phone, u.gender, u.status, r.code AS role_code FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=:id');
            $stmt->execute([':id' => $id]);
            Response::ok($stmt->fetch());
        }
        if ($method === 'PUT') {
            $data = body();
            $stmt = pdo()->prepare('UPDATE users SET full_name=:full_name, phone=:phone, gender=:gender, status=:status WHERE id=:id');
            $stmt->execute([
                ':full_name' => $data['full_name'] ?? '',
                ':phone' => $data['phone'] ?? null,
                ':gender' => $data['gender'] ?? null,
                ':status' => $data['status'] ?? 'active',
                ':id' => $id,
            ]);
            Response::ok(['id' => $id], 'User updated');
        }
        if ($method === 'DELETE') {
            pdo()->prepare('UPDATE users SET status="inactive" WHERE id=:id')->execute([':id' => $id]);
            Response::ok(['id' => $id], 'User deactivated');
        }
    }

    if (preg_match('#^/users/(\d+)/(deactivate|role)$#', $path, $m) && $method === 'PATCH') {
        $actor = current_user();
        require_role($actor, admin_roles());
        $id = Validator::int($m[1]);
        $data = body();
        if ($m[2] === 'deactivate') {
            pdo()->prepare('UPDATE users SET status=:status WHERE id=:id')->execute([':status' => $data['status'] ?? 'inactive', ':id' => $id]);
        } else {
            pdo()->prepare('UPDATE users SET role_id=:role_id WHERE id=:id')->execute([':role_id' => Validator::int($data['role_id'], 'role_id'), ':id' => $id]);
        }
        Response::ok(['id' => $id], 'User updated');
    }

    if ($method === 'POST' && $path === '/book-submissions') {
        $user = current_user();
        require_role($user, ['STUDENT']);
        Validator::require($_POST, ['title']);
        if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            Response::error('A PDF, Word, text, or audio file is required', 422);
        }
        $submissionExtension = strtolower(pathinfo((string)($_FILES['file']['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($submissionExtension, ['pdf', 'docx', 'txt', 'mp3', 'wav', 'm4a', 'ogg', 'webm'], true)) {
            Response::error('Student submissions must be a PDF, DOCX, TXT, or supported audio file', 422);
        }

        $asset = process_uploaded_library_file(
            $_FILES['file'],
            'submissions/' . (int)$user['id']
        );
        $totalCopies = bounded_int($_POST['total_copies'] ?? null, 1, 1, 1000000);
        $stmt = pdo()->prepare(
            'INSERT INTO book_submissions
             (submitted_by, title, isbn, publisher, publication_year, description, keywords, total_copies,
              file_type, file_path, original_name, source_name, mime_type, file_size, converted_to_pdf)
             VALUES
             (:submitted_by, :title, :isbn, :publisher, :publication_year, :description, :keywords, :total_copies,
              :file_type, :file_path, :original_name, :source_name, :mime_type, :file_size, :converted_to_pdf)'
        );
        $stmt->execute([
            ':submitted_by' => $user['id'],
            ':title' => trim((string)$_POST['title']),
            ':isbn' => trim((string)($_POST['isbn'] ?? '')) ?: null,
            ':publisher' => trim((string)($_POST['publisher'] ?? '')) ?: null,
            ':publication_year' => optional_int($_POST['publication_year'] ?? null, 'publication_year'),
            ':description' => trim((string)($_POST['description'] ?? '')) ?: null,
            ':keywords' => trim((string)($_POST['keywords'] ?? '')) ?: null,
            ':total_copies' => $totalCopies,
            ':file_type' => $asset['file_type'],
            ':file_path' => $asset['file_path'],
            ':original_name' => $asset['original_name'],
            ':source_name' => $asset['source_name'],
            ':mime_type' => $asset['mime_type'],
            ':file_size' => $asset['file_size'],
            ':converted_to_pdf' => $asset['converted_to_pdf'] ? 1 : 0,
        ]);
        $submissionId = (int)pdo()->lastInsertId();
        ActivityLogService::log($user, 'submit_book_for_review', 'success', 'book_submission', $submissionId, [
            'title' => trim((string)$_POST['title']),
            'source_name' => $asset['source_name'],
        ]);
        Response::ok([
            'id' => $submissionId,
            'status' => 'pending',
            'original_name' => $asset['original_name'],
            'source_name' => $asset['source_name'],
            'converted_to_pdf' => $asset['converted_to_pdf'],
        ], $asset['converted_to_pdf']
            ? 'Word document converted to PDF and sent to the librarian for verification'
            : 'Book sent to the librarian for verification');
    }

    if ($method === 'GET' && $path === '/book-submissions/my') {
        $user = current_user();
        require_role($user, ['STUDENT']);
        $stmt = pdo()->prepare(
            'SELECT bs.*, reviewer.full_name AS reviewer_name
             FROM book_submissions bs
             LEFT JOIN users reviewer ON reviewer.id=bs.reviewed_by
             WHERE bs.submitted_by=:user_id
             ORDER BY bs.created_at DESC'
        );
        $stmt->execute([':user_id' => $user['id']]);
        Response::ok($stmt->fetchAll());
    }

    if ($method === 'GET' && $path === '/book-submissions') {
        $user = current_user();
        require_role($user, admin_roles());
        $status = trim((string)($_GET['status'] ?? ''));
        $params = [];
        $condition = '';
        if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $condition = ' WHERE bs.status=:status';
            $params[':status'] = $status;
        }
        $stmt = pdo()->prepare(
            'SELECT bs.*, submitter.full_name AS submitted_by_name, submitter.email AS submitted_by_email,
                    reviewer.full_name AS reviewer_name
             FROM book_submissions bs
             JOIN users submitter ON submitter.id=bs.submitted_by
             LEFT JOIN users reviewer ON reviewer.id=bs.reviewed_by' .
             $condition . ' ORDER BY (bs.status="pending") DESC, bs.created_at DESC'
        );
        $stmt->execute($params);
        Response::ok($stmt->fetchAll());
    }

    if (preg_match('#^/book-submissions/(\d+)/download$#', $path, $m) && $method === 'GET') {
        $user = current_user();
        $submission = fetch_book_submission(Validator::int($m[1]));
        if ($user['role_code'] !== 'LIBRARIAN_ADMIN' && (int)$submission['submitted_by'] !== (int)$user['id']) {
            Response::error('You cannot access this submission', 403);
        }
        serve_stored_file(resolve_stored_upload($submission));
    }

    if (preg_match('#^/book-submissions/(\d+)/(approve|reject)$#', $path, $m) && $method === 'PATCH') {
        $user = current_user();
        require_role($user, admin_roles());
        $submissionId = Validator::int($m[1]);
        $action = $m[2];
        $data = body();
        $reviewNote = trim((string)($data['review_note'] ?? ''));

        if ($action === 'reject') {
            $stmt = pdo()->prepare(
                'UPDATE book_submissions
                 SET status="rejected", reviewed_by=:reviewed_by, reviewed_at=NOW(), review_note=:review_note
                 WHERE id=:id AND status="pending"'
            );
            $stmt->execute([
                ':reviewed_by' => $user['id'],
                ':review_note' => $reviewNote ?: null,
                ':id' => $submissionId,
            ]);
            if ($stmt->rowCount() !== 1) {
                Response::error('Only pending submissions can be rejected', 409);
            }
            $submission = fetch_book_submission($submissionId);
            pdo()->prepare(
                'INSERT INTO notifications (user_id, created_by, type, title, message)
                 VALUES (:user_id, :created_by, "system", :title, :message)'
            )->execute([
                ':user_id' => $submission['submitted_by'],
                ':created_by' => $user['id'],
                ':title' => 'Book submission needs attention',
                ':message' => $reviewNote !== ''
                    ? 'Your submission "' . $submission['title'] . '" was not approved: ' . $reviewNote
                    : 'Your submission "' . $submission['title'] . '" was not approved.',
            ]);
            ActivityLogService::log($user, 'reject_book_submission', 'success', 'book_submission', $submissionId);
            Response::ok(['id' => $submissionId, 'status' => 'rejected'], 'Book submission rejected');
        }

        $db = pdo();
        $db->beginTransaction();
        try {
            $submission = fetch_book_submission($submissionId, true);
            if ($submission['status'] !== 'pending') {
                $db->rollBack();
                Response::error('Only pending submissions can be approved', 409);
            }
            if ($submission['isbn']) {
                $duplicate = $db->prepare('SELECT id FROM books WHERE isbn=:isbn AND status <> "deleted" LIMIT 1');
                $duplicate->execute([':isbn' => $submission['isbn']]);
                if ($duplicate->fetch()) {
                    $db->rollBack();
                    Response::error('A catalog book already uses this ISBN', 409);
                }
            }

            $bookStmt = $db->prepare(
                'INSERT INTO books
                 (title, isbn, publisher, publication_year, description, keywords, total_copies, available_copies, created_by)
                 VALUES (:title, :isbn, :publisher, :publication_year, :description, :keywords,
                         :total_copies, :available_copies, :created_by)'
            );
            $bookStmt->execute([
                ':title' => $submission['title'],
                ':isbn' => $submission['isbn'],
                ':publisher' => $submission['publisher'],
                ':publication_year' => $submission['publication_year'],
                ':description' => $submission['description'],
                ':keywords' => $submission['keywords'],
                ':total_copies' => $submission['total_copies'],
                ':available_copies' => $submission['total_copies'],
                ':created_by' => $submission['submitted_by'],
            ]);
            $bookId = (int)$db->lastInsertId();
            ensure_generated_book_cover($db, $bookId);

            $fileStmt = $db->prepare(
                'INSERT INTO book_files
                 (book_id, file_type, file_path, original_name, mime_type, file_size, uploaded_by)
                 VALUES (:book_id, :file_type, :file_path, :original_name, :mime_type, :file_size, :uploaded_by)'
            );
            $fileStmt->execute([
                ':book_id' => $bookId,
                ':file_type' => $submission['file_type'],
                ':file_path' => $submission['file_path'],
                ':original_name' => $submission['original_name'],
                ':mime_type' => $submission['mime_type'],
                ':file_size' => $submission['file_size'],
                ':uploaded_by' => $submission['submitted_by'],
            ]);

            $db->prepare(
                'UPDATE book_submissions
                 SET status="approved", reviewed_by=:reviewed_by, reviewed_at=NOW(),
                     review_note=:review_note, approved_book_id=:book_id
                 WHERE id=:id'
            )->execute([
                ':reviewed_by' => $user['id'],
                ':review_note' => $reviewNote ?: null,
                ':book_id' => $bookId,
                ':id' => $submissionId,
            ]);
            $db->prepare(
                'INSERT INTO notifications (user_id, created_by, type, title, message)
                 VALUES (:user_id, :created_by, "new_book", :title, :message)'
            )->execute([
                ':user_id' => $submission['submitted_by'],
                ':created_by' => $user['id'],
                ':title' => 'Book submission approved',
                ':message' => 'Your submission "' . $submission['title'] . '" is now available in the library.',
            ]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        ActivityLogService::log($user, 'approve_book_submission', 'success', 'book_submission', $submissionId, [
            'book_id' => $bookId,
        ]);
        Response::ok([
            'id' => $submissionId,
            'status' => 'approved',
            'book_id' => $bookId,
        ], 'Book submission approved and published');
    }

    if ($method === 'GET' && $path === '/personal-books') {
        $user = current_user();
        $stmt = pdo()->prepare(
            'SELECT id, title, author, description, language_code, file_type, original_name, source_name,
                    mime_type, file_size, converted_to_pdf, created_at, updated_at
             FROM personal_books
             WHERE user_id=:user_id AND status="active"
             ORDER BY created_at DESC'
        );
        $stmt->execute([':user_id' => $user['id']]);
        Response::ok($stmt->fetchAll());
    }

    if ($method === 'POST' && $path === '/personal-books') {
        $user = current_user();
        if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            Response::error('An English PDF, DOCX, or TXT book is required', 422);
        }
        $data = $_POST;
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            Response::error('Book title is required', 422);
        }
        $extension = strtolower(pathinfo((string)($_FILES['file']['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($extension, ['pdf', 'docx', 'txt'], true)) {
            Response::error('Private books must be PDF, DOCX, or TXT files', 422);
        }

        $asset = process_uploaded_library_file($_FILES['file'], 'personal-books/' . (int)$user['id']);
        $content = extract_document_text($asset['absolute_path'], 20000);
        if (!$content || trim((string)($content['text'] ?? '')) === '') {
            delete_stored_upload($asset);
            Response::error('No readable English text could be extracted from this book', 422);
        }

        $stmt = pdo()->prepare(
            'INSERT INTO personal_books
             (user_id, title, author, description, language_code, file_type, file_path, original_name,
              source_name, mime_type, file_size, converted_to_pdf)
             VALUES (:user_id, :title, :author, :description, "en", :file_type, :file_path, :original_name,
                     :source_name, :mime_type, :file_size, :converted_to_pdf)'
        );
        $stmt->execute([
            ':user_id' => $user['id'],
            ':title' => $title,
            ':author' => trim((string)($data['author'] ?? '')) ?: null,
            ':description' => trim((string)($data['description'] ?? '')) ?: null,
            ':file_type' => $asset['file_type'],
            ':file_path' => $asset['file_path'],
            ':original_name' => $asset['original_name'],
            ':source_name' => $asset['source_name'],
            ':mime_type' => $asset['mime_type'],
            ':file_size' => $asset['file_size'],
            ':converted_to_pdf' => $asset['converted_to_pdf'] ? 1 : 0,
        ]);
        $personalBookId = (int)pdo()->lastInsertId();
        ActivityLogService::log($user, 'upload_private_book', 'success', 'personal_book', $personalBookId);
        Response::ok([
            'id' => $personalBookId,
            'converted_to_pdf' => $asset['converted_to_pdf'],
        ], $asset['converted_to_pdf']
            ? 'Word book converted to PDF and added to your private library'
            : 'Book added to your private library');
    }

    if (preg_match('#^/personal-books/(\d+)/content$#', $path, $m) && $method === 'GET') {
        $user = current_user();
        $book = fetch_personal_book(Validator::int($m[1]), (int)$user['id']);
        $file = resolve_stored_upload($book);
        $content = extract_document_text($file['absolute_path']);
        if (!$content || trim((string)($content['text'] ?? '')) === '') {
            Response::error('No readable text could be extracted from this private book', 422);
        }
        Response::ok([
            'id' => (int)$book['id'],
            'title' => $book['title'],
            'author' => $book['author'],
            'file_type' => $book['file_type'],
            ...$content,
        ]);
    }

    if (preg_match('#^/personal-books/(\d+)/download$#', $path, $m) && $method === 'GET') {
        $user = current_user();
        $book = fetch_personal_book(Validator::int($m[1]), (int)$user['id']);
        serve_stored_file(resolve_stored_upload($book));
    }

    if (preg_match('#^/personal-books/(\d+)$#', $path, $m)) {
        $user = current_user();
        $id = Validator::int($m[1]);
        $book = fetch_personal_book($id, (int)$user['id']);
        if ($method === 'GET') {
            Response::ok([
                'id' => (int)$book['id'],
                'title' => $book['title'],
                'author' => $book['author'],
                'description' => $book['description'],
                'language_code' => $book['language_code'],
                'file_type' => $book['file_type'],
                'original_name' => $book['original_name'],
                'file_size' => (int)$book['file_size'],
                'created_at' => $book['created_at'],
            ]);
        }
        if ($method === 'DELETE') {
            pdo()->prepare('DELETE FROM personal_books WHERE id=:id AND user_id=:user_id')
                ->execute([':id' => $id, ':user_id' => $user['id']]);
            delete_stored_upload($book);
            ActivityLogService::log($user, 'delete_private_book', 'success', 'personal_book', $id);
            Response::ok(['id' => $id], 'Private book permanently deleted');
        }
    }

    if ($method === 'GET' && $path === '/books') {
        $conditions = ["b.status = 'active'"];
        $params = [];
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q !== '') {
            $conditions[] = '(b.title LIKE :q_title OR b.subtitle LIKE :q_subtitle OR a.full_name LIKE :q_author OR b.isbn LIKE :q_isbn OR b.keywords LIKE :q_keywords)';
            $like = '%' . $q . '%';
            $params += [
                ':q_title' => $like,
                ':q_subtitle' => $like,
                ':q_author' => $like,
                ':q_isbn' => $like,
                ':q_keywords' => $like,
            ];
        }
        foreach (['faculty_id', 'department_id', 'course_id', 'category_id'] as $filter) {
            if (isset($_GET[$filter]) && $_GET[$filter] !== '') {
                $conditions[] = "b.{$filter} = :{$filter}";
                $params[":{$filter}"] = Validator::int($_GET[$filter], $filter);
            }
        }
        $availability = $_GET['availability'] ?? '';
        if ($availability === 'available') {
            $conditions[] = 'b.available_copies > 0';
        } elseif ($availability === 'unavailable') {
            $conditions[] = 'b.available_copies = 0';
        }
        $stmt = pdo()->prepare(
            book_select_sql() . ' WHERE ' . implode(' AND ', $conditions) .
            ' ORDER BY b.created_at DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', bounded_int($_GET['limit'] ?? null, 50, 1, 100), PDO::PARAM_INT);
        $stmt->bindValue(':offset', bounded_int($_GET['offset'] ?? null, 0, 0, PHP_INT_MAX), PDO::PARAM_INT);
        $stmt->execute();
        $books = $stmt->fetchAll();
        foreach ($books as &$book) {
            $book['files'] = book_files_for((int)$book['id']);
        }
        Response::ok($books);
    }

    if ($method === 'POST' && $path === '/books/upload') {
        $user = current_user();
        require_role($user, ['STUDENT', 'LECTURER', 'LIBRARIAN_ADMIN']);
        Validator::require($_POST, ['title']);
        if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            Response::error('Book file is required', 422);
        }

        $title = trim((string)$_POST['title']);
        if ($title === '') {
            Response::error('Book title is required', 422);
        }
        $isbn = trim((string)($_POST['isbn'] ?? '')) ?: null;
        $total = bounded_int($_POST['total_copies'] ?? null, 1, 1, 1000000);
        if ($isbn) {
            $duplicate = pdo()->prepare('SELECT id FROM books WHERE isbn=:isbn AND status <> "deleted" LIMIT 1');
            $duplicate->execute([':isbn' => $isbn]);
            if ($duplicate->fetch()) {
                Response::error('A catalog book already uses this ISBN', 409);
            }
        }

        $db = pdo();
        $asset = null;
        $db->beginTransaction();
        try {
            $bookStmt = $db->prepare(
                'INSERT INTO books
                 (title, isbn, publisher, publication_year, description, keywords,
                  total_copies, available_copies, status, created_by)
                 VALUES (:title, :isbn, :publisher, :publication_year, :description, :keywords,
                         :total_copies, :available_copies, "active", :created_by)'
            );
            $bookStmt->execute([
                ':title' => $title,
                ':isbn' => $isbn,
                ':publisher' => trim((string)($_POST['publisher'] ?? '')) ?: null,
                ':publication_year' => optional_int($_POST['publication_year'] ?? null, 'publication_year'),
                ':description' => trim((string)($_POST['description'] ?? '')) ?: null,
                ':keywords' => trim((string)($_POST['keywords'] ?? '')) ?: null,
                ':total_copies' => $total,
                ':available_copies' => $total,
                ':created_by' => $user['id'],
            ]);
            $bookId = (int)$db->lastInsertId();

            $asset = process_uploaded_library_file($_FILES['file'], 'books/' . $bookId);
            $fileStmt = $db->prepare(
                'INSERT INTO book_files
                 (book_id, file_type, file_path, original_name, mime_type, file_size, uploaded_by)
                 VALUES (:book_id, :file_type, :file_path, :original_name, :mime_type, :file_size, :uploaded_by)'
            );
            $fileStmt->execute([
                ':book_id' => $bookId,
                ':file_type' => $asset['file_type'],
                ':file_path' => $asset['file_path'],
                ':original_name' => $asset['original_name'],
                ':mime_type' => $asset['mime_type'],
                ':file_size' => $asset['file_size'],
                ':uploaded_by' => $user['id'],
            ]);
            $fileId = (int)$db->lastInsertId();
            ensure_generated_book_cover($db, $bookId);
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($asset && !empty($asset['absolute_path'])) {
                @unlink($asset['absolute_path'] . '.content.txt');
                @unlink($asset['absolute_path']);
            }
            throw $error;
        }

        ActivityLogService::log($user, 'upload_catalog_book', 'success', 'book', $bookId, [
            'title' => $title,
            'file_id' => $fileId,
            'source_name' => $asset['source_name'],
        ]);
        Response::ok([
            'id' => $bookId,
            'file_id' => $fileId,
            'status' => 'active',
            'original_name' => $asset['original_name'],
            'source_name' => $asset['source_name'],
            'converted_to_pdf' => $asset['converted_to_pdf'],
        ], $asset['converted_to_pdf']
            ? 'Word document converted to PDF and published in the catalog'
            : 'Book uploaded and published in the catalog');
    }

    if ($method === 'POST' && $path === '/books') {
        $user = current_user();
        require_role($user, ['LECTURER', 'LIBRARIAN_ADMIN']);
        $data = body();
        Validator::require($data, ['title']);
        $total = bounded_int($data['total_copies'] ?? null, 1, 0, 1000000);
        $available = bounded_int($data['available_copies'] ?? null, $total, 0, $total);
        $stmt = pdo()->prepare('INSERT INTO books (author_id, category_id, faculty_id, department_id, course_id, title, subtitle, isbn, publisher, publication_year, description, keywords, shelf_location, total_copies, available_copies, created_by) VALUES (:author_id, :category_id, :faculty_id, :department_id, :course_id, :title, :subtitle, :isbn, :publisher, :publication_year, :description, :keywords, :shelf_location, :total_copies, :available_copies, :created_by)');
        $stmt->execute([
            ':author_id' => optional_int($data['author_id'] ?? null, 'author_id'),
            ':category_id' => optional_int($data['category_id'] ?? null, 'category_id'),
            ':faculty_id' => optional_int($data['faculty_id'] ?? null, 'faculty_id'),
            ':department_id' => optional_int($data['department_id'] ?? null, 'department_id'),
            ':course_id' => optional_int($data['course_id'] ?? null, 'course_id'),
            ':title' => trim((string)$data['title']),
            ':subtitle' => $data['subtitle'] ?? null,
            ':isbn' => ($data['isbn'] ?? '') !== '' ? trim((string)$data['isbn']) : null,
            ':publisher' => $data['publisher'] ?? null,
            ':publication_year' => optional_int($data['publication_year'] ?? null, 'publication_year'),
            ':description' => $data['description'] ?? null,
            ':keywords' => $data['keywords'] ?? null,
            ':shelf_location' => $data['shelf_location'] ?? null,
            ':total_copies' => $total,
            ':available_copies' => $available,
            ':created_by' => $user['id'],
        ]);
        Response::ok(['id' => (int)pdo()->lastInsertId()], 'Book created');
    }

    if (preg_match('#^/books/(\d+)$#', $path, $m)) {
        $id = Validator::int($m[1]);
        if ($method === 'GET') {
            $stmt = pdo()->prepare(book_select_sql() . ' WHERE b.id=:id AND b.status <> "deleted"');
            $stmt->execute([':id' => $id]);
            $book = $stmt->fetch();
            if ($book) {
                $book['files'] = book_files_for($id);
            }
            Response::ok($book);
        }
        $user = current_user();
        require_role($user, admin_roles());
        if ($method === 'PUT') {
            $data = body();
            $existing = fetch_book($id);
            $total = bounded_int($data['total_copies'] ?? null, (int)$existing['total_copies'], 0, 1000000);
            $available = bounded_int($data['available_copies'] ?? null, (int)$existing['available_copies'], 0, $total);
            $stmt = pdo()->prepare(
                'UPDATE books SET author_id=:author_id, category_id=:category_id, faculty_id=:faculty_id,
                 department_id=:department_id, course_id=:course_id, title=:title, subtitle=:subtitle, isbn=:isbn,
                 publisher=:publisher, publication_year=:publication_year, description=:description, keywords=:keywords,
                 shelf_location=:shelf_location, cover_image=:cover_image, total_copies=:total_copies,
                 available_copies=:available_copies WHERE id=:id'
            );
            $stmt->execute([
                ':author_id' => array_key_exists('author_id', $data) ? optional_int($data['author_id'], 'author_id') : $existing['author_id'],
                ':category_id' => array_key_exists('category_id', $data) ? optional_int($data['category_id'], 'category_id') : $existing['category_id'],
                ':faculty_id' => array_key_exists('faculty_id', $data) ? optional_int($data['faculty_id'], 'faculty_id') : $existing['faculty_id'],
                ':department_id' => array_key_exists('department_id', $data) ? optional_int($data['department_id'], 'department_id') : $existing['department_id'],
                ':course_id' => array_key_exists('course_id', $data) ? optional_int($data['course_id'], 'course_id') : $existing['course_id'],
                ':title' => trim((string)($data['title'] ?? $existing['title'])),
                ':subtitle' => $data['subtitle'] ?? $existing['subtitle'],
                ':isbn' => array_key_exists('isbn', $data) && $data['isbn'] === '' ? null : ($data['isbn'] ?? $existing['isbn']),
                ':publisher' => $data['publisher'] ?? $existing['publisher'],
                ':publication_year' => array_key_exists('publication_year', $data) ? optional_int($data['publication_year'], 'publication_year') : $existing['publication_year'],
                ':description' => $data['description'] ?? $existing['description'],
                ':keywords' => $data['keywords'] ?? $existing['keywords'],
                ':shelf_location' => $data['shelf_location'] ?? $existing['shelf_location'],
                ':cover_image' => $data['cover_image'] ?? $existing['cover_image'],
                ':total_copies' => $total,
                ':available_copies' => $available,
                ':id' => $id,
            ]);
            Response::ok(['id' => $id], 'Book updated');
        }
        if ($method === 'DELETE') {
            $book = fetch_book($id);
            pdo()->prepare('UPDATE books SET status="deleted", updated_at=CURRENT_TIMESTAMP WHERE id=:id')->execute([':id' => $id]);
            ActivityLogService::log($user, 'delete_catalog_book', 'success', 'book', $id, [
                'title' => $book['title'],
                'deletion_mode' => 'soft_delete',
            ]);
            Response::ok(['id' => $id], 'Book deleted safely');
        }
    }

    if (preg_match('#^/books/(\d+)/archive$#', $path, $m) && $method === 'PATCH') {
        $user = current_user();
        require_role($user, admin_roles());
        $id = Validator::int($m[1]);
        $book = fetch_book($id);
        pdo()->prepare('UPDATE books SET status="archived", updated_at=CURRENT_TIMESTAMP WHERE id=:id')->execute([':id' => $id]);
        ActivityLogService::log($user, 'archive_catalog_book', 'success', 'book', $id, [
            'title' => $book['title'],
        ]);
        Response::ok(['id' => $id], 'Book archived');
    }

    if (preg_match('#^/books/(\d+)/availability$#', $path, $m) && $method === 'PATCH') {
        $user = current_user();
        require_role($user, admin_roles());
        $id = Validator::int($m[1]);
        $book = fetch_book($id);
        $available = bounded_int(body()['available_copies'] ?? null, (int)$book['available_copies'], 0, (int)$book['total_copies']);
        pdo()->prepare('UPDATE books SET available_copies=:available WHERE id=:id')
            ->execute([':available' => $available, ':id' => $id]);
        Response::ok(['id' => $id, 'available_copies' => $available], 'Book availability updated');
    }

    if (preg_match('#^/books/(\d+)/files$#', $path, $m)) {
        $bookId = Validator::int($m[1]);
        if ($method === 'GET') {
            current_user();
            Response::ok(book_files_for($bookId));
        }
        if ($method === 'POST') {
            $user = current_user();
            require_role($user, ['LECTURER', 'LIBRARIAN_ADMIN']);
            if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
                Response::error('Upload file is required', 422);
            }
            fetch_book($bookId);
            $asset = process_uploaded_library_file($_FILES['file'], 'books/' . $bookId);
            $stmt = pdo()->prepare('INSERT INTO book_files (book_id, file_type, file_path, original_name, mime_type, file_size, uploaded_by) VALUES (:book_id, :file_type, :file_path, :original_name, :mime_type, :file_size, :uploaded_by)');
            $stmt->execute([
                ':book_id' => $bookId,
                ':file_type' => $asset['file_type'],
                ':file_path' => $asset['file_path'],
                ':original_name' => $asset['original_name'],
                ':mime_type' => $asset['mime_type'],
                ':file_size' => $asset['file_size'],
                ':uploaded_by' => $user['id'],
            ]);
            $fileId = (int)pdo()->lastInsertId();
            ActivityLogService::log($user, $asset['converted_to_pdf'] ? 'convert_word_book_to_pdf' : 'upload_book_file', 'success', 'book_file', $fileId, [
                'book_id' => $bookId,
                'source_name' => $asset['source_name'],
                'stored_name' => $asset['original_name'],
            ]);
            Response::ok([
                'id' => $fileId,
                'book_id' => $bookId,
                'file_type' => $asset['file_type'],
                'original_name' => $asset['original_name'],
                'source_name' => $asset['source_name'],
                'converted_to_pdf' => $asset['converted_to_pdf'],
                'file_size' => $asset['file_size'],
            ], $asset['converted_to_pdf'] ? 'Word document converted to PDF and uploaded' : 'Book file uploaded');
        }
    }

    if (preg_match('#^/book-files/(\d+)/download$#', $path, $m) && $method === 'GET') {
        current_user();
        serve_stored_file(stored_book_file(Validator::int($m[1])));
    }

    if (preg_match('#^/book-files/(\d+)/stream$#', $path, $m) && $method === 'GET') {
        current_user();
        serve_stored_file(stored_book_file(Validator::int($m[1])), true);
    }

    if (preg_match('#^/book-files/(\d+)/content$#', $path, $m) && $method === 'GET') {
        current_user();
        $file = stored_book_file(Validator::int($m[1]));
        $content = extract_document_text($file['absolute_path']);
        if (!$content || trim($content['text'] ?? '') === '') {
            Response::error('No readable text could be extracted from this file', 422);
        }
        Response::ok([
            'file_id' => (int)$file['id'],
            'book_id' => (int)$file['book_id'],
            'file_type' => $file['file_type'],
            'original_name' => $file['original_name'],
            ...$content,
        ]);
    }

    if (preg_match('#^/book-files/(\d+)/page$#', $path, $m) && $method === 'GET') {
        current_user();
        $file = stored_book_file(Validator::int($m[1]));
        $page = Validator::int($_GET['page'] ?? 1, 'page');
        $content = extract_document_page_text($file['absolute_path'], $page);
        if (!$content) {
            Response::error('This page could not be inspected', 422);
        }
        Response::ok([
            'file_id' => (int)$file['id'],
            'book_id' => (int)$file['book_id'],
            'file_type' => $file['file_type'],
            'original_name' => $file['original_name'],
            ...$content,
        ]);
    }

    if (preg_match('#^/book-files/(\d+)/page-image$#', $path, $m) && $method === 'GET') {
        current_user();
        $file = stored_book_file(Validator::int($m[1]));
        $page = Validator::int($_GET['page'] ?? 1, 'page');
        $image = render_pdf_page_image($file['absolute_path'], $page);
        if (!$image) {
            Response::error('This page image could not be rendered', 422);
        }
        serve_image_file($image);
    }

    if (preg_match('#^/books/(\d+)/content$#', $path, $m) && $method === 'GET') {
        current_user();
        $bookId = Validator::int($m[1]);
        $stmt = pdo()->prepare(
            'SELECT id FROM book_files
             WHERE book_id=:book_id AND status="active" AND file_type IN ("pdf","docx","txt")
             ORDER BY FIELD(file_type, "pdf", "txt", "docx"), created_at DESC LIMIT 1'
        );
        $stmt->execute([':book_id' => $bookId]);
        $fileId = (int)($stmt->fetchColumn() ?: 0);
        if (!$fileId) {
            Response::error('This book has no readable document file', 404);
        }
        $file = stored_book_file($fileId);
        $content = extract_document_text($file['absolute_path']);
        if (!$content || trim($content['text'] ?? '') === '') {
            Response::error('No readable text could be extracted from this book', 422);
        }
        Response::ok([
            'file_id' => $fileId,
            'book_id' => $bookId,
            'file_type' => $file['file_type'],
            'original_name' => $file['original_name'],
            ...$content,
        ]);
    }

    if (preg_match('#^/books/(\d+)/page$#', $path, $m) && $method === 'GET') {
        current_user();
        $bookId = Validator::int($m[1]);
        $page = Validator::int($_GET['page'] ?? 1, 'page');
        $stmt = pdo()->prepare(
            'SELECT id FROM book_files
             WHERE book_id=:book_id AND status="active" AND file_type IN ("pdf","docx","txt","document")
             ORDER BY FIELD(file_type, "pdf", "txt", "docx", "document"), created_at DESC LIMIT 1'
        );
        $stmt->execute([':book_id' => $bookId]);
        $fileId = (int)($stmt->fetchColumn() ?: 0);
        if (!$fileId) {
            Response::error('This book has no readable document file', 404);
        }
        $file = stored_book_file($fileId);
        $content = extract_document_page_text($file['absolute_path'], $page);
        if (!$content) {
            Response::error('This page could not be inspected', 422);
        }
        Response::ok([
            'file_id' => $fileId,
            'book_id' => $bookId,
            'file_type' => $file['file_type'],
            'original_name' => $file['original_name'],
            ...$content,
        ]);
    }

    if (preg_match('#^/books/(\d+)/page-image$#', $path, $m) && $method === 'GET') {
        current_user();
        $bookId = Validator::int($m[1]);
        $page = Validator::int($_GET['page'] ?? 1, 'page');
        $stmt = pdo()->prepare(
            'SELECT id FROM book_files
             WHERE book_id=:book_id AND status="active" AND file_type="pdf"
             ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute([':book_id' => $bookId]);
        $fileId = (int)($stmt->fetchColumn() ?: 0);
        if (!$fileId) {
            Response::error('This book has no PDF page image to render', 404);
        }
        $file = stored_book_file($fileId);
        $image = render_pdf_page_image($file['absolute_path'], $page);
        if (!$image) {
            Response::error('This page image could not be rendered', 422);
        }
        serve_image_file($image);
    }

    if ($method === 'GET' && $path === '/search/books') {
        $user = current_user(false);
        $q = trim((string)($_GET['q'] ?? ''));
        $parsedQuery = parse_catalog_query($q);
        $searchTerms = $parsedQuery['keywords'] !== '' ? $parsedQuery['keywords'] : $q;
        $like = "%{$searchTerms}%";
        $authorLike = '%' . ($parsedQuery['author'] !== '' ? $parsedQuery['author'] : $searchTerms) . '%';
        $availability = (string)($_GET['availability'] ?? $parsedQuery['availability']);
        $availabilitySql = match ($availability) {
            'available' => ' AND b.available_copies > 0',
            'unavailable' => ' AND b.available_copies = 0',
            default => '',
        };
        $filterSql = '';
        $filterParams = [];
        foreach (['faculty_id', 'department_id', 'category_id', 'course_id'] as $filter) {
            if (isset($_GET[$filter]) && $_GET[$filter] !== '') {
                $filterSql .= " AND b.{$filter}=:{$filter}";
                $filterParams[":{$filter}"] = Validator::int($_GET[$filter], $filter);
            }
        }
        foreach (['language', 'access_level', 'license_type', 'reading_level'] as $filter) {
            if (isset($_GET[$filter]) && $_GET[$filter] !== '') {
                $filterSql .= " AND b.{$filter}=:{$filter}";
                $filterParams[":{$filter}"] = trim((string)$_GET[$filter]);
            }
        }
        foreach (['has_audio', 'has_translation', 'has_summary'] as $filter) {
            if (isset($_GET[$filter]) && $_GET[$filter] !== '') {
                $filterSql .= " AND b.{$filter}=:{$filter}";
                $filterParams[":{$filter}"] = !empty($_GET[$filter]) && $_GET[$filter] !== '0' ? 1 : 0;
            }
        }
        $orderBy = match ((string)($_GET['sort'] ?? '')) {
            'title' => 'b.title ASC',
            'year' => 'b.publication_year DESC, b.title ASC',
            'metadata' => 'b.metadata_quality_score DESC, relevance_score DESC, b.created_at DESC',
            default => 'relevance_score DESC, b.created_at DESC',
        };
        $sql = str_replace(
            'FROM books b',
            ', (CASE
                WHEN b.title = :title_exact THEN 100
                WHEN b.title LIKE :rank_title THEN 70
                WHEN a.full_name LIKE :rank_author THEN 60
                WHEN cat.name LIKE :rank_category THEN 50
                WHEN c.name LIKE :rank_course THEN 45
                WHEN d.name LIKE :rank_department THEN 40
                WHEN f.name LIKE :rank_faculty THEN 35
                WHEN b.keywords LIKE :rank_keywords THEN 30
                WHEN b.tags LIKE :rank_tags THEN 25
                WHEN b.description LIKE :rank_description THEN 20
                ELSE 0 END) AS relevance_score
             FROM books b',
            book_select_sql()
        ) . " WHERE b.status='active'
            AND (:q = '' OR b.title LIKE :like_title OR a.full_name LIKE :like_author OR b.isbn LIKE :like_isbn
                 OR b.keywords LIKE :like_keywords OR b.tags LIKE :like_tags OR b.description LIKE :like_description
                 OR cat.name LIKE :like_category OR c.name LIKE :like_course
                 OR d.name LIKE :like_department OR f.name LIKE :like_faculty)
            {$availabilitySql}{$filterSql} ORDER BY {$orderBy} LIMIT 50";
        $stmt = pdo()->prepare($sql);
        $stmt->execute([
            ':q' => $searchTerms,
            ':title_exact' => $searchTerms,
            ':rank_title' => $like,
            ':rank_author' => $authorLike,
            ':rank_category' => $like,
            ':rank_course' => $like,
            ':rank_department' => $like,
            ':rank_faculty' => $like,
            ':rank_keywords' => $like,
            ':rank_tags' => $like,
            ':rank_description' => $like,
            ':like_title' => $like,
            ':like_author' => $authorLike,
            ':like_isbn' => $like,
            ':like_keywords' => $like,
            ':like_tags' => $like,
            ':like_description' => $like,
            ':like_category' => $like,
            ':like_course' => $like,
            ':like_department' => $like,
            ':like_faculty' => $like,
        ] + $filterParams);
        $rows = $stmt->fetchAll();
        $contentMatches = [];
        if ($searchTerms !== '') {
            try {
                $contentStmt = pdo()->prepare(
                    str_replace('FROM books b', ', bpi.page_number, bpi.chunk_number, bpi.content_text FROM books b', book_select_sql()) . '
                    JOIN book_page_index bpi ON bpi.book_id=b.id
                    WHERE b.status="active"' . $availabilitySql . $filterSql . '
                      AND MATCH(bpi.content_text) AGAINST (:content_query IN BOOLEAN MODE)
                    ORDER BY MATCH(bpi.content_text) AGAINST (:content_query_rank IN BOOLEAN MODE) DESC, bpi.page_number ASC
                    LIMIT 20'
                );
                $terms = preg_split('/\s+/', $searchTerms, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $booleanQuery = implode(' ', array_map(fn($term) => '+' . preg_replace('/[^[:alnum:]_]/u', '', $term) . '*', $terms));
                $contentStmt->execute([
                    ':content_query' => $booleanQuery ?: $searchTerms,
                    ':content_query_rank' => $booleanQuery ?: $searchTerms,
                ] + $filterParams);
                $contentMatches = array_map(function ($row) {
                    $snippet = mb_substr((string)($row['content_text'] ?? ''), 0, 420);
                    unset($row['content_text']);
                    $row['snippet'] = $snippet;
                    return $row;
                }, $contentStmt->fetchAll());
            } catch (Throwable $contentSearchError) {
                error_log('[Rwanda Library content search] ' . $contentSearchError->getMessage());
            }
        }
        $totalResults = count($rows) + count($contentMatches);
        pdo()->prepare('INSERT INTO search_logs (user_id, query_text, filters, results_count, status) VALUES (:user_id, :query_text, :filters, :results_count, :status)')
            ->execute([
                ':user_id' => $user['id'] ?? null,
                ':query_text' => $q,
                ':filters' => json_encode(['request' => $_GET, 'parsed' => $parsedQuery, 'content_matches' => count($contentMatches)]),
                ':results_count' => $totalResults,
                ':status' => $totalResults ? 'success' : 'no_results',
            ]);
        Response::ok(['results' => $rows, 'content_matches' => $contentMatches, 'parsed_query' => $parsedQuery]);
    }

    if ($method === 'GET' && $path === '/search/autocomplete') {
        $q = trim((string)($_GET['q'] ?? ''));
        if (mb_strlen($q) < 2) {
            Response::ok(['query' => $q, 'suggestions' => []]);
        }
        $like = '%' . $q . '%';
        $suggestions = [];
        $bookStmt = pdo()->prepare(
            'SELECT "title" AS type, b.title AS label, b.id AS book_id, a.full_name AS context
             FROM books b LEFT JOIN authors a ON a.id=b.author_id
             WHERE b.status="active" AND b.title LIKE :q
             ORDER BY CASE WHEN b.title LIKE :prefix THEN 0 ELSE 1 END, b.title LIMIT 8'
        );
        $bookStmt->execute([':q' => $like, ':prefix' => $q . '%']);
        $suggestions = array_merge($suggestions, $bookStmt->fetchAll());

        $authorStmt = pdo()->prepare(
            'SELECT "author" AS type, a.full_name AS label, NULL AS book_id, COUNT(b.id) AS context
             FROM authors a JOIN books b ON b.author_id=a.id AND b.status="active"
             WHERE a.full_name LIKE :q GROUP BY a.id, a.full_name ORDER BY a.full_name LIMIT 6'
        );
        $authorStmt->execute([':q' => $like]);
        $suggestions = array_merge($suggestions, $authorStmt->fetchAll());

        $metadataStmt = pdo()->prepare(
            'SELECT "category" AS type, name AS label, NULL AS book_id, "collection" AS context FROM categories WHERE status="active" AND name LIKE :q_category
             UNION ALL SELECT "faculty" AS type, name AS label, NULL AS book_id, "faculty" AS context FROM faculties WHERE status="active" AND name LIKE :q_faculty
             UNION ALL SELECT "department" AS type, name AS label, NULL AS book_id, "department" AS context FROM departments WHERE status="active" AND name LIKE :q_department
             UNION ALL SELECT "course" AS type, name AS label, NULL AS book_id, "course" AS context FROM courses WHERE status="active" AND name LIKE :q_course
             LIMIT 10'
        );
        $metadataStmt->execute([':q_category' => $like, ':q_faculty' => $like, ':q_department' => $like, ':q_course' => $like]);
        $suggestions = array_merge($suggestions, $metadataStmt->fetchAll());

        $queryStmt = pdo()->prepare(
            'SELECT "popular_query" AS type, query_text AS label, NULL AS book_id, COUNT(*) AS context
             FROM search_logs WHERE query_text LIKE :q AND status <> "failed"
             GROUP BY query_text ORDER BY COUNT(*) DESC LIMIT 6'
        );
        $queryStmt->execute([':q' => $like]);
        $suggestions = array_merge($suggestions, $queryStmt->fetchAll());
        Response::ok(['query' => $q, 'suggestions' => array_slice($suggestions, 0, 20)]);
    }

    if (preg_match('#^/books/(\d+)/search$#', $path, $m) && $method === 'GET') {
        current_user();
        $bookId = Validator::int($m[1]);
        fetch_book($bookId);
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q === '') {
            Response::error('Search query is required', 422);
        }
        $limit = min(max(Validator::int($_GET['limit'] ?? 20, 'limit'), 1), 50);
        $terms = preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $booleanQuery = implode(' ', array_map(fn($term) => '+' . preg_replace('/[^[:alnum:]_]/u', '', $term) . '*', $terms));
        $stmt = pdo()->prepare(
            'SELECT bpi.id AS chunk_id, bpi.book_id, bpi.file_id, bpi.page_number, bpi.chunk_number,
                    bpi.language, bpi.keywords, bpi.summary, bpi.character_count, bpi.token_count,
                    MATCH(bpi.content_text) AGAINST (:query_rank IN BOOLEAN MODE) AS relevance_score,
                    bpi.content_text
             FROM book_page_index bpi
             WHERE bpi.book_id=:book_id AND MATCH(bpi.content_text) AGAINST (:query IN BOOLEAN MODE)
             ORDER BY relevance_score DESC, bpi.page_number ASC, bpi.chunk_number ASC
             LIMIT ' . $limit
        );
        $stmt->execute([
            ':book_id' => $bookId,
            ':query' => $booleanQuery ?: $q,
            ':query_rank' => $booleanQuery ?: $q,
        ]);
        $matches = array_map(function ($row) {
            $row['snippet'] = mb_substr((string)$row['content_text'], 0, 700);
            unset($row['content_text']);
            return $row;
        }, $stmt->fetchAll());
        Response::ok(['book_id' => $bookId, 'query' => $q, 'matches' => $matches]);
    }

    if ($method === 'GET' && $path === '/ai/retrieve') {
        $user = current_user(false);
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q === '') {
            Response::error('Retrieval query is required', 422);
        }
        $bookId = !empty($_GET['book_id']) ? Validator::int($_GET['book_id'], 'book_id') : null;
        $limit = min(max(Validator::int($_GET['limit'] ?? 8, 'limit'), 1), 20);
        $terms = preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $booleanQuery = implode(' ', array_map(fn($term) => '+' . preg_replace('/[^[:alnum:]_]/u', '', $term) . '*', $terms));
        $bookSql = $bookId ? ' AND bpi.book_id=:book_id' : '';
        $stmt = pdo()->prepare(
            'SELECT b.id AS book_id, b.title, a.full_name AS author, bpi.id AS chunk_id, bpi.file_id,
                    bpi.page_number, bpi.chunk_number, bpi.language, bpi.summary, bpi.keywords,
                    MATCH(bpi.content_text) AGAINST (:query_rank IN BOOLEAN MODE) AS relevance_score,
                    bpi.content_text
             FROM book_page_index bpi
             JOIN books b ON b.id=bpi.book_id
             LEFT JOIN authors a ON a.id=b.author_id
             WHERE b.status="active"' . $bookSql . ' AND MATCH(bpi.content_text) AGAINST (:query IN BOOLEAN MODE)
             ORDER BY relevance_score DESC, bpi.page_number ASC
             LIMIT ' . $limit
        );
        $params = [':query' => $booleanQuery ?: $q, ':query_rank' => $booleanQuery ?: $q];
        if ($bookId) {
            $params[':book_id'] = $bookId;
        }
        $stmt->execute($params);
        $chunks = array_map(function ($row) {
            $row['content'] = mb_substr((string)$row['content_text'], 0, 1800);
            unset($row['content_text']);
            return $row;
        }, $stmt->fetchAll());
        try {
            pdo()->prepare(
                'INSERT INTO ai_usage_logs (institution_id, user_id, service_name, provider, model_name, input_units, output_units, status, metadata)
                 VALUES (:institution_id, :user_id, "other", "database_fulltext", "book_page_index", :input_units, :output_units, "success", :metadata)'
            )->execute([
                ':institution_id' => class_exists('TenantService') ? TenantService::institutionIdFor($user) : null,
                ':user_id' => $user['id'] ?? null,
                ':input_units' => mb_strlen($q),
                ':output_units' => count($chunks),
                ':metadata' => json_encode(['book_id' => $bookId, 'limit' => $limit]),
            ]);
        } catch (Throwable) {
        }
        Response::ok(['query' => $q, 'book_id' => $bookId, 'chunks' => $chunks, 'retrieval_first' => true]);
    }    if ($method === 'GET' && in_array($path, ['/faculties', '/departments', '/courses', '/categories', '/authors'], true)) {
        $includeArchived = !empty($_GET['include_archived']);
        if ($path === '/departments') {
            $conditions = $includeArchived ? [] : ['d.status="active"'];
            $params = [];
            if (!empty($_GET['faculty_id'])) {
                $conditions[] = 'd.faculty_id=:faculty_id';
                $params[':faculty_id'] = Validator::int($_GET['faculty_id'], 'faculty_id');
            }
            $stmt = pdo()->prepare(
                'SELECT d.*, f.name AS faculty_name, f.code AS faculty_code
                 FROM departments d JOIN faculties f ON f.id=d.faculty_id' .
                ($conditions ? ' WHERE ' . implode(' AND ', $conditions) : '') .
                ' ORDER BY f.name, d.name'
            );
            $stmt->execute($params);
            Response::ok($stmt->fetchAll());
        }
        if ($path === '/courses') {
            $condition = $includeArchived ? '' : ' WHERE c.status="active"';
            $stmt = pdo()->query(
                'SELECT c.*, d.name AS department_name, f.name AS faculty_name
                 FROM courses c
                 JOIN departments d ON d.id=c.department_id
                 JOIN faculties f ON f.id=d.faculty_id' .
                $condition . ' ORDER BY f.name, d.name, c.name'
            );
            Response::ok($stmt->fetchAll());
        }
        $table = trim($path, '/');
        $orderColumn = $table === 'authors' ? 'full_name' : 'name';
        $condition = $includeArchived ? '' : " WHERE status='active'";
        $stmt = pdo()->query("SELECT * FROM {$table}{$condition} ORDER BY {$orderColumn}");
        Response::ok($stmt->fetchAll());
    }

    if ($method === 'POST' && in_array($path, ['/faculties', '/departments', '/courses', '/categories', '/authors'], true)) {
        $user = current_user();
        require_role($user, admin_roles());
        $data = body();
        Validator::require($data, [$path === '/authors' ? 'full_name' : 'name']);
        if ($path === '/faculties') {
            pdo()->prepare('INSERT INTO faculties (name, code, description) VALUES (:name, :code, :description)')->execute([':name' => $data['name'], ':code' => $data['code'] ?? null, ':description' => $data['description'] ?? null]);
        } elseif ($path === '/departments') {
            pdo()->prepare('INSERT INTO departments (faculty_id, name, code, description) VALUES (:faculty_id, :name, :code, :description)')->execute([':faculty_id' => Validator::int($data['faculty_id'], 'faculty_id'), ':name' => $data['name'], ':code' => $data['code'] ?? null, ':description' => $data['description'] ?? null]);
        } elseif ($path === '/courses') {
            pdo()->prepare('INSERT INTO courses (department_id, name, code, description) VALUES (:department_id, :name, :code, :description)')->execute([':department_id' => Validator::int($data['department_id'], 'department_id'), ':name' => $data['name'], ':code' => $data['code'] ?? null, ':description' => $data['description'] ?? null]);
        } elseif ($path === '/categories') {
            pdo()->prepare('INSERT INTO categories (name, description) VALUES (:name, :description)')
                ->execute([':name' => $data['name'], ':description' => $data['description'] ?? null]);
        } else {
            pdo()->prepare('INSERT INTO authors (full_name, biography) VALUES (:full_name, :biography)')
                ->execute([':full_name' => $data['full_name'], ':biography' => $data['biography'] ?? null]);
        }
        Response::ok(['id' => (int)pdo()->lastInsertId()], 'Academic record created');
    }

    if (preg_match('#^/(faculties|departments|courses)/(\d+)$#', $path, $m) && in_array($method, ['PUT', 'DELETE'], true)) {
        $user = current_user();
        require_role($user, admin_roles());
        $table = $m[1];
        $entityName = match ($table) {
            'faculties' => 'faculty',
            'departments' => 'department',
            default => 'course',
        };
        $id = Validator::int($m[2]);
        if ($method === 'DELETE') {
            pdo()->prepare("UPDATE {$table} SET status='archived' WHERE id=:id")->execute([':id' => $id]);
            ActivityLogService::log($user, 'archive_' . $entityName, 'success', $table, $id);
            Response::ok(['id' => $id], 'Academic record archived');
        }
        $data = body();
        Validator::require($data, ['name']);
        if ($table === 'faculties') {
            pdo()->prepare('UPDATE faculties SET name=:name, code=:code, description=:description, status=:status WHERE id=:id')
                ->execute([
                    ':name' => trim((string)$data['name']),
                    ':code' => trim((string)($data['code'] ?? '')) ?: null,
                    ':description' => $data['description'] ?? null,
                    ':status' => $data['status'] ?? 'active',
                    ':id' => $id,
                ]);
        } elseif ($table === 'departments') {
            pdo()->prepare('UPDATE departments SET faculty_id=:parent_id, name=:name, code=:code, description=:description, status=:status WHERE id=:id')
                ->execute([
                    ':parent_id' => Validator::int($data['faculty_id'] ?? null, 'faculty_id'),
                    ':name' => trim((string)$data['name']),
                    ':code' => trim((string)($data['code'] ?? '')) ?: null,
                    ':description' => $data['description'] ?? null,
                    ':status' => $data['status'] ?? 'active',
                    ':id' => $id,
                ]);
        } else {
            pdo()->prepare('UPDATE courses SET department_id=:parent_id, name=:name, code=:code, description=:description, status=:status WHERE id=:id')
                ->execute([
                    ':parent_id' => Validator::int($data['department_id'] ?? null, 'department_id'),
                    ':name' => trim((string)$data['name']),
                    ':code' => trim((string)($data['code'] ?? '')) ?: null,
                    ':description' => $data['description'] ?? null,
                    ':status' => $data['status'] ?? 'active',
                    ':id' => $id,
                ]);
        }
        ActivityLogService::log($user, 'update_' . $entityName, 'success', $table, $id);
        Response::ok(['id' => $id], 'Academic record updated');
    }

    if ($method === 'POST' && $path === '/academic/book-courses') {
        $user = current_user();
        require_role($user, ['LECTURER', 'LIBRARIAN_ADMIN']);
        $data = body();
        $bookId = Validator::int($data['book_id'] ?? null, 'book_id');
        $courseId = Validator::int($data['course_id'] ?? null, 'course_id');
        pdo()->prepare(
            'INSERT INTO book_courses (book_id, course_id, status) VALUES (:book_id, :course_id, "active")
             ON DUPLICATE KEY UPDATE status="active"'
        )->execute([':book_id' => $bookId, ':course_id' => $courseId]);
        Response::ok(['book_id' => $bookId, 'course_id' => $courseId], 'Book assigned to course');
    }

    if ($method === 'POST' && $path === '/academic/lecturer-courses') {
        $user = current_user();
        require_role($user, admin_roles());
        $data = body();
        $lecturerId = Validator::int($data['lecturer_id'] ?? null, 'lecturer_id');
        $courseId = Validator::int($data['course_id'] ?? null, 'course_id');
        $role = pdo()->prepare('SELECT r.code FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=:id AND u.status="active"');
        $role->execute([':id' => $lecturerId]);
        if ($role->fetchColumn() !== 'LECTURER') {
            Response::error('Selected user is not an active lecturer', 422);
        }
        pdo()->prepare(
            'INSERT INTO lecturer_courses (lecturer_id, course_id, status) VALUES (:lecturer_id, :course_id, "active")
             ON DUPLICATE KEY UPDATE status="active"'
        )->execute([':lecturer_id' => $lecturerId, ':course_id' => $courseId]);
        Response::ok(['lecturer_id' => $lecturerId, 'course_id' => $courseId], 'Lecturer assigned to course');
    }

    if (preg_match('#^/academic/(faculties|departments|courses)/(\d+)/books$#', $path, $m) && $method === 'GET') {
        $column = match ($m[1]) {
            'faculties' => 'faculty_id',
            'departments' => 'department_id',
            default => 'course_id',
        };
        $stmt = pdo()->prepare(book_select_sql() . " WHERE b.status='active' AND b.{$column}=:id ORDER BY b.title");
        $stmt->execute([':id' => Validator::int($m[2])]);
        Response::ok($stmt->fetchAll());
    }

    if ($method === 'POST' && $path === '/borrow/request') {
        $user = current_user();
        require_role($user, ['STUDENT']);
        $data = body();
        $bookId = Validator::int($data['book_id'] ?? null, 'book_id');
        $book = fetch_book($bookId);
        if ($book['status'] !== 'active') {
            Response::error('This book is not available for borrowing', 409);
        }
        $existing = pdo()->prepare(
            'SELECT id FROM borrow_requests
             WHERE user_id=:user_id AND book_id=:book_id AND status IN ("pending","approved","overdue") LIMIT 1'
        );
        $existing->execute([':user_id' => $user['id'], ':book_id' => $bookId]);
        if ($existing->fetch()) {
            Response::error('An active borrow request already exists for this book', 409);
        }
        $stmt = pdo()->prepare('INSERT INTO borrow_requests (user_id, book_id) VALUES (:user_id, :book_id)');
        $stmt->execute([':user_id' => $user['id'], ':book_id' => $bookId]);
        $requestId = (int)pdo()->lastInsertId();
        ActivityLogService::log($user, 'borrow_request', 'success', 'book', $bookId);
        Response::ok(['id' => $requestId], 'Borrow request created');
    }

    if ($method === 'GET' && $path === '/borrow/my-books') {
        $user = current_user();
        $stmt = pdo()->prepare('SELECT br.*, b.title AS book_title FROM borrow_requests br JOIN books b ON b.id=br.book_id WHERE br.user_id=:user_id ORDER BY br.created_at DESC');
        $stmt->execute([':user_id' => $user['id']]);
        Response::ok($stmt->fetchAll());
    }

    if ($method === 'GET' && $path === '/borrow') {
        $user = current_user();
        require_role($user, admin_roles());
        $stmt = pdo()->query('SELECT br.*, b.title AS book_title, u.full_name AS user_name FROM borrow_requests br JOIN books b ON b.id=br.book_id JOIN users u ON u.id=br.user_id ORDER BY br.created_at DESC');
        Response::ok($stmt->fetchAll());
    }

    if (preg_match('#^/borrow/(\d+)/(approve|reject|return|renew)$#', $path, $m) && $method === 'PATCH') {
        $user = current_user();
        require_role($user, admin_roles());
        $id = Validator::int($m[1]);
        $action = $m[2];
        $data = body();
        try {
            pdo()->beginTransaction();
            $requestStmt = pdo()->prepare('SELECT * FROM borrow_requests WHERE id=:id FOR UPDATE');
            $requestStmt->execute([':id' => $id]);
            $request = $requestStmt->fetch();
            if (!$request) {
                throw new RuntimeException('Borrow request not found', 404);
            }
            fetch_book((int)$request['book_id'], true);
            if ($action === 'approve') {
                if ($request['status'] !== 'pending') {
                    throw new RuntimeException('Only pending requests can be approved', 409);
                }
                $inventory = pdo()->prepare(
                    'UPDATE books SET available_copies=available_copies-1
                     WHERE id=:book_id AND status="active" AND available_copies > 0'
                );
                $inventory->execute([':book_id' => $request['book_id']]);
                if ($inventory->rowCount() !== 1) {
                    throw new RuntimeException('No available copy remains for this book', 409);
                }
                pdo()->prepare(
                    'UPDATE borrow_requests SET status="approved", approved_by=:user_id, approved_at=NOW(),
                     due_date=DATE_ADD(CURDATE(), INTERVAL 14 DAY) WHERE id=:id'
                )->execute([':user_id' => $user['id'], ':id' => $id]);
            } elseif ($action === 'reject') {
                if ($request['status'] !== 'pending') {
                    throw new RuntimeException('Only pending requests can be rejected', 409);
                }
                pdo()->prepare(
                    'UPDATE borrow_requests SET status="rejected", rejected_by=:user_id, rejected_at=NOW(),
                     rejection_reason=:reason WHERE id=:id'
                )->execute([':user_id' => $user['id'], ':reason' => $data['reason'] ?? null, ':id' => $id]);
            } elseif ($action === 'return') {
                if (!in_array($request['status'], ['approved', 'overdue'], true)) {
                    throw new RuntimeException('Only active loans can be returned', 409);
                }
                pdo()->prepare('UPDATE borrow_requests SET status="returned", returned_at=NOW() WHERE id=:id')
                    ->execute([':id' => $id]);
                pdo()->prepare(
                    'UPDATE books SET available_copies=LEAST(total_copies, available_copies+1) WHERE id=:book_id'
                )->execute([':book_id' => $request['book_id']]);
            } else {
                if (!in_array($request['status'], ['approved', 'overdue'], true) || !$request['due_date']) {
                    throw new RuntimeException('Only active loans with a due date can be renewed', 409);
                }
                pdo()->prepare(
                    'UPDATE borrow_requests SET status="approved", renewed_until=DATE_ADD(due_date, INTERVAL 7 DAY),
                     due_date=DATE_ADD(due_date, INTERVAL 7 DAY) WHERE id=:id'
                )->execute([':id' => $id]);
            }
            pdo()->prepare(
                'INSERT INTO borrowing_history (borrow_request_id, user_id, book_id, action, action_by, notes)
                 VALUES (:request_id, :user_id, :book_id, :action, :action_by, :notes)'
            )->execute([
                ':request_id' => $id,
                ':user_id' => $request['user_id'],
                ':book_id' => $request['book_id'],
                ':action' => $action,
                ':action_by' => $user['id'],
                ':notes' => $data['reason'] ?? null,
            ]);
            if (in_array($action, ['approve', 'reject'], true)) {
                pdo()->prepare(
                    'INSERT INTO notifications (user_id, created_by, type, title, message)
                     VALUES (:user_id, :created_by, :type, :title, :message)'
                )->execute([
                    ':user_id' => $request['user_id'],
                    ':created_by' => $user['id'],
                    ':type' => $action === 'approve' ? 'borrow_approved' : 'borrow_rejected',
                    ':title' => $action === 'approve' ? 'Borrow request approved' : 'Borrow request rejected',
                    ':message' => $action === 'approve'
                        ? 'Your requested book is ready. Check your borrowed books for the due date.'
                        : (string)($data['reason'] ?? 'Your borrow request was rejected.'),
                ]);
            }
            pdo()->commit();
        } catch (RuntimeException $e) {
            if (pdo()->inTransaction()) {
                pdo()->rollBack();
            }
            Response::error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 409);
        } catch (Throwable $e) {
            if (pdo()->inTransaction()) {
                pdo()->rollBack();
            }
            throw $e;
        }
        Response::ok(['id' => $id], 'Borrowing updated');
    }

    if ($method === 'GET' && $path === '/borrow/overdue') {
        $user = current_user();
        require_role($user, admin_roles());
        $stmt = pdo()->query('SELECT br.*, b.title AS book_title, u.full_name AS user_name FROM borrow_requests br JOIN books b ON b.id=br.book_id JOIN users u ON u.id=br.user_id WHERE br.status="approved" AND br.due_date < CURDATE()');
        Response::ok($stmt->fetchAll());
    }

    if ($method === 'GET' && $path === '/progress') {
        $user = current_user();
        $stmt = pdo()->prepare('SELECT rp.*, b.title AS book_title FROM reading_progress rp JOIN books b ON b.id=rp.book_id WHERE rp.user_id=:user_id');
        $stmt->execute([':user_id' => $user['id']]);
        Response::ok($stmt->fetchAll());
    }

    if ($method === 'POST' && $path === '/progress') {
        $user = current_user();
        $data = body();
        $stmt = pdo()->prepare('INSERT INTO reading_progress (user_id, book_id, last_page, last_section, progress_percentage, total_reading_time_seconds, completed_status) VALUES (:user_id, :book_id, :last_page, :last_section, :progress_percentage, :time, :completed_status) ON DUPLICATE KEY UPDATE last_page=VALUES(last_page), last_section=VALUES(last_section), progress_percentage=VALUES(progress_percentage), total_reading_time_seconds=VALUES(total_reading_time_seconds), completed_status=VALUES(completed_status)');
        $stmt->execute([':user_id' => $user['id'], ':book_id' => Validator::int($data['book_id'] ?? null, 'book_id'), ':last_page' => (int)($data['last_page'] ?? 0), ':last_section' => $data['last_section'] ?? null, ':progress_percentage' => (float)($data['progress_percentage'] ?? 0), ':time' => (int)($data['total_reading_time_seconds'] ?? 0), ':completed_status' => $data['completed_status'] ?? 'in_progress']);
        Response::ok(null, 'Progress saved');
    }

    if (preg_match('#^/progress/(\d+)$#', $path, $m)) {
        $user = current_user();
        $bookId = Validator::int($m[1], 'book_id');
        if ($method === 'GET') {
            $stmt = pdo()->prepare(
                'SELECT rp.*, b.title AS book_title FROM reading_progress rp
                 JOIN books b ON b.id=rp.book_id WHERE rp.user_id=:user_id AND rp.book_id=:book_id'
            );
            $stmt->execute([':user_id' => $user['id'], ':book_id' => $bookId]);
            Response::ok($stmt->fetch());
        }
        if (in_array($method, ['PATCH', 'PUT'], true)) {
            $data = body();
            $progress = max(0, min(100, (float)($data['progress_percentage'] ?? $data['progress'] ?? 0)));
            $completed = $data['completed_status'] ?? ($progress >= 100 ? 'completed' : ($progress > 0 ? 'in_progress' : 'not_started'));
            pdo()->prepare(
                'INSERT INTO reading_progress
                 (user_id, book_id, last_page, last_section, progress_percentage, total_reading_time_seconds, completed_status, status)
                 VALUES (:user_id, :book_id, :last_page, :last_section, :progress, :time, :completed, "active")
                 ON DUPLICATE KEY UPDATE last_page=VALUES(last_page), last_section=VALUES(last_section),
                 progress_percentage=VALUES(progress_percentage),
                 total_reading_time_seconds=total_reading_time_seconds + VALUES(total_reading_time_seconds),
                 completed_status=VALUES(completed_status), status="active"'
            )->execute([
                ':user_id' => $user['id'],
                ':book_id' => $bookId,
                ':last_page' => max(0, (int)($data['last_page'] ?? $data['page'] ?? 0)),
                ':last_section' => $data['last_section'] ?? $data['section'] ?? null,
                ':progress' => $progress,
                ':time' => max(0, (int)($data['total_reading_time_seconds'] ?? $data['reading_time_seconds'] ?? 0)),
                ':completed' => $completed,
            ]);
            Response::ok(['book_id' => $bookId, 'progress_percentage' => $progress], 'Progress updated');
        }
        if ($method === 'DELETE') {
            pdo()->prepare(
                'UPDATE reading_progress SET last_page=0, last_section=NULL, progress_percentage=0,
                 total_reading_time_seconds=0, completed_status="not_started", status="reset"
                 WHERE user_id=:user_id AND book_id=:book_id'
            )->execute([':user_id' => $user['id'], ':book_id' => $bookId]);
            Response::ok(['book_id' => $bookId], 'Progress reset');
        }
    }

    if ($method === 'GET' && $path === '/favorites') {
        $user = current_user();
        $stmt = pdo()->prepare('SELECT f.*, b.title, b.cover_image FROM favorites f JOIN books b ON b.id=f.book_id WHERE f.user_id=:user_id AND f.status="active"');
        $stmt->execute([':user_id' => $user['id']]);
        Response::ok($stmt->fetchAll());
    }

    if ($method === 'POST' && $path === '/favorites') {
        $user = current_user();
        $bookId = Validator::int(body()['book_id'] ?? null, 'book_id');
        pdo()->prepare('INSERT INTO favorites (user_id, book_id) VALUES (:user_id, :book_id) ON DUPLICATE KEY UPDATE status="active"')->execute([':user_id' => $user['id'], ':book_id' => $bookId]);
        Response::ok(null, 'Favorite added');
    }

    if ($method === 'POST' && $path === '/favorites/toggle') {
        $user = current_user();
        $data = body();
        $bookId = Validator::int($data['book_id'] ?? $data['bookId'] ?? null, 'book_id');
        $stmt = pdo()->prepare('SELECT status FROM favorites WHERE user_id=:user_id AND book_id=:book_id');
        $stmt->execute([':user_id' => $user['id'], ':book_id' => $bookId]);
        $current = $stmt->fetchColumn();
        $next = $current === 'active' ? 'removed' : 'active';
        pdo()->prepare(
            'INSERT INTO favorites (user_id, book_id, status) VALUES (:user_id, :book_id, :status)
             ON DUPLICATE KEY UPDATE status=VALUES(status)'
        )->execute([':user_id' => $user['id'], ':book_id' => $bookId, ':status' => $next]);
        Response::ok(['book_id' => $bookId, 'favorite' => $next === 'active'], 'Favorite updated');
    }

    if (preg_match('#^/favorites/(\d+)$#', $path, $m) && $method === 'DELETE') {
        $user = current_user();
        pdo()->prepare('UPDATE favorites SET status="removed" WHERE user_id=:user_id AND book_id=:book_id')->execute([':user_id' => $user['id'], ':book_id' => Validator::int($m[1], 'book_id')]);
        Response::ok(null, 'Favorite removed');
    }

    if ($method === 'GET' && $path === '/bookmarks') {
        $user = current_user();
        $stmt = pdo()->prepare('SELECT bm.*, b.title FROM bookmarks bm JOIN books b ON b.id=bm.book_id WHERE bm.user_id=:user_id AND bm.status="active"');
        $stmt->execute([':user_id' => $user['id']]);
        Response::ok($stmt->fetchAll());
    }

    if ($method === 'POST' && $path === '/bookmarks') {
        $user = current_user();
        $data = body();
        $bookId = Validator::int($data['book_id'] ?? null, 'book_id');
        $database = pdo();
        $database->prepare('INSERT INTO bookmarks (user_id, book_id, page_number, section, note) VALUES (:user_id, :book_id, :page_number, :section, :note)')
            ->execute([':user_id' => $user['id'], ':book_id' => $bookId, ':page_number' => $data['page_number'] ?? null, ':section' => $data['section'] ?? null, ':note' => $data['note'] ?? null]);
        Response::ok(['id' => (int) $database->lastInsertId(), 'book_id' => $bookId], 'Bookmark added');
    }

    if (preg_match('#^/bookmarks/(\d+)$#', $path, $m) && $method === 'DELETE') {
        $user = current_user();
        pdo()->prepare('UPDATE bookmarks SET status="removed" WHERE id=:id AND user_id=:user_id')
            ->execute([':id' => Validator::int($m[1]), ':user_id' => $user['id']]);
        Response::ok(null, 'Bookmark removed');
    }

    if (preg_match('#^/ratings/(\d+)$#', $path, $m) && $method === 'GET') {
        $stmt = pdo()->prepare(
            'SELECT ROUND(AVG(rating),2) AS average_rating, COUNT(*) AS total_ratings
             FROM ratings WHERE book_id=:book_id AND status="active"'
        );
        $stmt->execute([':book_id' => Validator::int($m[1], 'book_id')]);
        Response::ok($stmt->fetch());
    }

    if ($method === 'POST' && $path === '/ratings') {
        $user = current_user();
        $data = body();
        Validator::require($data, ['rating']);
        $bookId = Validator::int($data['book_id'] ?? $data['bookId'] ?? null, 'book_id');
        $rating = bounded_int($data['rating'] ?? null, 0, 1, 5);
        pdo()->prepare(
            'INSERT INTO ratings (user_id, book_id, rating, status) VALUES (:user_id, :book_id, :rating, "active")
             ON DUPLICATE KEY UPDATE rating=VALUES(rating), status="active"'
        )->execute([':user_id' => $user['id'], ':book_id' => $bookId, ':rating' => $rating]);
        Response::ok(['book_id' => $bookId, 'rating' => $rating], 'Rating saved');
    }

    if ($method === 'GET' && $path === '/reviews') {
        $user = current_user();
        require_role($user, admin_roles());
        $status = trim((string)($_GET['status'] ?? ''));
        $condition = in_array($status, ['pending', 'approved', 'hidden', 'deleted'], true)
            ? ' WHERE rv.status=:status'
            : '';
        $stmt = pdo()->prepare(
            'SELECT rv.id, rv.book_id, rv.user_id, rv.review_text AS text, rv.status, rv.moderation_note,
                    rv.created_at, rv.moderated_at, u.full_name AS author, u.email, b.title AS book_title,
                    moderator.full_name AS moderator_name
             FROM reviews rv
             JOIN users u ON u.id=rv.user_id
             JOIN books b ON b.id=rv.book_id
             LEFT JOIN users moderator ON moderator.id=rv.moderated_by' .
             $condition . ' ORDER BY (rv.status="pending") DESC, rv.created_at DESC LIMIT 200'
        );
        $stmt->execute($condition ? [':status' => $status] : []);
        Response::ok($stmt->fetchAll());
    }

    if (preg_match('#^/reviews/(\d+)$#', $path, $m)) {
        $id = Validator::int($m[1]);
        if ($method === 'GET') {
            $stmt = pdo()->prepare(
                'SELECT rv.id, rv.user_id, rv.book_id, rv.review_text AS text, rv.status, rv.created_at,
                 u.full_name AS author, rt.rating
                 FROM reviews rv JOIN users u ON u.id=rv.user_id
                 LEFT JOIN ratings rt ON rt.user_id=rv.user_id AND rt.book_id=rv.book_id AND rt.status="active"
                 WHERE rv.book_id=:book_id AND rv.status="approved" ORDER BY rv.created_at DESC'
            );
            $stmt->execute([':book_id' => $id]);
            Response::ok($stmt->fetchAll());
        }
        $user = current_user();
        if ($method === 'PUT') {
            $data = body();
            Validator::require($data, ['review_text']);
            $stmt = pdo()->prepare(
                'UPDATE reviews SET review_text=:text, status="pending"
                 WHERE id=:id AND (user_id=:user_id OR :is_admin=1)'
            );
            $stmt->execute([
                ':text' => trim((string)$data['review_text']),
                ':id' => $id,
                ':user_id' => $user['id'],
                ':is_admin' => $user['role_code'] === 'LIBRARIAN_ADMIN' ? 1 : 0,
            ]);
            Response::ok(['id' => $id], 'Review updated');
        }
        if ($method === 'DELETE') {
            pdo()->prepare(
                'UPDATE reviews SET status="deleted" WHERE id=:id AND (user_id=:user_id OR :is_admin=1)'
            )->execute([
                ':id' => $id,
                ':user_id' => $user['id'],
                ':is_admin' => $user['role_code'] === 'LIBRARIAN_ADMIN' ? 1 : 0,
            ]);
            Response::ok(['id' => $id], 'Review deleted');
        }
    }

    if ($method === 'POST' && $path === '/reviews') {
        $user = current_user();
        $data = body();
        $bookId = Validator::int($data['book_id'] ?? $data['bookId'] ?? null, 'book_id');
        $text = trim((string)($data['review_text'] ?? $data['text'] ?? ''));
        if ($text === '') {
            Response::error('Review text is required', 422);
        }
        $stmt = pdo()->prepare(
            'INSERT INTO reviews (user_id, book_id, review_text, status) VALUES (:user_id, :book_id, :text, "pending")'
        );
        $stmt->execute([':user_id' => $user['id'], ':book_id' => $bookId, ':text' => $text]);
        $reviewId = (int)pdo()->lastInsertId();
        if (isset($data['rating'])) {
            $rating = bounded_int($data['rating'], 0, 1, 5);
            pdo()->prepare(
                'INSERT INTO ratings (user_id, book_id, rating, status) VALUES (:user_id, :book_id, :rating, "active")
                 ON DUPLICATE KEY UPDATE rating=VALUES(rating), status="active"'
            )->execute([':user_id' => $user['id'], ':book_id' => $bookId, ':rating' => $rating]);
        }
        Response::ok(['id' => $reviewId], 'Review submitted for moderation');
    }

    if (preg_match('#^/reviews/(\d+)/moderate$#', $path, $m) && $method === 'PATCH') {
        $user = current_user();
        require_role($user, admin_roles());
        $data = body();
        $status = (string)($data['status'] ?? '');
        if (!in_array($status, ['approved', 'hidden', 'deleted'], true)) {
            Response::error('Invalid moderation status', 422);
        }
        pdo()->prepare(
            'UPDATE reviews SET status=:status, moderated_by=:moderator, moderated_at=NOW(), moderation_note=:note WHERE id=:id'
        )->execute([
            ':status' => $status,
            ':moderator' => $user['id'],
            ':note' => $data['note'] ?? null,
            ':id' => Validator::int($m[1]),
        ]);
        Response::ok(['id' => (int)$m[1], 'status' => $status], 'Review moderated');
    }

    if ($method === 'GET' && $path === '/recommendations') {
        $user = current_user();
        $stmt = pdo()->prepare(
            book_select_sql() . '
            LEFT JOIN user_profiles up ON up.user_id=:profile_user_id
            LEFT JOIN (
                SELECT book_id, COUNT(*) AS borrow_count
                FROM borrow_requests WHERE status IN ("approved","returned","overdue") GROUP BY book_id
            ) popularity ON popularity.book_id=b.id
            LEFT JOIN recommendations rec ON rec.book_id=b.id AND rec.user_id=:recommendation_user_id AND rec.status="active"
            WHERE b.status="active"
            ORDER BY (
                CASE WHEN b.course_id IS NOT NULL AND b.course_id=up.course_id THEN 40 ELSE 0 END +
                CASE WHEN b.department_id IS NOT NULL AND b.department_id=up.department_id THEN 25 ELSE 0 END +
                CASE WHEN b.faculty_id IS NOT NULL AND b.faculty_id=up.faculty_id THEN 15 ELSE 0 END +
                CASE WHEN EXISTS (
                    SELECT 1 FROM borrow_requests preferred_borrow
                    JOIN books borrowed_book ON borrowed_book.id=preferred_borrow.book_id
                    WHERE preferred_borrow.user_id=:borrow_user_id
                      AND preferred_borrow.status IN ("approved","returned","overdue")
                      AND borrowed_book.category_id=b.category_id
                ) THEN 20 ELSE 0 END +
                CASE WHEN EXISTS (
                    SELECT 1 FROM favorites preferred_favorite
                    JOIN books favorite_book ON favorite_book.id=preferred_favorite.book_id
                    WHERE preferred_favorite.user_id=:favorite_user_id
                      AND preferred_favorite.status="active"
                      AND favorite_book.category_id=b.category_id
                ) THEN 15 ELSE 0 END +
                CASE WHEN EXISTS (
                    SELECT 1 FROM search_logs preferred_search
                    WHERE preferred_search.user_id=:search_user_id
                      AND preferred_search.status <> "failed"
                      AND (b.title LIKE CONCAT("%", preferred_search.query_text, "%")
                           OR b.keywords LIKE CONCAT("%", preferred_search.query_text, "%"))
                ) THEN 15 ELSE 0 END +
                COALESCE(rec.score, 0) + LEAST(COALESCE(popularity.borrow_count, 0), 20)
            ) DESC, b.created_at DESC
            LIMIT 20'
        );
        $stmt->execute([
            ':profile_user_id' => $user['id'],
            ':recommendation_user_id' => $user['id'],
            ':borrow_user_id' => $user['id'],
            ':favorite_user_id' => $user['id'],
            ':search_user_id' => $user['id'],
        ]);
        Response::ok($stmt->fetchAll());
    }

    if ($method === 'POST' && $path === '/recommendations') {
        $user = current_user();
        require_role($user, ['LECTURER', 'LIBRARIAN_ADMIN']);
        $data = body();
        $bookId = Validator::int($data['book_id'] ?? $data['bookId'] ?? null, 'book_id');
        $targetUser = optional_int($data['user_id'] ?? null, 'user_id');
        $sourceType = (string)($data['source_type'] ?? 'lecturer');
        $allowedSources = ['faculty', 'department', 'course', 'popular', 'search_history', 'borrowed_books', 'favorites', 'lecturer', 'similar'];
        if (!in_array($sourceType, $allowedSources, true)) {
            Response::error('Invalid recommendation source', 422);
        }
        pdo()->prepare(
            'INSERT INTO recommendations (user_id, book_id, source_type, source_id, score, status)
             VALUES (:user_id, :book_id, :source_type, :source_id, :score, "active")'
        )->execute([
            ':user_id' => $targetUser,
            ':book_id' => $bookId,
            ':source_type' => $sourceType,
            ':source_id' => optional_int($data['source_id'] ?? null, 'source_id'),
            ':score' => max(0, (float)($data['score'] ?? 50)),
        ]);
        $recommendationId = (int)pdo()->lastInsertId();
        if ($targetUser) {
            pdo()->prepare(
                'INSERT INTO notifications (user_id, created_by, type, title, message)
                 VALUES (:user_id, :created_by, "lecturer_recommendation", :title, :message)'
            )->execute([
                ':user_id' => $targetUser,
                ':created_by' => $user['id'],
                ':title' => 'New book recommendation',
                ':message' => (string)($data['message'] ?? 'A new book has been recommended for you.'),
            ]);
        }
        Response::ok(['id' => $recommendationId], 'Recommendation created');
    }

    if ($method === 'GET' && $path === '/recommendations/admin') {
        $user = current_user();
        require_role($user, admin_roles());
        $stmt = pdo()->query(
            'SELECT rec.id, rec.user_id, rec.book_id, rec.source_type, rec.source_id, rec.score, rec.status,
                    rec.created_at, b.title AS book_title, u.full_name AS user_name, u.email AS user_email
             FROM recommendations rec
             JOIN books b ON b.id=rec.book_id
             LEFT JOIN users u ON u.id=rec.user_id
             ORDER BY (rec.status="active") DESC, rec.created_at DESC LIMIT 200'
        );
        Response::ok($stmt->fetchAll());
    }

    if (preg_match('#^/recommendations/(\d+)$#', $path, $m) && $method === 'PATCH') {
        $user = current_user();
        require_role($user, admin_roles());
        $data = body();
        $status = (string)($data['status'] ?? '');
        if (!in_array($status, ['active', 'dismissed', 'expired'], true)) {
            Response::error('Invalid recommendation status', 422);
        }
        $id = Validator::int($m[1]);
        pdo()->prepare('UPDATE recommendations SET status=:status WHERE id=:id')
            ->execute([':status' => $status, ':id' => $id]);
        ActivityLogService::log($user, 'update_recommendation', 'success', 'recommendations', $id, ['status' => $status]);
        Response::ok(['id' => $id, 'status' => $status], 'Recommendation updated');
    }

    if ($method === 'GET' && $path === '/notifications') {
        $user = current_user();
        $stmt = pdo()->prepare(
            'SELECT * FROM notifications
             WHERE (user_id=:user_id OR user_id IS NULL) AND status="active" ORDER BY created_at DESC'
        );
        $stmt->execute([':user_id' => $user['id']]);
        Response::ok($stmt->fetchAll());
    }

    if (preg_match('#^/notifications/(\d+)/read$#', $path, $m) && $method === 'PATCH') {
        $user = current_user();
        pdo()->prepare('UPDATE notifications SET read_at=NOW() WHERE id=:id AND user_id=:user_id')->execute([':id' => Validator::int($m[1]), ':user_id' => $user['id']]);
        Response::ok(null, 'Notification marked read');
    }

    if ($method === 'POST' && $path === '/notifications') {
        $user = current_user();
        require_role($user, ['LECTURER', 'LIBRARIAN_ADMIN']);
        $data = body();
        Validator::require($data, ['title', 'message']);
        $type = (string)($data['type'] ?? 'system');
        $allowedTypes = ['new_book', 'borrow_approved', 'borrow_rejected', 'due_date', 'overdue', 'lecturer_recommendation', 'system'];
        if (!in_array($type, $allowedTypes, true)) {
            Response::error('Invalid notification type', 422);
        }
        pdo()->prepare(
            'INSERT INTO notifications (user_id, created_by, type, title, message)
             VALUES (:user_id, :created_by, :type, :title, :message)'
        )->execute([
            ':user_id' => optional_int($data['user_id'] ?? null, 'user_id'),
            ':created_by' => $user['id'],
            ':type' => $type,
            ':title' => trim((string)$data['title']),
            ':message' => trim((string)$data['message']),
        ]);
        Response::ok(['id' => (int)pdo()->lastInsertId()], 'Notification created');
    }

    if (preg_match('#^/notifications/(\d+)$#', $path, $m) && $method === 'DELETE') {
        $user = current_user();
        pdo()->prepare('UPDATE notifications SET status="deleted" WHERE id=:id AND user_id=:user_id')
            ->execute([':id' => Validator::int($m[1]), ':user_id' => $user['id']]);
        Response::ok(null, 'Notification deleted');
    }

    if ($method === 'GET' && $path === '/reading-lists') {
        $user = current_user();
        $courseId = optional_int($_GET['course_id'] ?? null, 'course_id');
        $stmt = pdo()->prepare(
            'SELECT rl.id, rl.title, rl.description, rl.visibility AS status, rl.status AS record_status, rl.created_at, c.name AS course, COUNT(rlb.id) AS books
             FROM reading_lists rl
             JOIN courses c ON c.id=rl.course_id
             LEFT JOIN reading_list_books rlb ON rlb.reading_list_id=rl.id AND rlb.status="active"
             WHERE rl.status="active" AND (:course_filter IS NULL OR rl.course_id=:course_id)
             AND (:is_admin=1 OR rl.lecturer_id=:user_id OR rl.visibility="published")
             GROUP BY rl.id, rl.title, rl.description, rl.visibility, rl.status, rl.created_at, c.name
             ORDER BY rl.created_at DESC'
        );
        $stmt->execute([
            ':course_filter' => $courseId,
            ':course_id' => $courseId,
            ':is_admin' => $user['role_code'] === 'LIBRARIAN_ADMIN' ? 1 : 0,
            ':user_id' => $user['id'],
        ]);
        Response::ok($stmt->fetchAll());
    }

    if (preg_match('#^/reading-lists/(\d+)$#', $path, $m) && $method === 'GET') {
        $user = current_user();
        $list = fetch_reading_list(Validator::int($m[1]));
        if ($user['role_code'] !== 'LIBRARIAN_ADMIN'
            && (int)$list['lecturer_id'] !== (int)$user['id']
            && $list['visibility'] !== 'published') {
            Response::error('You cannot access this reading list', 403);
        }
        $books = pdo()->prepare(
            book_select_sql() . '
             JOIN reading_list_books rlb ON rlb.book_id=b.id AND rlb.status="active"
             WHERE rlb.reading_list_id=:list_id AND b.status="active"
             ORDER BY rlb.sort_order, b.title'
        );
        $books->execute([':list_id' => $list['id']]);
        $notes = pdo()->prepare(
            'SELECT ln.id, ln.title, ln.original_name, ln.mime_type, ln.file_size, ln.status, ln.created_at,
                    c.name AS course
             FROM lecture_notes ln JOIN courses c ON c.id=ln.course_id
             WHERE ln.reading_list_id=:list_id AND ln.status <> "archived"
             ORDER BY ln.created_at DESC'
        );
        $notes->execute([':list_id' => $list['id']]);
        Response::ok(['list' => $list, 'books' => $books->fetchAll(), 'notes' => $notes->fetchAll()]);
    }

    if ($method === 'POST' && $path === '/reading-lists') {
        $user = current_user();
        require_role($user, ['LECTURER', 'LIBRARIAN_ADMIN']);
        $data = body();
        Validator::require($data, ['title']);
        $courseId = optional_int($data['course_id'] ?? null, 'course_id');
        if (!$courseId) {
            $courseStmt = pdo()->prepare(
                'SELECT course_id FROM lecturer_courses
                 WHERE lecturer_id=:assigned_lecturer_id AND status="active"
                 UNION
                 SELECT course_id FROM reading_lists
                 WHERE lecturer_id=:list_lecturer_id AND status="active"
                 LIMIT 1'
            );
            $courseStmt->execute([
                ':assigned_lecturer_id' => $user['id'],
                ':list_lecturer_id' => $user['id'],
            ]);
            $courseId = (int)($courseStmt->fetchColumn() ?: 0);
            if (!$courseId && $user['role_code'] === 'LIBRARIAN_ADMIN') {
                $courseId = (int)(pdo()->query('SELECT id FROM courses WHERE status="active" ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
            }
            if (!$courseId) {
                Response::error('Assign the lecturer to a course or provide course_id', 422);
            }
        }
        pdo()->prepare('INSERT INTO reading_lists (lecturer_id, course_id, title, description, visibility) VALUES (:lecturer_id, :course_id, :title, :description, :visibility)')
            ->execute([':lecturer_id' => $user['id'], ':course_id' => $courseId, ':title' => $data['title'], ':description' => $data['description'] ?? null, ':visibility' => $data['visibility'] ?? 'draft']);
        Response::ok(['id' => (int)pdo()->lastInsertId()], 'Reading list created');
    }

    if (preg_match('#^/reading-lists/(\d+)/books$#', $path, $m) && $method === 'POST') {
        $user = current_user();
        require_role($user, ['LECTURER', 'LIBRARIAN_ADMIN']);
        $list = fetch_reading_list(Validator::int($m[1]));
        require_reading_list_manager($user, $list);
        $data = body();
        $bookId = Validator::int($data['book_id'] ?? null, 'book_id');
        $requirement = (string)($data['requirement_type'] ?? 'recommended');
        if (!in_array($requirement, ['required', 'recommended', 'optional'], true)) {
            Response::error('Invalid requirement type', 422);
        }
        pdo()->prepare(
            'INSERT INTO reading_list_books (reading_list_id, book_id, requirement_type, sort_order, status)
             VALUES (:list_id, :book_id, :requirement, :sort_order, "active")
             ON DUPLICATE KEY UPDATE requirement_type=VALUES(requirement_type), sort_order=VALUES(sort_order), status="active"'
        )->execute([
            ':list_id' => $list['id'],
            ':book_id' => $bookId,
            ':requirement' => $requirement,
            ':sort_order' => max(0, (int)($data['sort_order'] ?? 0)),
        ]);
        Response::ok(['reading_list_id' => (int)$list['id'], 'book_id' => $bookId], 'Book added to reading list');
    }

    if (preg_match('#^/reading-lists/(\d+)/books/(\d+)$#', $path, $m) && $method === 'DELETE') {
        $user = current_user();
        require_role($user, ['LECTURER', 'LIBRARIAN_ADMIN']);
        $list = fetch_reading_list(Validator::int($m[1]));
        require_reading_list_manager($user, $list);
        pdo()->prepare(
            'UPDATE reading_list_books SET status="removed" WHERE reading_list_id=:list_id AND book_id=:book_id'
        )->execute([':list_id' => $list['id'], ':book_id' => Validator::int($m[2], 'book_id')]);
        Response::ok(null, 'Book removed from reading list');
    }

    if ($method === 'POST' && $path === '/lecture-notes') {
        $user = current_user();
        require_role($user, ['LECTURER', 'LIBRARIAN_ADMIN']);
        if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            Response::error('Lecture note file is required', 422);
        }
        $courseId = Validator::int($_POST['course_id'] ?? null, 'course_id');
        $listId = optional_int($_POST['reading_list_id'] ?? null, 'reading_list_id');
        if ($listId) {
            $list = fetch_reading_list($listId);
            require_reading_list_manager($user, $list);
        }
        $file = $_FILES['file'];
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            Response::error('The lecture note upload did not complete successfully', 422);
        }
        $maxNoteBytes = configured_int('max_lecture_note_upload_bytes', 25 * 1024 * 1024);
        if ((int)($file['size'] ?? 0) > $maxNoteBytes) {
            Response::error('Lecture notes must be ' . format_bytes($maxNoteBytes) . ' or smaller', 422);
        }
        $noteExtension = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($noteExtension, ['pdf', 'docx', 'txt'], true)) {
            Response::error('Lecture notes must be PDF, DOCX, or TXT files', 422);
        }
        $extension = safe_upload_extension($file['name'] ?? '');
        $notesRoot = (__DIR__ . '/../uploads/lecture-notes');
        if (!is_dir($notesRoot)) {
            mkdir($notesRoot, 0775, true);
        }
        $storedName = bin2hex(random_bytes(12)) . '.' . $extension;
        $target = $notesRoot . DIRECTORY_SEPARATOR . $storedName;
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            Response::error('Could not store lecture note', 500);
        }
        pdo()->prepare(
            'INSERT INTO lecture_notes
             (reading_list_id, lecturer_id, course_id, title, file_path, original_name, mime_type, file_size)
             VALUES (:list_id, :lecturer_id, :course_id, :title, :path, :original_name, :mime_type, :file_size)'
        )->execute([
            ':list_id' => $listId,
            ':lecturer_id' => $user['id'],
            ':course_id' => $courseId,
            ':title' => trim((string)($_POST['title'] ?? pathinfo($file['name'], PATHINFO_FILENAME))),
            ':path' => 'uploads/lecture-notes/' . $storedName,
            ':original_name' => $file['name'] ?? $storedName,
            ':mime_type' => $file['type'] ?? null,
            ':file_size' => (int)($file['size'] ?? 0),
        ]);
        Response::ok(['id' => (int)pdo()->lastInsertId()], 'Lecture note uploaded');
    }

    if ($method === 'GET' && $path === '/lecture-notes') {
        $user = current_user();
        $conditions = ['ln.status <> "archived"'];
        $params = [];
        if ($user['role_code'] === 'LECTURER') {
            $conditions[] = 'ln.lecturer_id=:lecturer_id';
            $params[':lecturer_id'] = $user['id'];
        } elseif ($user['role_code'] !== 'LIBRARIAN_ADMIN') {
            $conditions[] = 'ln.status="approved"';
        }
        if (!empty($_GET['course_id'])) {
            $conditions[] = 'ln.course_id=:course_id';
            $params[':course_id'] = Validator::int($_GET['course_id'], 'course_id');
        }
        $stmt = pdo()->prepare(
            'SELECT ln.id, ln.reading_list_id, ln.lecturer_id, ln.course_id, ln.title, ln.original_name,
                    ln.mime_type, ln.file_size, ln.status, ln.created_at, c.name AS course,
                    u.full_name AS lecturer_name
             FROM lecture_notes ln
             JOIN courses c ON c.id=ln.course_id
             JOIN users u ON u.id=ln.lecturer_id
             WHERE ' . implode(' AND ', $conditions) . ' ORDER BY ln.created_at DESC'
        );
        $stmt->execute($params);
        Response::ok($stmt->fetchAll());
    }

    if (preg_match('#^/lecture-notes/(\d+)/download$#', $path, $m) && $method === 'GET') {
        $user = current_user();
        $stmt = pdo()->prepare('SELECT * FROM lecture_notes WHERE id=:id AND status <> "archived" LIMIT 1');
        $stmt->execute([':id' => Validator::int($m[1])]);
        $note = $stmt->fetch();
        if (!$note) {
            Response::error('Lecture note not found', 404);
        }
        if ($user['role_code'] !== 'LIBRARIAN_ADMIN'
            && (int)$note['lecturer_id'] !== (int)$user['id']
            && $note['status'] !== 'approved') {
            Response::error('This lecture note has not been approved', 403);
        }
        serve_stored_file(resolve_stored_upload($note));
    }

    if (preg_match('#^/lecture-notes/(\d+)/moderate$#', $path, $m) && $method === 'PATCH') {
        $user = current_user();
        require_role($user, admin_roles());
        $status = (string)(body()['status'] ?? '');
        if (!in_array($status, ['approved', 'rejected', 'archived'], true)) {
            Response::error('Invalid lecture note status', 422);
        }
        $id = Validator::int($m[1]);
        pdo()->prepare('UPDATE lecture_notes SET status=:status WHERE id=:id')
            ->execute([':status' => $status, ':id' => $id]);
        ActivityLogService::log($user, 'moderate_lecture_note', 'success', 'lecture_notes', $id, ['status' => $status]);
        Response::ok(['id' => $id, 'status' => $status], 'Lecture note updated');
    }

    if ($method === 'GET' && $path === '/lecturer/resources') {
        $user = current_user();
        require_role($user, ['LECTURER', 'LIBRARIAN_ADMIN']);
        $lecturerId = $user['role_code'] === 'LIBRARIAN_ADMIN'
            ? optional_int($_GET['lecturer_id'] ?? null, 'lecturer_id')
            : (int)$user['id'];
        if (!$lecturerId) {
            Response::ok(['courses' => [], 'books' => [], 'notes' => []]);
        }
        $courses = pdo()->prepare(
            'SELECT c.id, c.name, c.code, d.name AS department
             FROM lecturer_courses lc
             JOIN courses c ON c.id=lc.course_id
             JOIN departments d ON d.id=c.department_id
             WHERE lc.lecturer_id=:lecturer_id AND lc.status="active" AND c.status="active"
             ORDER BY c.name'
        );
        $courses->execute([':lecturer_id' => $lecturerId]);
        $books = pdo()->prepare(
            book_select_sql() . '
             JOIN book_courses bc ON bc.book_id=b.id AND bc.status="active"
             JOIN lecturer_courses lc ON lc.course_id=bc.course_id AND lc.status="active"
             WHERE lc.lecturer_id=:lecturer_id AND b.status="active" ORDER BY b.title'
        );
        $books->execute([':lecturer_id' => $lecturerId]);
        $notes = pdo()->prepare(
            'SELECT ln.id, ln.title, ln.original_name, ln.status, ln.created_at, c.name AS course
             FROM lecture_notes ln JOIN courses c ON c.id=ln.course_id
             WHERE ln.lecturer_id=:lecturer_id AND ln.status <> "archived" ORDER BY ln.created_at DESC'
        );
        $notes->execute([':lecturer_id' => $lecturerId]);
        Response::ok(['courses' => $courses->fetchAll(), 'books' => $books->fetchAll(), 'notes' => $notes->fetchAll()]);
    }

    if ($method === 'GET' && $path === '/lecturer/engagement') {
        $user = current_user();
        require_role($user, ['LECTURER']);
        $params = [':lecturer_id' => $user['id']];
        $courseStats = pdo()->prepare(
            'SELECT c.id, c.name AS course,
                    COUNT(DISTINCT up.user_id) AS enrolled_students,
                    COUNT(DISTINCT rp.user_id) AS active_readers,
                    COUNT(DISTINCT br.id) AS borrow_actions,
                    ROUND(COALESCE(AVG(rp.progress_percentage), 0), 1) AS average_progress
             FROM lecturer_courses lc
             JOIN courses c ON c.id=lc.course_id
             LEFT JOIN user_profiles up ON up.course_id=c.id AND up.status="active"
             LEFT JOIN reading_progress rp ON rp.user_id=up.user_id AND rp.status="active"
             LEFT JOIN books b ON b.course_id=c.id
             LEFT JOIN borrow_requests br ON br.book_id=b.id AND br.user_id=up.user_id
             WHERE lc.lecturer_id=:lecturer_id AND lc.status="active"
             GROUP BY c.id, c.name ORDER BY c.name'
        );
        $courseStats->execute($params);
        $recent = pdo()->prepare(
            'SELECT al.action, al.entity_type, al.entity_id, al.created_at, u.full_name AS student
             FROM activity_logs al
             JOIN users u ON u.id=al.user_id
             JOIN user_profiles up ON up.user_id=u.id
             JOIN lecturer_courses lc ON lc.course_id=up.course_id AND lc.status="active"
             WHERE lc.lecturer_id=:lecturer_id AND al.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             ORDER BY al.created_at DESC LIMIT 100'
        );
        $recent->execute($params);
        Response::ok(['courses' => $courseStats->fetchAll(), 'recent_activity' => $recent->fetchAll()]);
    }

    if ($method === 'GET' && $path === '/analytics') {
        $user = current_user();
        require_role($user, admin_roles());
        $data = [
            'totalUsers' => (int)pdo()->query("SELECT COUNT(*) FROM users WHERE status='active'")->fetchColumn(),
            'totalBooks' => (int)pdo()->query("SELECT COUNT(*) FROM books WHERE status='active'")->fetchColumn(),
            'borrowedBooks' => (int)pdo()->query("SELECT COUNT(*) FROM borrow_requests WHERE status='approved'")->fetchColumn(),
            'availableBooks' => (int)pdo()->query("SELECT COALESCE(SUM(available_copies),0) FROM books WHERE status='active'")->fetchColumn(),
            'overdueBooks' => (int)pdo()->query("SELECT COUNT(*) FROM borrow_requests WHERE status='approved' AND due_date < CURDATE()")->fetchColumn(),
            'voiceSearches' => (int)pdo()->query("SELECT COUNT(*) FROM voice_search_logs")->fetchColumn(),
            'ttsPlays' => (int)pdo()->query("SELECT COUNT(*) FROM tts_logs")->fetchColumn(),
        ];
        Response::ok($data);
    }

    if ($method === 'GET' && $path === '/analytics/top') {
        $user = current_user();
        require_role($user, admin_roles());
        Response::ok([
            'mostSearchedBooks' => pdo()->query(
                'SELECT query_text, COUNT(*) AS searches FROM search_logs
                 WHERE status <> "failed" GROUP BY query_text ORDER BY searches DESC LIMIT 10'
            )->fetchAll(),
            'mostBorrowedBooks' => pdo()->query(
                'SELECT b.id, b.title, COUNT(*) AS borrow_count FROM borrow_requests br
                 JOIN books b ON b.id=br.book_id WHERE br.status IN ("approved","returned","overdue")
                 GROUP BY b.id, b.title ORDER BY borrow_count DESC LIMIT 10'
            )->fetchAll(),
            'mostActiveUsers' => pdo()->query(
                'SELECT u.id, u.full_name, COUNT(*) AS activity_count FROM activity_logs al
                 JOIN users u ON u.id=al.user_id GROUP BY u.id, u.full_name ORDER BY activity_count DESC LIMIT 10'
            )->fetchAll(),
        ]);
    }

    if ($method === 'GET' && $path === '/logs/activity') {
        $user = current_user();
        require_role($user, admin_roles());
        $conditions = [];
        $params = [];
        if (!empty($_GET['user_id'])) {
            $conditions[] = 'al.user_id=:user_id';
            $params[':user_id'] = Validator::int($_GET['user_id'], 'user_id');
        }
        if (!empty($_GET['from'])) {
            $conditions[] = 'al.created_at >= :from_date';
            $params[':from_date'] = $_GET['from'] . ' 00:00:00';
        }
        if (!empty($_GET['to'])) {
            $conditions[] = 'al.created_at <= :to_date';
            $params[':to_date'] = $_GET['to'] . ' 23:59:59';
        }
        $sql = 'SELECT al.*, COALESCE(u.full_name, "System") AS actor, r.code AS role_code
                FROM activity_logs al
                LEFT JOIN users u ON u.id=al.user_id
                LEFT JOIN roles r ON r.id=u.role_id' .
            ($conditions ? ' WHERE ' . implode(' AND ', $conditions) : '') .
            ' ORDER BY al.created_at DESC LIMIT 200';
        $stmt = pdo()->prepare($sql);
        $stmt->execute($params);
        Response::ok($stmt->fetchAll());
    }

    if (preg_match('#^/logs/(security|search|voice|tts)$#', $path, $m) && $method === 'GET') {
        $user = current_user();
        require_role($user, admin_roles());
        $table = match ($m[1]) {
            'security' => 'security_logs',
            'search' => 'search_logs',
            'voice' => 'voice_search_logs',
            default => 'tts_logs',
        };
        $conditions = [];
        $params = [];
        if (!empty($_GET['user_id'])) {
            $conditions[] = 'user_id=:user_id';
            $params[':user_id'] = Validator::int($_GET['user_id'], 'user_id');
        }
        if (!empty($_GET['from'])) {
            $conditions[] = 'created_at >= :from_date';
            $params[':from_date'] = $_GET['from'] . ' 00:00:00';
        }
        if (!empty($_GET['to'])) {
            $conditions[] = 'created_at <= :to_date';
            $params[':to_date'] = $_GET['to'] . ' 23:59:59';
        }
        $sql = "SELECT * FROM {$table}" . ($conditions ? ' WHERE ' . implode(' AND ', $conditions) : '') .
            ' ORDER BY created_at DESC LIMIT :limit';
        $stmt = pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', bounded_int($_GET['limit'] ?? null, 100, 1, 500), PDO::PARAM_INT);
        $stmt->execute();
        Response::ok($stmt->fetchAll());
    }

    if ($method === 'GET' && $path === '/voice-search/logs') {
        $user = current_user();
        $stmt = pdo()->prepare(
            'SELECT * FROM voice_search_logs WHERE user_id=:user_id ORDER BY created_at DESC LIMIT 100'
        );
        $stmt->execute([':user_id' => $user['id']]);
        Response::ok($stmt->fetchAll());
    }

    if ($method === 'GET' && $path === '/tts/logs') {
        $user = current_user();
        $stmt = pdo()->prepare('SELECT * FROM tts_logs WHERE user_id=:user_id ORDER BY created_at DESC LIMIT 100');
        $stmt->execute([':user_id' => $user['id']]);
        Response::ok($stmt->fetchAll());
    }

    if ($method === 'POST' && $path === '/logs/activity') {
        $user = current_user();
        $data = body();
        Validator::require($data, ['action']);
        ActivityLogService::log(
            $user,
            trim((string)$data['action']),
            in_array(($data['status'] ?? 'success'), ['success', 'failed', 'warning'], true) ? $data['status'] : 'success',
            $data['entity_type'] ?? null,
            optional_int($data['entity_id'] ?? null, 'entity_id'),
            is_array($data['metadata'] ?? null) ? $data['metadata'] : []
        );
        Response::ok(null, 'Activity logged');
    }

    if ($method === 'POST' && $path === '/logs/security') {
        $user = current_user(false);
        $data = body();
        Validator::require($data, ['event_type']);
        $severity = (string)($data['severity'] ?? 'low');
        if (!in_array($severity, ['low', 'medium', 'high', 'critical'], true)) {
            Response::error('Invalid severity', 422);
        }
        pdo()->prepare(
            'INSERT INTO security_logs (user_id, event_type, severity, ip_address, user_agent, details)
             VALUES (:user_id, :event_type, :severity, :ip, :user_agent, :details)'
        )->execute([
            ':user_id' => $user['id'] ?? null,
            ':event_type' => trim((string)$data['event_type']),
            ':severity' => $severity,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ':details' => $data['details'] ?? null,
        ]);
        Response::ok(['id' => (int)pdo()->lastInsertId()], 'Security event logged');
    }

    if ($method === 'GET' && $path === '/settings') {
        $user = current_user(false);
        $isAdmin = $user && $user['role_code'] === 'LIBRARIAN_ADMIN';
        $stmt = pdo()->prepare(
            'SELECT setting_key, setting_value, value_type, description, is_public
             FROM system_settings WHERE status="active" AND (:is_admin=1 OR is_public=1) ORDER BY setting_key'
        );
        $stmt->execute([':is_admin' => $isAdmin ? 1 : 0]);
        Response::ok($stmt->fetchAll());
    }

    if ($method === 'PUT' && $path === '/settings') {
        $user = current_user();
        require_role($user, admin_roles());
        foreach (body() as $key => $value) {
            pdo()->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')
                ->execute([':k' => $key, ':v' => is_scalar($value) ? (string)$value : json_encode($value)]);
        }
        Response::ok(null, 'Settings updated');
    }

    if ($method === 'GET' && $path === '/ai/models') {
        current_user();
        Response::ok(ai_model_status());
    }

    if ($method === 'POST' && $path === '/voice-search/search') {
        enforce_rate_limit('voice-search', 30, 300);
        $user = current_user();
        $language = strtolower(trim((string)($_POST['language'] ?? 'en'))) ?: 'en';
        if (!in_array($language, ['en', 'fr', 'rw', 'sw'], true)) {
            $language = 'en';
        }
        $transcript = trim($_POST['transcript'] ?? '');
        $originalTranscript = $transcript;
        $stt = null;
        $aiStatus = $transcript === '' ? 'awaiting_audio' : 'manual_transcript';

        if ($transcript === '' && isset($_FILES['audio'])) {
            $stt = local_open_vocabulary_transcribe($_FILES['audio'], $language);
            if ($stt) {
                $transcript = trim((string)($stt['transcription'] ?? $stt['predicted_text'] ?? $stt['text'] ?? ''));
                $originalTranscript = $transcript;
                $confidence = (float)($stt['confidence'] ?? 0);
                $aiStatus = $transcript === ''
                    ? 'empty_transcript'
                    : ($confidence > 0 && $confidence < 0.2
                        ? 'stt_low_confidence'
                        : (($stt['provider'] ?? '') === 'local_whisper_fallback' ? 'whisper_fallback_success' : 'transformer_success'));
            } else {
                $aiStatus = 'open_vocabulary_stt_unavailable';
            }
        }

        $searchTranscript = $transcript;
        if ($transcript !== '' && $language !== 'en' && class_exists('TranslationService')) {
            try {
                $translatedQuery = TranslationService::translate($transcript, 'en', $language, $user, 'voice_search');
                if (!empty($translatedQuery['translated_text'])) {
                    $searchTranscript = trim((string)$translatedQuery['translated_text']);
                }
            } catch (Throwable $translationError) {
                error_log('[Rwanda Library voice translation] ' . $translationError->getMessage());
            }
        }

        $rows = [];
        $action = $searchTranscript !== '' ? interpret_library_action($searchTranscript) : null;
        if (!$action && $searchTranscript !== '' && strlen($searchTranscript) >= 2 && $aiStatus !== 'stt_low_confidence') {
            $parsedQuery = parse_catalog_query($searchTranscript);
            $terms = $parsedQuery['keywords'] !== '' ? $parsedQuery['keywords'] : $searchTranscript;
            $like = "%{$terms}%";
            $authorLike = '%' . ($parsedQuery['author'] !== '' ? $parsedQuery['author'] : $terms) . '%';
            $availabilitySql = match ($parsedQuery['availability']) {
                'available' => ' AND b.available_copies > 0',
                'unavailable' => ' AND b.available_copies = 0',
                default => '',
            };
            $stmt = pdo()->prepare(
                book_select_sql() . ' WHERE b.status="active"
                 AND (b.title LIKE :title_q OR a.full_name LIKE :author_q OR b.keywords LIKE :keywords_q
                      OR b.description LIKE :description_q OR cat.name LIKE :category_q
                      OR c.name LIKE :course_q OR d.name LIKE :department_q OR f.name LIKE :faculty_q)' .
                 $availabilitySql . '
                 ORDER BY CASE WHEN b.title LIKE :rank_title THEN 1 WHEN a.full_name LIKE :rank_author THEN 2 ELSE 3 END,
                          b.created_at DESC LIMIT 20'
            );
            $stmt->execute([
                ':title_q' => $like,
                ':author_q' => $authorLike,
                ':keywords_q' => $like,
                ':description_q' => $like,
                ':category_q' => $like,
                ':course_q' => $like,
                ':department_q' => $like,
                ':faculty_q' => $like,
                ':rank_title' => $like,
                ':rank_author' => $authorLike,
            ]);
            $rows = $stmt->fetchAll();
        }
        pdo()->prepare(
            'INSERT INTO voice_search_logs
             (user_id, transcript, confidence, language, duration_seconds, results_count, status)
             VALUES (:user_id, :transcript, :confidence, :language, :duration, :count, :status)'
        )
            ->execute([
                ':user_id' => $user['id'],
                ':transcript' => $transcript,
                ':confidence' => $stt ? round((float)($stt['confidence'] ?? 0), 2) : null,
                ':language' => $language,
                ':duration' => $stt['processing_seconds'] ?? null,
                ':count' => count($rows),
                ':status' => normalize_log_status(
                    $transcript === '' ? 'empty_transcript' : ($aiStatus === 'open_vocabulary_stt_unavailable' ? 'failed' : 'success')
                ),
            ]);
        Response::ok([
            'transcript' => $transcript,
            'original_transcript' => $originalTranscript ?: $transcript,
            'search_transcript' => $searchTranscript,
            'language' => $language,
            'parsed_query' => $searchTranscript !== '' ? parse_catalog_query($searchTranscript) : null,
            'action' => $action,
            'results' => $rows,
            'ai_status' => $aiStatus,
            'stt' => $stt,
        ]);
    }
    if (preg_match('#^/tts/audio/([a-f0-9]{64}\.(?:mp3|wav))$#', $path, $m) && $method === 'GET') {
        $user = current_user();
        $mimeType = str_ends_with($m[1], '.wav') ? 'audio/wav' : 'audio/mpeg';
        $file = [
            'file_path' => 'uploads/tts/' . (int)$user['id'] . '/' . $m[1],
            'original_name' => 'mdl-library-narration.' . pathinfo($m[1], PATHINFO_EXTENSION),
            'mime_type' => $mimeType,
        ];
        serve_stored_file(resolve_stored_upload($file), true);
    }

    if ($method === 'POST' && in_array($path, ['/tts', '/tts/generate', '/tts/read-book', '/tts/read-summary'], true)) {
        enforce_rate_limit('tts', 60, 300);
        $user = current_user();
        $data = body();
        $text = trim((string)($data['text'] ?? ''));
        $audio = generate_narration_audio(
            $user,
            $text,
            (string)($data['language'] ?? 'en'),
            (string)($data['provider'] ?? $data['voice'] ?? 'speecht5')
        );
        pdo()->prepare('INSERT INTO tts_logs (user_id, book_id, text_length, provider, status) VALUES (:user_id, :book_id, :text_length, :provider, "success")')
            ->execute([
                ':user_id' => $user['id'],
                ':book_id' => optional_int($data['book_id'] ?? null, 'book_id'),
                ':text_length' => mb_strlen($text),
                ':provider' => $audio['provider'],
            ]);
        Response::ok([
            'audio_url' => '/tts/audio/' . $audio['filename'],
            'text_length' => mb_strlen($text),
            'provider' => $audio['provider'],
            'file_size' => $audio['file_size'],
        ], $audio['provider'] === 'speecht5' ? 'SpeechT5 narration generated' : ($audio['provider'] === 'mms_tts' ? 'MMS multilingual narration generated' : 'gTTS narration generated'));
    }

    if ($method === 'GET' && $path === '/startup/layers') {
        $user = current_user(false);
        $counts = [
            'books' => (int)pdo()->query('SELECT COUNT(*) FROM books WHERE status="active"')->fetchColumn(),
            'indexed_chunks' => (int)pdo()->query('SELECT COUNT(*) FROM book_page_index')->fetchColumn(),
            'indexed_books' => (int)pdo()->query('SELECT COUNT(DISTINCT book_id) FROM book_page_index')->fetchColumn(),
            'institutions' => (int)pdo()->query('SELECT COUNT(*) FROM institutions WHERE status IN ("active","trial")')->fetchColumn(),
            'publishers' => (int)pdo()->query('SELECT COUNT(*) FROM publishers WHERE status <> "archived"')->fetchColumn(),
            'rights_records' => (int)pdo()->query('SELECT COUNT(*) FROM content_rights')->fetchColumn(),
            'queued_jobs' => (int)pdo()->query('SELECT COUNT(*) FROM ai_jobs WHERE status IN ("queued","running")')->fetchColumn(),
            'search_logs' => (int)pdo()->query('SELECT COUNT(*) FROM search_logs')->fetchColumn(),
            'failed_searches' => (int)pdo()->query('SELECT COUNT(*) FROM search_logs WHERE status IN ("failed","no_results")')->fetchColumn(),
            'ai_usage_events' => (int)pdo()->query('SELECT COUNT(*) FROM ai_usage_logs')->fetchColumn(),
        ];
        $health = [
            'translation' => class_exists('TranslationService') ? TranslationService::health() : ['status' => 'unavailable'],
            'tts' => tts_health(),
            'models' => ai_model_status(),
            'storage' => class_exists('StorageService') ? StorageService::capabilities() : ['status' => 'unavailable'],
            'tenant' => class_exists('TenantService') ? TenantService::health() : ['status' => 'unavailable'],
        ];
        Response::ok([
            'direction' => 'Library foundation -> metadata -> advanced search -> big-book indexing -> AI retrieval -> audio/translation -> SaaS institutions -> publisher rights -> analytics -> scale',
            'layers' => [
                ['key' => 'metadata', 'status' => $counts['books'] > 0 ? 'active' : 'needs_data', 'evidence' => ['active_books' => $counts['books']]],
                ['key' => 'search', 'status' => 'active', 'evidence' => ['search_logs' => $counts['search_logs'], 'autocomplete' => true, 'content_search' => true]],
                ['key' => 'ai_performance', 'status' => 'active', 'evidence' => ['retrieval_first' => true, 'ai_usage_events' => $counts['ai_usage_events'], 'model_health' => $health['models']]],
                ['key' => 'big_book_processing', 'status' => $counts['indexed_chunks'] > 0 ? 'active' : 'needs_indexing', 'evidence' => ['indexed_books' => $counts['indexed_books'], 'indexed_chunks' => $counts['indexed_chunks']]],
                ['key' => 'user_experience', 'status' => 'active', 'evidence' => ['bookmarks' => true, 'reading_progress' => true, 'voice_guidance' => true]],
                ['key' => 'saas_institutions', 'status' => $counts['institutions'] > 0 ? 'active' : 'needs_setup', 'evidence' => ['institutions' => $counts['institutions'], 'tenant' => $health['tenant']]],
                ['key' => 'publisher_rights', 'status' => 'active', 'evidence' => ['publishers' => $counts['publishers'], 'rights_records' => $counts['rights_records']]],
                ['key' => 'security', 'status' => 'active', 'evidence' => ['rate_limits' => true, 'role_permissions' => true, 'protected_files' => true, 'audit_logs' => true]],
                ['key' => 'analytics', 'status' => 'active', 'evidence' => ['failed_searches' => $counts['failed_searches'], 'ai_usage_events' => $counts['ai_usage_events']]],
                ['key' => 'scalability', 'status' => 'pilot_ready', 'evidence' => ['object_storage_ready' => $health['storage']['object_storage_ready'] ?? false, 'queued_jobs' => $counts['queued_jobs'], 'cache_table' => true]],
            ],
            'counts' => $counts,
            'admin_view' => ($user['role_code'] ?? '') === 'LIBRARIAN_ADMIN',
        ]);
    }

    if ($method === 'GET' && $path === '/analytics/usage') {
        $user = current_user();
        require_role($user, admin_roles());
        Response::ok([
            'failedSearches' => pdo()->query('SELECT query_text, COUNT(*) AS attempts, MAX(created_at) AS last_seen FROM search_logs WHERE status IN ("failed","no_results") GROUP BY query_text ORDER BY attempts DESC, last_seen DESC LIMIT 20')->fetchAll(),
            'aiUsageByService' => pdo()->query('SELECT service_name, provider, model_name, status, COUNT(*) AS events, SUM(input_units) AS input_units, SUM(output_units) AS output_units FROM ai_usage_logs GROUP BY service_name, provider, model_name, status ORDER BY events DESC')->fetchAll(),
            'ttsUsageByProvider' => pdo()->query('SELECT provider, COUNT(*) AS events, SUM(text_length) AS text_units, COUNT(audio_path) AS generated_files FROM tts_logs GROUP BY provider ORDER BY events DESC')->fetchAll(),
            'translationUsage' => pdo()->query('SELECT provider, source_language, target_language, quality_status, COUNT(*) AS events, AVG(confidence) AS avg_confidence FROM translation_requests GROUP BY provider, source_language, target_language, quality_status ORDER BY events DESC LIMIT 30')->fetchAll(),
            'jobQueue' => pdo()->query('SELECT job_type, status, COUNT(*) AS jobs, MAX(updated_at) AS last_update FROM ai_jobs GROUP BY job_type, status ORDER BY job_type, status')->fetchAll(),
            'modelFallbacks' => pdo()->query('SELECT task, primary_provider, fallback_provider, status, COUNT(*) AS events, MAX(created_at) AS last_seen FROM model_fallback_logs GROUP BY task, primary_provider, fallback_provider, status ORDER BY events DESC LIMIT 30')->fetchAll(),
        ]);
    }

    if ($method === 'GET' && $path === '/content/licenses') {
        Response::ok(pdo()->query('SELECT * FROM content_licenses WHERE status="active" ORDER BY name')->fetchAll());
    }

    if ($method === 'GET' && $path === '/publishers') {
        $user = current_user();
        require_role($user, admin_roles());
        Response::ok(pdo()->query('SELECT * FROM publishers WHERE status <> "archived" ORDER BY name')->fetchAll());
    }

    if ($method === 'POST' && $path === '/publishers') {
        $user = current_user();
        require_role($user, admin_roles());
        $data = body();
        Validator::require($data, ['name']);
        $name = trim((string)$data['name']);
        $slug = strtolower(trim((string)($data['slug'] ?? preg_replace('/[^a-z0-9]+/i', '-', $name)), '-'));
        pdo()->prepare('INSERT INTO publishers (name, slug, contact_name, contact_email, contact_phone, country_code, status) VALUES (:name, :slug, :contact_name, :contact_email, :contact_phone, :country_code, :status)')
            ->execute([
                ':name' => $name,
                ':slug' => $slug,
                ':contact_name' => $data['contact_name'] ?? null,
                ':contact_email' => $data['contact_email'] ?? null,
                ':contact_phone' => $data['contact_phone'] ?? null,
                ':country_code' => strtoupper((string)($data['country_code'] ?? 'RW')),
                ':status' => $data['status'] ?? 'pending',
            ]);
        Response::ok(['id' => (int)pdo()->lastInsertId()], 'Publisher created');
    }

    if ($method === 'GET' && $path === '/publisher/rights') {
        $user = current_user();
        require_role($user, admin_roles());
        $stmt = pdo()->query(
            'SELECT cr.*, b.title, b.isbn, p.name AS publisher_name, cl.name AS license_name
             FROM content_rights cr
             JOIN books b ON b.id=cr.book_id
             LEFT JOIN publishers p ON p.id=cr.publisher_id
             LEFT JOIN content_licenses cl ON cl.id=cr.license_id
             ORDER BY cr.updated_at DESC LIMIT 200'
        );
        Response::ok($stmt->fetchAll());
    }

    if ($method === 'PUT' && preg_match('#^/books/(\d+)/rights$#', $path, $m)) {
        $user = current_user();
        require_role($user, admin_roles());
        $bookId = Validator::int($m[1], 'book_id');
        fetch_book($bookId);
        $data = body();
        pdo()->prepare(
            'INSERT INTO content_rights (book_id, publisher_id, license_id, rights_owner, copyright_status, visibility, access_level, starts_at, ends_at, notes)
             VALUES (:book_id, :publisher_id, :license_id, :rights_owner, :copyright_status, :visibility, :access_level, :starts_at, :ends_at, :notes)
             ON DUPLICATE KEY UPDATE publisher_id=VALUES(publisher_id), license_id=VALUES(license_id), rights_owner=VALUES(rights_owner), copyright_status=VALUES(copyright_status), visibility=VALUES(visibility), access_level=VALUES(access_level), starts_at=VALUES(starts_at), ends_at=VALUES(ends_at), notes=VALUES(notes)'
        )->execute([
            ':book_id' => $bookId,
            ':publisher_id' => optional_int($data['publisher_id'] ?? null, 'publisher_id'),
            ':license_id' => optional_int($data['license_id'] ?? null, 'license_id'),
            ':rights_owner' => $data['rights_owner'] ?? null,
            ':copyright_status' => $data['copyright_status'] ?? 'unknown',
            ':visibility' => $data['visibility'] ?? 'institution',
            ':access_level' => $data['access_level'] ?? 'read_online',
            ':starts_at' => $data['starts_at'] ?? null,
            ':ends_at' => $data['ends_at'] ?? null,
            ':notes' => $data['notes'] ?? null,
        ]);
        Response::ok(['book_id' => $bookId], 'Content rights updated');
    }

    if ($method === 'POST' && $path === '/takedown-requests') {
        enforce_rate_limit('takedown', 10, 900);
        $user = current_user(false);
        $data = body();
        Validator::require($data, ['requester_name', 'requester_email', 'reason']);
        Validator::email($data['requester_email']);
        pdo()->prepare('INSERT INTO takedown_requests (institution_id, book_id, requester_name, requester_email, reason) VALUES (:institution_id, :book_id, :requester_name, :requester_email, :reason)')
            ->execute([
                ':institution_id' => class_exists('TenantService') ? TenantService::institutionIdFor($user) : null,
                ':book_id' => optional_int($data['book_id'] ?? null, 'book_id'),
                ':requester_name' => trim((string)$data['requester_name']),
                ':requester_email' => trim((string)$data['requester_email']),
                ':reason' => trim((string)$data['reason']),
            ]);
        Response::ok(['id' => (int)pdo()->lastInsertId()], 'Takedown request received');
    }

    if ($method === 'GET' && $path === '/takedown-requests') {
        $user = current_user();
        require_role($user, admin_roles());
        Response::ok(pdo()->query('SELECT tr.*, b.title AS book_title FROM takedown_requests tr LEFT JOIN books b ON b.id=tr.book_id ORDER BY FIELD(tr.status, "open", "reviewing", "resolved", "rejected"), tr.created_at DESC LIMIT 200')->fetchAll());
    }

    if ($method === 'PATCH' && preg_match('#^/takedown-requests/(\d+)$#', $path, $m)) {
        $user = current_user();
        require_role($user, admin_roles());
        $data = body();
        $status = $data['status'] ?? 'reviewing';
        if (!in_array($status, ['open', 'reviewing', 'resolved', 'rejected'], true)) {
            Response::error('Invalid takedown status', 422);
        }
        pdo()->prepare('UPDATE takedown_requests SET status=:status, reviewed_by=:reviewed_by, reviewed_at=NOW(), resolution_note=:resolution_note WHERE id=:id')
            ->execute([
                ':status' => $status,
                ':reviewed_by' => $user['id'],
                ':resolution_note' => $data['resolution_note'] ?? null,
                ':id' => Validator::int($m[1], 'id'),
            ]);
        Response::ok(['id' => (int)$m[1], 'status' => $status], 'Takedown request updated');
    }

    if (str_starts_with($path, '/reports/')) {
        $user = current_user();
        require_role($user, admin_roles());
        $report = basename($path);
        $report = match ($report) {
            'book-inventory', 'inventory' => 'books',
            'user-activity' => 'activity',
            default => $report,
        };
        $map = [
            'books' => 'SELECT b.id AS book_id, b.title, b.isbn, b.total_copies, b.available_copies,
                        (b.total_copies - b.available_copies) AS copies_in_use, b.status, b.created_at
                        FROM books b ORDER BY b.title',
            'borrowing' => 'SELECT br.id AS request_id, br.user_id, u.full_name AS user_name,
                            br.book_id, b.title AS book_title, br.requested_at, br.approved_at,
                            br.due_date, br.renewed_until, br.returned_at, br.status
                            FROM borrow_requests br JOIN users u ON u.id=br.user_id
                            JOIN books b ON b.id=br.book_id ORDER BY br.created_at DESC',
            'users' => 'SELECT u.id AS user_id, u.full_name, u.email, r.code AS role_code,
                        u.status, u.last_login_at, u.created_at
                        FROM users u JOIN roles r ON r.id=u.role_id ORDER BY u.created_at DESC',
            'overdue' => 'SELECT br.*, u.full_name, b.title FROM borrow_requests br JOIN users u ON u.id=br.user_id JOIN books b ON b.id=br.book_id WHERE br.status IN ("approved","overdue") AND br.due_date < CURDATE() ORDER BY br.due_date',
            'voice-search' => 'SELECT v.id AS voice_search_id, v.user_id,
                               COALESCE(u.full_name, "Deleted user") AS user_name,
                               v.transcript, v.confidence, v.language, v.duration_seconds AS processing_seconds,
                               v.results_count, v.status, v.created_at
                               FROM voice_search_logs v LEFT JOIN users u ON u.id=v.user_id
                               ORDER BY v.created_at DESC',
            'tts' => 'SELECT t.id AS tts_event_id, t.user_id,
                      COALESCE(u.full_name, "Deleted user") AS user_name,
                      t.book_id, b.title AS book_title, t.text_length, t.provider, t.status, t.created_at
                      FROM tts_logs t LEFT JOIN users u ON u.id=t.user_id
                      LEFT JOIN books b ON b.id=t.book_id ORDER BY t.created_at DESC',
            'activity' => 'SELECT al.id AS activity_id, al.user_id,
                           COALESCE(u.full_name, "System") AS actor, r.code AS role_code,
                           al.action, al.entity_type, al.entity_id, al.status, al.created_at
                           FROM activity_logs al LEFT JOIN users u ON u.id=al.user_id
                           LEFT JOIN roles r ON r.id=u.role_id ORDER BY al.created_at DESC',
        ];
        if (!isset($map[$report])) {
            Response::error('Unknown report', 404);
        }
        Response::ok(pdo()->query($map[$report])->fetchAll());
    }

    if ($method === 'GET' && $path === '/languages') {
        Response::ok(class_exists('TranslationService') ? TranslationService::supportedLanguages() : []);
    }

    if ($method === 'GET' && $path === '/translation/health') {
        Response::ok(class_exists('TranslationService') ? TranslationService::health() : ['status' => 'unavailable']);
    }

    if ($method === 'POST' && $path === '/translation/translate') {
        enforce_rate_limit('translation', 80, 300);
        $user = current_user();
        $data = body();
        Validator::require($data, ['text', 'target_language']);
        $result = TranslationService::translate(
            (string)$data['text'],
            (string)$data['target_language'],
            (string)($data['source_language'] ?? 'en'),
            $user
        );
        ActivityLogService::log($user, 'translate_text', 'success', 'translation_request', $result['id'] ?? null, [
            'source_language' => $result['source_language'],
            'target_language' => $result['target_language'],
            'provider' => $result['provider'],
            'quality_status' => $result['quality_status'],
        ]);
        Response::ok($result, $result['quality_status'] === 'translated' ? 'Translation completed' : 'Translation completed with review recommended');
    }

    if (preg_match('#^/books/(\d+)/translate$#', $path, $m) && $method === 'POST') {
        enforce_rate_limit('book-translation', 20, 300);
        $user = current_user();
        $data = body();
        Validator::require($data, ['target_language']);
        $book = fetch_book(Validator::int($m[1]));
        $fileStmt = pdo()->prepare(
            'SELECT * FROM book_files
             WHERE book_id=:book_id AND status="active" AND file_type IN ("pdf", "docx", "txt")
             ORDER BY created_at DESC LIMIT 1'
        );
        $fileStmt->execute([':book_id' => (int)$book['id']]);
        $file = $fileStmt->fetch();
        if (!$file) {
            Response::error('No readable book file is available for translation', 404);
        }
        $stored = resolve_stored_upload($file);
        $extracted = extract_document_text($stored['absolute_path'], 500000);
        if (!$extracted || trim((string)($extracted['text'] ?? '')) === '') {
            Response::error('Book text could not be extracted for translation', 422);
        }
        $result = TranslationService::translateBookText(
            (string)$extracted['text'],
            (string)$data['target_language'],
            (string)($data['source_language'] ?? 'en'),
            $user
        );
        $result['book'] = [
            'id' => (int)$book['id'],
            'title' => $book['title'],
            'file_id' => (int)$file['id'],
            'extracted_characters' => (int)($extracted['characters'] ?? mb_strlen((string)$extracted['text'])),
        ];
        ActivityLogService::log($user, 'translate_book', 'success', 'book', (int)$book['id'], [
            'translation_request_id' => $result['id'] ?? null,
            'source_language' => $result['source_language'],
            'target_language' => $result['target_language'],
            'provider' => $result['provider'],
            'quality_status' => $result['quality_status'],
            'source_truncated' => $result['source_truncated'] ?? false,
        ]);
        Response::ok($result, 'Book translation completed');
    }
    Response::error('Endpoint not found', 404);
}

try {
    route($method, $path);
} catch (PDOException $e) {
    error_log('[Rwanda Library backend database] ' . $e->getMessage());
    Response::error('Database error. Check backend logs and database setup.', 500);
} catch (Throwable $e) {
    error_log('[Rwanda Library backend server] ' . $e->getMessage());
    Response::error('Server error. Check backend logs.', 500);
}







