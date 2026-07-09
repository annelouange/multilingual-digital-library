<?php

function generated_cover_relative_path(int $bookId): string
{
    return 'uploads/covers/generated/book-' . $bookId . '.svg';
}

function cover_text_lines(string $text, int $maxChars = 22, int $limit = 4): array
{
    $words = preg_split('/\s+/', trim($text)) ?: [];
    $lines = [];
    $current = '';
    foreach ($words as $word) {
        $candidate = trim($current . ' ' . $word);
        if ($current !== '' && strlen($candidate) > $maxChars) {
            $lines[] = $current;
            $current = $word;
            if (count($lines) >= $limit) {
                break;
            }
            continue;
        }
        $current = $candidate;
    }
    if ($current !== '' && count($lines) < $limit) {
        $lines[] = $current;
    }
    return $lines ?: ['Multilingual', 'Digital Library'];
}

function ensure_generated_book_cover(PDO $db, int $bookId, bool $force = false): ?string
{
    $stmt = $db->prepare(
        'SELECT b.id, b.title, b.subtitle, b.cover_image,
                COALESCE(a.full_name, "MULTILINGUAL DIGITAL LIBRARY") AS author,
                COALESCE(cat.name, "Academic Resource") AS category,
                COALESCE(f.name, "Rwanda Library Network") AS faculty
         FROM books b
         LEFT JOIN authors a ON a.id=b.author_id
         LEFT JOIN categories cat ON cat.id=b.category_id
         LEFT JOIN faculties f ON f.id=b.faculty_id
         WHERE b.id=:id LIMIT 1'
    );
    $stmt->execute([':id' => $bookId]);
    $book = $stmt->fetch();
    if (!$book) {
        return null;
    }
    if (!$force && !empty($book['cover_image'])) {
        return (string)$book['cover_image'];
    }

    $relativePath = generated_cover_relative_path($bookId);
    $directory = dirname(__DIR__) . '/uploads/covers/generated';
    if (!is_dir($directory) && !@mkdir($directory, 0775, true)) {
        return null;
    }

    $palette = [
        ['#0f3b2f', '#1f7a5f', '#f3d36b'],
        ['#153f5c', '#2a7ea5', '#f4d35e'],
        ['#4c2c72', '#7c4fd4', '#f6d365'],
        ['#5c2d2d', '#b35f47', '#f1c27d'],
        ['#113b4a', '#238276', '#e7c969'],
    ];
    $colors = $palette[abs(crc32((string)$book['title'])) % count($palette)];
    $titleLines = cover_text_lines((string)$book['title']);
    $titleSvg = '';
    $y = 185;
    foreach ($titleLines as $line) {
        $titleSvg .= '<text x="40" y="' . $y . '" class="title">' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</text>';
        $y += 36;
    }

    $category = htmlspecialchars((string)$book['category'], ENT_QUOTES, 'UTF-8');
    $author = htmlspecialchars((string)$book['author'], ENT_QUOTES, 'UTF-8');
    $initials = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string)$book['title']) ?: 'IN', 0, 2));

    $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="640" height="900" viewBox="0 0 640 900">
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0%" stop-color="{$colors[0]}"/>
      <stop offset="100%" stop-color="{$colors[1]}"/>
    </linearGradient>
    <style>
      .eyebrow { font: 700 22px Arial, sans-serif; fill: {$colors[2]}; letter-spacing: 4px; }
      .title { font: 800 32px Arial, sans-serif; fill: #ffffff; }
      .meta { font: 500 22px Arial, sans-serif; fill: rgba(255,255,255,.86); }
      .badge { font: 900 42px Arial, sans-serif; fill: {$colors[0]}; }
    </style>
  </defs>
  <rect width="640" height="900" rx="34" fill="url(#bg)"/>
  <circle cx="520" cy="98" r="118" fill="rgba(255,255,255,.08)"/>
  <circle cx="74" cy="790" r="142" fill="rgba(255,255,255,.07)"/>
  <rect x="40" y="50" width="116" height="116" rx="24" fill="#ffffff"/>
  <text x="98" y="122" text-anchor="middle" class="badge">{$initials}</text>
  <text x="40" y="224" class="eyebrow">MULTILINGUAL LIBRARY</text>
  {$titleSvg}
  <text x="40" y="560" class="meta">{$author}</text>
  <rect x="40" y="628" width="560" height="112" rx="24" fill="rgba(255,255,255,.13)" stroke="rgba(255,255,255,.24)"/>
  <text x="70" y="675" class="meta">{$category}</text>
  <text x="70" y="712" class="meta">Academic reading resource</text>
  <text x="40" y="820" class="eyebrow">SCIENTIA ET LUX</text>
</svg>
SVG;

    $path = $directory . '/book-' . $bookId . '.svg';
    if (file_put_contents($path, $svg) === false) {
        return null;
    }

    $update = $db->prepare('UPDATE books SET cover_image=:cover_image WHERE id=:id');
    $update->execute([':cover_image' => $relativePath, ':id' => $bookId]);
    return $relativePath;
}
