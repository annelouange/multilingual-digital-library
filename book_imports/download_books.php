<?php
/**
 * MULTILINGUAL DIGITAL LIBRARY - robust verified book downloader.
 *
 * Reads book_imports/verified_books.csv and downloads files into
 * book_imports/books/. Files are streamed to disk, first into *.part, and
 * accepted only when size and file signature/content look valid.
 */

$csvFile = __DIR__ . DIRECTORY_SEPARATOR . 'verified_books.csv';
$booksDir = __DIR__ . DIRECTORY_SEPARATOR . 'books';
$logFile = __DIR__ . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'download.log';

if (!is_dir(dirname($logFile))) {
    mkdir(dirname($logFile), 0755, true);
}
if (!is_dir($booksDir)) {
    mkdir($booksDir, 0755, true);
}

function log_msg(string $message, string $level = 'INFO'): void
{
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . "] [$level] $message\n";
    file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

function parse_csv(string $file): array
{
    if (!is_file($file)) {
        throw new RuntimeException("CSV file not found: {$file}");
    }

    $handle = fopen($file, 'r');
    $header = fgetcsv($handle);
    $books = [];

    while (($row = fgetcsv($handle)) !== false) {
        if (count(array_filter($row, static fn($value) => trim((string)$value) !== '')) === 0) {
            continue;
        }
        $row = array_pad($row, count($header), '');
        $books[] = array_combine($header, $row);
    }

    fclose($handle);
    return $books;
}

function local_path(array $book): string
{
    global $booksDir;
    return $booksDir . DIRECTORY_SEPARATOR . basename((string)$book['file_path']);
}

function validate_downloaded_file(string $path, string $expectedExt): array
{
    if (!is_file($path)) {
        return ['ok' => false, 'message' => 'file missing'];
    }

    $size = filesize($path);
    $minimum = $expectedExt === 'txt' ? 1024 : 50000;
    if ($size < $minimum) {
        return ['ok' => false, 'message' => "file too small ({$size} bytes)"];
    }

    $handle = fopen($path, 'rb');
    $head = $handle ? fread($handle, 512) : '';
    if ($handle) {
        fclose($handle);
    }

    if ($expectedExt === 'pdf' && !str_starts_with($head, '%PDF-')) {
        return ['ok' => false, 'message' => 'expected PDF signature but file is not PDF'];
    }

    if ($expectedExt === 'txt' && preg_match('/<html|<!doctype html/i', $head)) {
        return ['ok' => false, 'message' => 'expected TXT but downloaded HTML'];
    }

    return ['ok' => true, 'size' => $size];
}

function download_stream(string $url, string $destination): array
{
    $tmp = $destination . '.part';
    @unlink($tmp);

    $resumeFrom = is_file($tmp) ? filesize($tmp) : 0;
    $out = fopen($tmp, $resumeFrom > 0 ? 'ab' : 'wb');
    if (!$out) {
        return ['ok' => false, 'message' => "could not open temp file {$tmp}"];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $out,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 8,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT => 3600,
        CURLOPT_USERAGENT => 'Rwanda Library-Digital-Library-Importer/1.0',
        CURLOPT_FAILONERROR => false,
        CURLOPT_LOW_SPEED_LIMIT => 1024,
        CURLOPT_LOW_SPEED_TIME => 120,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($resumeFrom > 0) {
        curl_setopt($ch, CURLOPT_RESUME_FROM, $resumeFrom);
    }

    $ok = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = curl_error($ch);
    curl_close($ch);
    fclose($out);

    if (!$ok || $status < 200 || $status >= 300) {
        @unlink($tmp);
        return ['ok' => false, 'message' => "HTTP {$status} {$error}"];
    }

    if (!rename($tmp, $destination)) {
        @unlink($tmp);
        return ['ok' => false, 'message' => 'could not move temp file into place'];
    }

    return ['ok' => true, 'status' => $status, 'content_type' => $contentType];
}

function format_bytes(int|float $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = (int)floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    return round($bytes / (1 << (10 * $pow)), 2) . ' ' . $units[$pow];
}

try {
    log_msg('=== Rwanda Library verified book downloader ===');
    log_msg("CSV: {$csvFile}");
    log_msg("Destination: {$booksDir}");

    $books = parse_csv($csvFile);
    log_msg('Books in CSV: ' . count($books));

    $stats = ['downloaded' => 0, 'existing' => 0, 'failed' => 0, 'bytes' => 0];

    foreach ($books as $index => $book) {
        $number = $index + 1;
        $title = trim((string)$book['title']);
        $url = trim((string)$book['source_url']);
        $destination = local_path($book);
        $expectedExt = strtolower(pathinfo($destination, PATHINFO_EXTENSION));

        log_msg("[{$number}] {$title}");

        if (!in_array($expectedExt, ['pdf', 'txt'], true)) {
            log_msg("  FAIL: unsupported expected extension {$expectedExt}", 'ERROR');
            $stats['failed']++;
            continue;
        }

        $existing = validate_downloaded_file($destination, $expectedExt);
        if ($existing['ok']) {
            log_msg('  OK: already exists (' . format_bytes($existing['size']) . ')');
            $stats['existing']++;
            $stats['bytes'] += $existing['size'];
            continue;
        }

        log_msg("  Downloading: {$url}");
        $download = download_stream($url, $destination);
        if (!$download['ok']) {
            log_msg('  FAIL: ' . $download['message'], 'ERROR');
            $stats['failed']++;
            continue;
        }

        $valid = validate_downloaded_file($destination, $expectedExt);
        if (!$valid['ok']) {
            @unlink($destination);
            log_msg('  FAIL: ' . $valid['message'] . ' (' . ($download['content_type'] ?? 'unknown content-type') . ')', 'ERROR');
            $stats['failed']++;
            continue;
        }

        log_msg('  OK: downloaded ' . format_bytes($valid['size']) . ' (' . ($download['content_type'] ?? 'unknown content-type') . ')');
        $stats['downloaded']++;
        $stats['bytes'] += $valid['size'];
    }

    log_msg('=== SUMMARY ===');
    log_msg('Downloaded: ' . $stats['downloaded']);
    log_msg('Already existing: ' . $stats['existing']);
    log_msg('Failed: ' . $stats['failed']);
    log_msg('Verified size: ' . format_bytes($stats['bytes']));

    exit($stats['failed'] > 0 ? 1 : 0);
} catch (Throwable $error) {
    log_msg($error->getMessage(), 'ERROR');
    exit(1);
}
