<?php
/**
 * MULTILINGUAL DIGITAL LIBRARY - Extract PDF Book Covers
 *
 * Renders the first page of each active PDF book as:
 *   backend/uploads/covers/book-{id}.jpg
 *
 * Safe behavior:
 * - --dry-run never writes files and never updates the database.
 * - Existing generated SVG covers remain as fallback when extraction fails.
 * - Only books with active PDF files are processed.
 */

$dryRun = in_array('--dry-run', $argv, true);
$force = in_array('--force', $argv, true);
$rootDir = dirname(__DIR__);
$coversDir = $rootDir . '/backend/uploads/covers';
$logFile = $rootDir . '/book_imports/logs/covers.log';

if (!is_dir(dirname($logFile))) {
    mkdir(dirname($logFile), 0755, true);
}
if (!$dryRun && !is_dir($coversDir)) {
    mkdir($coversDir, 0755, true);
}

require $rootDir . '/backend/helpers/Database.php';

function log_msg(string $message, string $level = 'INFO'): void
{
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . "] [$level] $message" . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

function active_pdf_books(PDO $db): array
{
    $stmt = $db->query(
        'SELECT b.id, b.title, b.cover_image, bf.file_path, bf.file_type
         FROM books b
         JOIN book_files bf ON bf.book_id=b.id AND bf.status="active"
         WHERE b.status="active"
           AND (bf.file_type="pdf" OR LOWER(bf.file_path) LIKE "%.pdf")
         GROUP BY b.id, b.title, b.cover_image, bf.file_path, bf.file_type
         ORDER BY b.id'
    );
    return $stmt->fetchAll();
}

function resolve_upload_path(string $relativePath, string $rootDir): ?string
{
    $clean = str_replace('\\', '/', trim($relativePath));
    $clean = ltrim($clean, '/');
    $candidates = [
        $rootDir . '/backend/' . $clean,
        $rootDir . '/' . $clean,
    ];
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }
    return null;
}

function python_has_cover_libs(): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }
    $code = 'import fitz; from PIL import Image';
    exec('python -c ' . escapeshellarg($code) . ' 2>NUL', $output, $status);
    $available = $status === 0;
    return $available;
}

function extract_cover_with_python(string $pdfPath, string $outputPath): array
{
    $helper = __DIR__ . '/extract_pdf_cover.py';
    $command = 'python ' . escapeshellarg($helper) . ' ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($outputPath);
    $output = [];
    exec($command . ' 2>&1', $output, $status);
    if ($status !== 0 || !is_file($outputPath)) {
        return ['ok' => false, 'reason' => 'PyMuPDF/Pillow extraction failed: ' . implode(' ', array_slice($output, -3))];
    }
    return ['ok' => true, 'size' => filesize($outputPath), 'tool' => 'python-fit/pillow'];
}

function extract_cover_with_imagick(string $pdfPath, string $outputPath): array
{
    if (!extension_loaded('imagick')) {
        return ['ok' => false, 'reason' => 'PHP imagick extension is not loaded'];
    }
    try {
        $imagick = new Imagick();
        $imagick->setResolution(150, 150);
        $imagick->readImage($pdfPath . '[0]');
        $imagick->setImageFormat('jpeg');
        $imagick->setImageQuality(85);
        if ($imagick->getImageWidth() > 600 || $imagick->getImageHeight() > 800) {
            $imagick->scaleImage(600, 800, true);
        }
        $imagick->writeImage($outputPath);
        $imagick->destroy();
        return ['ok' => true, 'size' => filesize($outputPath), 'tool' => 'imagick'];
    } catch (Throwable $e) {
        return ['ok' => false, 'reason' => 'Imagick extraction failed: ' . $e->getMessage()];
    }
}

function extract_pdf_cover(string $pdfPath, string $outputPath): array
{
    if (python_has_cover_libs()) {
        $result = extract_cover_with_python($pdfPath, $outputPath);
        if ($result['ok']) {
            return $result;
        }
        if (!extension_loaded('imagick')) {
            return $result;
        }
    }

    if (extension_loaded('imagick')) {
        return extract_cover_with_imagick($pdfPath, $outputPath);
    }

    return ['ok' => false, 'reason' => 'No local renderer available. Install PyMuPDF/Pillow, Ghostscript, or PHP imagick.'];
}

function update_cover(PDO $db, int $bookId, string $relativeCoverPath): void
{
    $stmt = $db->prepare('UPDATE books SET cover_image=:cover_image WHERE id=:id');
    $stmt->execute([':cover_image' => $relativeCoverPath, ':id' => $bookId]);
}

function format_bytes(int|float $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max((float)$bytes, 0);
    $pow = $bytes > 0 ? min((int)floor(log($bytes, 1024)), count($units) - 1) : 0;
    return round($bytes / (1024 ** $pow), 2) . ' ' . $units[$pow];
}

try {
    log_msg('=== Rwanda Library Book Cover Extractor ===');
    log_msg('Mode: ' . ($dryRun ? 'DRY RUN' : 'REAL') . ($force ? ' + FORCE' : ''));
    log_msg('Renderer: ' . (python_has_cover_libs() ? 'Python PyMuPDF/Pillow' : (extension_loaded('imagick') ? 'PHP Imagick' : 'none')));

    $db = Database::connection();
    $books = active_pdf_books($db);
    log_msg('Active PDF books found: ' . count($books));

    $stats = ['checked' => 0, 'extracted' => 0, 'skipped' => 0, 'failed' => 0, 'bytes' => 0];
    foreach ($books as $book) {
        $stats['checked']++;
        $bookId = (int)$book['id'];
        $title = trim((string)$book['title']);
        $pdfPath = resolve_upload_path((string)$book['file_path'], $rootDir);
        $relativeCoverPath = 'uploads/covers/book-' . $bookId . '.jpg';
        $absoluteCoverPath = $coversDir . '/book-' . $bookId . '.jpg';

        log_msg("[$bookId] $title");
        if (!$pdfPath) {
            log_msg('  FAIL: PDF file not found at ' . (string)$book['file_path'], 'WARN');
            $stats['failed']++;
            continue;
        }

        if (!$force && is_file($absoluteCoverPath)) {
            log_msg('  SKIP: Cover already exists. Use --force to refresh.');
            if (!$dryRun && (string)$book['cover_image'] !== $relativeCoverPath) {
                update_cover($db, $bookId, $relativeCoverPath);
                log_msg("  DB: Updated cover_image to '$relativeCoverPath'");
            }
            $stats['skipped']++;
            continue;
        }

        if ($dryRun) {
            log_msg('  WOULD EXTRACT: ' . basename($pdfPath) . " -> $relativeCoverPath");
            $stats['extracted']++;
            continue;
        }

        $result = extract_pdf_cover($pdfPath, $absoluteCoverPath);
        if (!$result['ok']) {
            log_msg('  FAIL: ' . $result['reason'], 'WARN');
            $stats['failed']++;
            continue;
        }

        update_cover($db, $bookId, $relativeCoverPath);
        $stats['extracted']++;
        $stats['bytes'] += (int)$result['size'];
        log_msg('  OK: Extracted with ' . $result['tool'] . ' (' . format_bytes((int)$result['size']) . ')');
        log_msg("  DB: Updated cover_image to '$relativeCoverPath'");
    }

    log_msg('=== SUMMARY ===');
    log_msg('PDF books checked: ' . $stats['checked']);
    log_msg(($dryRun ? 'Would extract' : 'Covers extracted') . ': ' . $stats['extracted']);
    log_msg('Skipped existing covers: ' . $stats['skipped']);
    log_msg('Failed: ' . $stats['failed']);
    log_msg('New cover bytes: ' . format_bytes($stats['bytes']));
    exit($stats['failed'] > 0 ? 1 : 0);
} catch (Throwable $e) {
    log_msg('ERROR: ' . $e->getMessage(), 'ERROR');
    exit(1);
}
