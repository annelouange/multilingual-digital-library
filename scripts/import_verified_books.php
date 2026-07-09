<?php
/**
 * MULTILINGUAL DIGITAL LIBRARY - verified book importer.
 *
 * Safe by default:
 * - --dry-run validates only; it does not write to the database or copy files.
 * - --execute imports after the files are downloaded and validation passes.
 * - No DROP, DELETE, or destructive operations.
 * - Uses the real Rwanda Library schema in database/database.sql.
 */

$mode = $argv[1] ?? '';
$dryRun = $mode === '--dry-run';
$execute = $mode === '--execute';

if (!$dryRun && !$execute) {
    echo "Usage:\n";
    echo "  C:\\xampp\\php\\php.exe scripts\\import_verified_books.php --dry-run\n";
    echo "  C:\\xampp\\php\\php.exe scripts\\import_verified_books.php --execute\n";
    exit(0);
}

$root = dirname(__DIR__);
$importDir = $root . DIRECTORY_SEPARATOR . 'book_imports';
$csvFile = $importDir . DIRECTORY_SEPARATOR . 'verified_books.csv';
$booksDir = $importDir . DIRECTORY_SEPARATOR . 'books';
$backendBooksDir = $root . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'books';
$logFile = $importDir . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'import.log';

if (!is_dir(dirname($logFile))) {
    mkdir(dirname($logFile), 0755, true);
}

function log_message(string $message, string $level = 'INFO'): void
{
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . "] [$level] $message\n";
    file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

function db(): PDO
{
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $name = getenv('DB_NAME') ?: 'multilingual_digital_library';
    $user = getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASSWORD');
    $pass = $pass === false ? '' : $pass;

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    return $pdo;
}

function parse_books(string $csvFile): array
{
    if (!is_file($csvFile)) {
        throw new RuntimeException("CSV file not found: {$csvFile}");
    }

    $handle = fopen($csvFile, 'r');
    if (!$handle) {
        throw new RuntimeException("Could not open CSV file: {$csvFile}");
    }

    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        return [];
    }

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

function normalize_ext(string $path): string
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($ext, ['pdf', 'docx', 'txt'], true) ? $ext : 'other';
}

function mime_for_ext(string $ext): string
{
    return match ($ext) {
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'txt' => 'text/plain',
        default => 'application/octet-stream',
    };
}

function local_book_path(array $book): string
{
    global $importDir;
    $relative = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim((string)$book['file_path']));
    return $importDir . DIRECTORY_SEPARATOR . $relative;
}

function fetch_id(PDO $pdo, string $sql, array $params): ?int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int)$id;
}

function get_or_create_author(PDO $pdo, string $name, bool $dryRun): ?int
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }

    $id = fetch_id($pdo, 'SELECT id FROM authors WHERE full_name = :name LIMIT 1', [':name' => $name]);
    if ($id || $dryRun) {
        return $id;
    }

    $stmt = $pdo->prepare('INSERT INTO authors (full_name, status) VALUES (:name, "active")');
    $stmt->execute([':name' => $name]);
    return (int)$pdo->lastInsertId();
}

function get_or_create_category(PDO $pdo, string $name, bool $dryRun): ?int
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }

    $id = fetch_id($pdo, 'SELECT id FROM categories WHERE name = :name LIMIT 1', [':name' => $name]);
    if ($id || $dryRun) {
        return $id;
    }

    $stmt = $pdo->prepare('INSERT INTO categories (name, status) VALUES (:name, "active")');
    $stmt->execute([':name' => $name]);
    return (int)$pdo->lastInsertId();
}

function find_faculty(PDO $pdo, string $name): ?int
{
    return fetch_id($pdo, 'SELECT id FROM faculties WHERE name = :name AND status = "active" LIMIT 1', [':name' => trim($name)]);
}

function find_department(PDO $pdo, ?int $facultyId, string $name): ?int
{
    if (!$facultyId || trim($name) === '') {
        return null;
    }

    return fetch_id(
        $pdo,
        'SELECT id FROM departments WHERE faculty_id = :faculty_id AND name = :name AND status = "active" LIMIT 1',
        [':faculty_id' => $facultyId, ':name' => trim($name)]
    );
}

function find_course(PDO $pdo, ?int $departmentId, string $name): ?int
{
    if (!$departmentId || trim($name) === '') {
        return null;
    }

    return fetch_id(
        $pdo,
        'SELECT id FROM courses WHERE department_id = :department_id AND name = :name AND status = "active" LIMIT 1',
        [':department_id' => $departmentId, ':name' => trim($name)]
    );
}

function existing_book_id(PDO $pdo, string $title, string $author): ?int
{
    $stmt = $pdo->prepare(
        'SELECT b.id
         FROM books b
         LEFT JOIN authors a ON a.id = b.author_id
         WHERE b.title = :title
           AND b.status <> "deleted"
           AND (:author = "" OR a.full_name = :author)
         LIMIT 1'
    );
    $stmt->execute([':title' => trim($title), ':author' => trim($author)]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int)$id;
}

function validate_file(array $book): array
{
    $path = local_book_path($book);
    if (!is_file($path)) {
        return ['ok' => false, 'path' => $path, 'message' => 'missing local file'];
    }

    $size = filesize($path);
    $ext = normalize_ext($path);
    $minimum = $ext === 'txt' ? 1024 : 50000;
    if ($size < $minimum) {
        return ['ok' => false, 'path' => $path, 'message' => "file too small ({$size} bytes)"];
    }

    return ['ok' => true, 'path' => $path, 'size' => $size, 'ext' => $ext];
}

function import_book(PDO $pdo, array $book, array $resolved, array $file): int
{
    global $backendBooksDir;

    $pdo->beginTransaction();
    try {
        $authorId = get_or_create_author($pdo, (string)$book['author'], false);
        $categoryId = get_or_create_category($pdo, (string)$book['category'], false);

        $keywords = trim((string)$book['keywords']);
        $license = trim((string)($book['license'] ?? ''));
        $source = trim((string)($book['source_url'] ?? ''));
        $licenseUrl = trim((string)($book['license_url'] ?? ''));
        $extraKeywords = array_filter([$keywords, $license, $source, $licenseUrl]);

        $stmt = $pdo->prepare(
            'INSERT INTO books
             (author_id, category_id, faculty_id, department_id, course_id, title, publication_year,
              description, keywords, total_copies, available_copies, status)
             VALUES
             (:author_id, :category_id, :faculty_id, :department_id, :course_id, :title, :publication_year,
              :description, :keywords, 1, 1, "active")'
        );
        $stmt->execute([
            ':author_id' => $authorId,
            ':category_id' => $categoryId,
            ':faculty_id' => $resolved['faculty_id'],
            ':department_id' => $resolved['department_id'],
            ':course_id' => $resolved['course_id'],
            ':title' => trim((string)$book['title']),
            ':publication_year' => (int)$book['publication_year'] ?: null,
            ':description' => trim((string)$book['description']),
            ':keywords' => implode('; ', $extraKeywords),
        ]);

        $bookId = (int)$pdo->lastInsertId();
        $targetDir = $backendBooksDir . DIRECTORY_SEPARATOR . $bookId;
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
            throw new RuntimeException("Could not create {$targetDir}");
        }

        $target = $targetDir . DIRECTORY_SEPARATOR . basename($file['path']);
        if (!copy($file['path'], $target)) {
            throw new RuntimeException("Could not copy {$file['path']} to {$target}");
        }

        $relativePath = 'uploads/books/' . $bookId . '/' . basename($target);
        $stmt = $pdo->prepare(
            'INSERT INTO book_files
             (book_id, file_type, file_path, original_name, mime_type, file_size, status)
             VALUES
             (:book_id, :file_type, :file_path, :original_name, :mime_type, :file_size, "active")'
        );
        $stmt->execute([
            ':book_id' => $bookId,
            ':file_type' => $file['ext'],
            ':file_path' => $relativePath,
            ':original_name' => basename($target),
            ':mime_type' => mime_for_ext($file['ext']),
            ':file_size' => $file['size'],
        ]);

        $pdo->commit();
        return $bookId;
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

try {
    log_message('=== Rwanda Library verified book import: ' . ($dryRun ? 'DRY RUN' : 'EXECUTE') . ' ===');
    log_message("CSV: {$csvFile}");
    log_message("Books directory: {$booksDir}");
    log_message("Backend uploads: {$backendBooksDir}");

    $books = parse_books($csvFile);
    $pdo = db();
    log_message('Database connected');
    log_message('Books in CSV: ' . count($books));

    $stats = [
        'valid' => 0,
        'imported' => 0,
        'missing_files' => 0,
        'duplicates' => 0,
        'errors' => 0,
        'warnings' => 0,
        'bytes' => 0,
    ];

    foreach ($books as $index => $book) {
        $number = $index + 1;
        $title = trim((string)$book['title']);
        log_message("[{$number}] {$title}");

        $facultyId = find_faculty($pdo, (string)$book['faculty']);
        if (!$facultyId) {
            log_message("  ERROR: faculty not found: {$book['faculty']}", 'ERROR');
            $stats['errors']++;
            continue;
        }

        $departmentId = find_department($pdo, $facultyId, (string)$book['department']);
        if (!$departmentId && trim((string)$book['department']) !== '') {
            log_message("  WARN: department not found under faculty, importing with faculty only: {$book['department']}", 'WARN');
            $stats['warnings']++;
        }

        $courseId = find_course($pdo, $departmentId, (string)$book['course']);
        if (!$courseId && $departmentId && trim((string)$book['course']) !== '') {
            log_message("  WARN: course not found under department, importing without course: {$book['course']}", 'WARN');
            $stats['warnings']++;
        }

        $existing = existing_book_id($pdo, $title, (string)$book['author']);
        if ($existing) {
            log_message("  SKIP: duplicate existing book id {$existing}", 'SKIP');
            $stats['duplicates']++;
            continue;
        }

        $file = validate_file($book);
        if (!$file['ok']) {
            log_message("  SKIP: {$file['message']} at {$file['path']}", 'SKIP');
            $stats['missing_files']++;
            continue;
        }

        $resolved = [
            'faculty_id' => $facultyId,
            'department_id' => $departmentId,
            'course_id' => $courseId,
        ];

        if ($dryRun) {
            log_message('  OK: validated; would import');
            $stats['valid']++;
            $stats['bytes'] += $file['size'];
            continue;
        }

        $bookId = import_book($pdo, $book, $resolved, $file);
        log_message("  OK: imported as book id {$bookId}");
        $stats['imported']++;
        $stats['bytes'] += $file['size'];
    }

    log_message('=== SUMMARY ===');
    log_message('Valid ready files: ' . $stats['valid']);
    log_message('Imported: ' . $stats['imported']);
    log_message('Missing/skipped files: ' . $stats['missing_files']);
    log_message('Duplicates: ' . $stats['duplicates']);
    log_message('Warnings: ' . $stats['warnings']);
    log_message('Errors: ' . $stats['errors']);
    log_message('Validated/imported size: ' . round($stats['bytes'] / 1048576, 2) . ' MB');

    exit($stats['errors'] > 0 || $stats['missing_files'] > 0 ? 1 : 0);
} catch (Throwable $error) {
    log_message($error->getMessage(), 'ERROR');
    exit(1);
}
