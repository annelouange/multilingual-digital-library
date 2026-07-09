<?php

require __DIR__ . '/../backend/helpers/Database.php';
require __DIR__ . '/../backend/helpers/BookCover.php';

$force = in_array('--force', $argv, true);
$db = Database::connection();
$stmt = $db->query('SELECT id FROM books WHERE status <> "deleted" ORDER BY id');
$bookIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

$created = 0;
$skipped = 0;
foreach ($bookIds as $bookId) {
    $before = $db->prepare('SELECT cover_image FROM books WHERE id=:id');
    $before->execute([':id' => $bookId]);
    $existing = (string)($before->fetchColumn() ?: '');
    $cover = ensure_generated_book_cover($db, $bookId, $force);
    if ($cover && ($force || $existing === '')) {
        $created++;
    } else {
        $skipped++;
    }
}

echo json_encode([
    'books_seen' => count($bookIds),
    'covers_created_or_refreshed' => $created,
    'covers_skipped' => $skipped,
    'force' => $force,
], JSON_PRETTY_PRINT) . PHP_EOL;
