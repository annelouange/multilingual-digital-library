<?php

require __DIR__ . '/../backend/helpers/Database.php';

$options = getopt('', ['book-id::', 'file-id::', 'chunk-size::', 'limit::']);
$chunkSize = max(1000, (int)($options['chunk-size'] ?? 4500));
$limit = max(1, (int)($options['limit'] ?? 500));
$params = [];
$where = 'bf.status="active" AND bf.file_type IN ("pdf", "docx", "txt")';
if (!empty($options['book-id'])) {
    $where .= ' AND bf.book_id=:book_id';
    $params[':book_id'] = (int)$options['book-id'];
}
if (!empty($options['file-id'])) {
    $where .= ' AND bf.id=:file_id';
    $params[':file_id'] = (int)$options['file-id'];
}

$pdo = Database::connection();
$sql = "SELECT bf.*, b.institution_id FROM book_files bf JOIN books b ON b.id=bf.book_id WHERE {$where} ORDER BY bf.created_at DESC LIMIT {$limit}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$files = $stmt->fetchAll();
$indexed = 0;
$skipped = 0;

function absolute_upload_path(string $relative): ?string
{
    $candidate = realpath(__DIR__ . '/../backend/' . $relative);
    $uploadsRoot = realpath(__DIR__ . '/../backend/uploads');
    if (!$candidate || !$uploadsRoot) {
        return null;
    }
    return str_starts_with(str_replace('\\', '/', $candidate), str_replace('\\', '/', $uploadsRoot)) ? $candidate : null;
}

function summarize_chunk(string $text, int $max = 420): string
{
    $clean = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    if (mb_strlen($clean) <= $max) {
        return $clean;
    }
    $cut = mb_substr($clean, 0, $max);
    $boundary = max(mb_strrpos($cut, '. ') ?: 0, mb_strrpos($cut, '; ') ?: 0);
    return trim(mb_substr($cut, 0, $boundary > 120 ? $boundary + 1 : $max));
}

function extract_keywords(string $text, int $limit = 14): array
{
    $stop = array_flip(['the','and','for','with','from','that','this','are','was','were','have','has','not','but','you','your','their','into','about','book','chapter','section','page','can','will','all','our','more','these','those','which','when','where','what','how','why','who','use','used','using','dans','les','des','une','kwa','na','ya','wa','mu','ku']);
    $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $counts = [];
    foreach ($words as $word) {
        if (mb_strlen($word) < 4 || isset($stop[$word])) {
            continue;
        }
        $counts[$word] = ($counts[$word] ?? 0) + 1;
    }
    arsort($counts);
    return array_slice(array_keys($counts), 0, $limit);
}

function page_sidecars(string $path): array
{
    $files = glob($path . '.page-*.txt') ?: [];
    usort($files, function ($a, $b) {
        preg_match('/\.page-(\d+)\.txt$/', $a, $ma);
        preg_match('/\.page-(\d+)\.txt$/', $b, $mb);
        return ((int)($ma[1] ?? 0)) <=> ((int)($mb[1] ?? 0));
    });
    return $files;
}

$insert = $pdo->prepare(
    'INSERT INTO book_page_index
     (institution_id, book_id, file_id, page_number, chunk_number, language, content_hash, content_text, keywords, summary, topics, character_count, token_count, source_quality)
     VALUES (:institution_id, :book_id, :file_id, :page_number, :chunk_number, "en", :content_hash, :content_text, :keywords, :summary, :topics, :character_count, :token_count, "extracted")
     ON DUPLICATE KEY UPDATE content_hash=VALUES(content_hash), content_text=VALUES(content_text), keywords=VALUES(keywords), summary=VALUES(summary), topics=VALUES(topics), character_count=VALUES(character_count), token_count=VALUES(token_count), indexed_at=CURRENT_TIMESTAMP'
);

foreach ($files as $file) {
    $absolute = absolute_upload_path($file['file_path']);
    if (!$absolute) {
        $skipped++;
        continue;
    }
    $sources = [];
    foreach (page_sidecars($absolute) as $pageFile) {
        preg_match('/\.page-(\d+)\.txt$/', $pageFile, $match);
        $sources[] = ['page' => (int)($match[1] ?? 0), 'text' => trim((string)file_get_contents($pageFile))];
    }
    if (!$sources && is_file($absolute . '.content.txt')) {
        $sources[] = ['page' => 0, 'text' => trim((string)file_get_contents($absolute . '.content.txt'))];
    }
    if (!$sources) {
        $skipped++;
        continue;
    }

    foreach ($sources as $source) {
        $text = preg_replace('/\s+/u', ' ', $source['text']) ?? $source['text'];
        $length = mb_strlen($text);
        if ($length === 0) {
            continue;
        }
        $chunkNumber = 0;
        for ($offset = 0; $offset < $length; $offset += $chunkSize) {
            $chunk = trim(mb_substr($text, $offset, $chunkSize));
            if ($chunk === '') {
                continue;
            }
            $keywords = extract_keywords($chunk);
            $summary = summarize_chunk($chunk);
            $topics = array_slice($keywords, 0, 6);
            $tokenCount = count(preg_split('/\s+/u', trim($chunk), -1, PREG_SPLIT_NO_EMPTY) ?: []);
            $insert->execute([
                ':institution_id' => $file['institution_id'] ?? null,
                ':book_id' => (int)$file['book_id'],
                ':file_id' => (int)$file['id'],
                ':page_number' => (int)$source['page'],
                ':chunk_number' => $chunkNumber,
                ':content_hash' => hash('sha256', $chunk),
                ':content_text' => $chunk,
                ':keywords' => implode(', ', $keywords),
                ':summary' => $summary,
                ':topics' => json_encode($topics),
                ':character_count' => mb_strlen($chunk),
                ':token_count' => $tokenCount,
            ]);
            $indexed++;
            $chunkNumber++;
        }
    }
}

echo json_encode([
    'success' => true,
    'files_seen' => count($files),
    'chunks_indexed' => $indexed,
    'files_skipped' => $skipped,
    'chunk_size' => $chunkSize,
], JSON_PRETTY_PRINT) . PHP_EOL;