-- Metadata, retrieval-first AI, caching, and performance architecture additions.
-- Safe additive migration.

ALTER TABLE books ADD COLUMN IF NOT EXISTS edition VARCHAR(80) NULL AFTER isbn;
ALTER TABLE books ADD COLUMN IF NOT EXISTS language VARCHAR(12) NOT NULL DEFAULT 'en' AFTER publication_year;
ALTER TABLE books ADD COLUMN IF NOT EXISTS tags TEXT NULL AFTER keywords;
ALTER TABLE books ADD COLUMN IF NOT EXISTS total_pages INT UNSIGNED NULL AFTER cover_image;
ALTER TABLE books ADD COLUMN IF NOT EXISTS reading_level VARCHAR(80) NULL AFTER total_pages;
ALTER TABLE books ADD COLUMN IF NOT EXISTS has_audio TINYINT(1) NOT NULL DEFAULT 0 AFTER reading_level;
ALTER TABLE books ADD COLUMN IF NOT EXISTS has_translation TINYINT(1) NOT NULL DEFAULT 0 AFTER has_audio;
ALTER TABLE books ADD COLUMN IF NOT EXISTS has_summary TINYINT(1) NOT NULL DEFAULT 0 AFTER has_translation;
ALTER TABLE books ADD COLUMN IF NOT EXISTS metadata_quality_score TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER has_summary;
ALTER TABLE books ADD COLUMN IF NOT EXISTS last_indexed_at TIMESTAMP NULL AFTER metadata_quality_score;

ALTER TABLE books ADD INDEX IF NOT EXISTS idx_books_language (language);
ALTER TABLE books ADD INDEX IF NOT EXISTS idx_books_metadata_flags (has_audio, has_translation, has_summary);
ALTER TABLE books ADD INDEX IF NOT EXISTS idx_books_reading_level (reading_level);
ALTER TABLE books ADD FULLTEXT KEY IF NOT EXISTS ft_books_tags (tags);

ALTER TABLE book_page_index ADD COLUMN IF NOT EXISTS keywords TEXT NULL AFTER content_text;
ALTER TABLE book_page_index ADD COLUMN IF NOT EXISTS summary TEXT NULL AFTER keywords;
ALTER TABLE book_page_index ADD COLUMN IF NOT EXISTS topics JSON NULL AFTER summary;
ALTER TABLE book_page_index ADD COLUMN IF NOT EXISTS embedding_vector LONGTEXT NULL AFTER topics;
ALTER TABLE book_page_index ADD COLUMN IF NOT EXISTS token_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER character_count;
ALTER TABLE book_page_index ADD COLUMN IF NOT EXISTS source_quality ENUM('extracted','ocr','manual','unknown') NOT NULL DEFAULT 'extracted' AFTER token_count;
ALTER TABLE book_page_index ADD INDEX IF NOT EXISTS idx_book_page_language (language);
ALTER TABLE book_page_index ADD INDEX IF NOT EXISTS idx_book_page_quality (source_quality);
ALTER TABLE book_page_index ADD FULLTEXT KEY IF NOT EXISTS ft_book_page_keywords (keywords, summary);

CREATE TABLE IF NOT EXISTS book_keywords (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institution_id BIGINT UNSIGNED NULL,
  book_id BIGINT UNSIGNED NOT NULL,
  keyword VARCHAR(160) NOT NULL,
  source ENUM('metadata','content','ai','manual') NOT NULL DEFAULT 'metadata',
  weight DECIMAL(8,4) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_book_keyword (book_id, keyword, source),
  KEY idx_book_keywords_keyword (keyword),
  KEY idx_book_keywords_institution (institution_id),
  CONSTRAINT fk_book_keywords_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
  CONSTRAINT fk_book_keywords_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS book_embeddings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institution_id BIGINT UNSIGNED NULL,
  book_id BIGINT UNSIGNED NOT NULL,
  page_index_id BIGINT UNSIGNED NULL,
  embedding_model VARCHAR(190) NOT NULL,
  embedding_dimensions SMALLINT UNSIGNED NOT NULL,
  vector LONGTEXT NOT NULL,
  vector_hash CHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_book_embedding (embedding_model, vector_hash),
  KEY idx_book_embeddings_book (book_id),
  KEY idx_book_embeddings_page (page_index_id),
  KEY idx_book_embeddings_institution (institution_id),
  CONSTRAINT fk_book_embeddings_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
  CONSTRAINT fk_book_embeddings_page FOREIGN KEY (page_index_id) REFERENCES book_page_index(id) ON DELETE CASCADE,
  CONSTRAINT fk_book_embeddings_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS model_fallback_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institution_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NULL,
  task ENUM('translation','stt','tts','search','qa','summary') NOT NULL,
  primary_provider VARCHAR(120) NOT NULL,
  primary_model VARCHAR(190) NULL,
  fallback_provider VARCHAR(120) NOT NULL,
  fallback_model VARCHAR(190) NULL,
  reason VARCHAR(255) NULL,
  latency_ms INT UNSIGNED NULL,
  status ENUM('used','failed','skipped') NOT NULL DEFAULT 'used',
  metadata JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_model_fallback_task (task, created_at),
  KEY idx_model_fallback_institution (institution_id),
  KEY idx_model_fallback_user (user_id),
  CONSTRAINT fk_model_fallback_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE SET NULL,
  CONSTRAINT fk_model_fallback_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_response_cache (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institution_id BIGINT UNSIGNED NULL,
  cache_key CHAR(64) NOT NULL,
  task ENUM('translation','stt','tts','search','qa','summary','recommendation') NOT NULL,
  language VARCHAR(12) NULL,
  provider VARCHAR(120) NULL,
  model_name VARCHAR(190) NULL,
  input_hash CHAR(64) NOT NULL,
  response_json JSON NOT NULL,
  hit_count INT UNSIGNED NOT NULL DEFAULT 0,
  expires_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ai_cache_key (cache_key),
  KEY idx_ai_cache_task (task, language, expires_at),
  KEY idx_ai_cache_institution (institution_id),
  CONSTRAINT fk_ai_cache_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE OR REPLACE VIEW book_metadata AS
SELECT
  b.id AS book_id,
  b.institution_id,
  b.title,
  b.subtitle,
  a.full_name AS author,
  b.publisher,
  b.isbn,
  b.edition,
  b.publication_year,
  b.language,
  f.name AS faculty,
  d.name AS department,
  c.name AS course,
  cat.name AS category,
  b.keywords,
  b.tags,
  b.description,
  b.access_level,
  b.license_type,
  b.created_by AS uploaded_by,
  b.total_pages,
  b.reading_level,
  b.has_audio,
  b.has_translation,
  b.has_summary,
  b.metadata_quality_score,
  b.last_indexed_at
FROM books b
LEFT JOIN authors a ON a.id=b.author_id
LEFT JOIN categories cat ON cat.id=b.category_id
LEFT JOIN faculties f ON f.id=b.faculty_id
LEFT JOIN departments d ON d.id=b.department_id
LEFT JOIN courses c ON c.id=b.course_id;

CREATE OR REPLACE VIEW book_chunks AS
SELECT
  bpi.id AS chunk_id,
  bpi.institution_id,
  bpi.book_id,
  bpi.file_id,
  bpi.page_number,
  bpi.chunk_number,
  bpi.language,
  bpi.content_text AS content,
  bpi.keywords,
  bpi.summary,
  bpi.topics,
  bpi.embedding_vector,
  bpi.character_count,
  bpi.token_count,
  bpi.source_quality,
  bpi.indexed_at AS created_at
FROM book_page_index bpi;

UPDATE books b
LEFT JOIN (
  SELECT book_id, MAX(page_number) AS total_pages
  FROM book_page_index
  WHERE page_number > 0
  GROUP BY book_id
) p ON p.book_id=b.id
LEFT JOIN (
  SELECT book_id, COUNT(*) AS audio_count
  FROM book_files
  WHERE status='active' AND file_type='audio'
  GROUP BY book_id
) af ON af.book_id=b.id
LEFT JOIN (
  SELECT book_id, MAX(indexed_at) AS last_indexed_at
  FROM book_page_index
  GROUP BY book_id
) ix ON ix.book_id=b.id
SET
  b.total_pages = COALESCE(b.total_pages, p.total_pages),
  b.has_audio = IF(COALESCE(af.audio_count, 0) > 0, 1, b.has_audio),
  b.last_indexed_at = COALESCE(b.last_indexed_at, ix.last_indexed_at),
  b.metadata_quality_score = LEAST(100,
    (CASE WHEN b.title IS NOT NULL AND b.title <> '' THEN 15 ELSE 0 END) +
    (CASE WHEN b.author_id IS NOT NULL THEN 10 ELSE 0 END) +
    (CASE WHEN b.publisher IS NOT NULL AND b.publisher <> '' THEN 8 ELSE 0 END) +
    (CASE WHEN b.isbn IS NOT NULL AND b.isbn <> '' THEN 8 ELSE 0 END) +
    (CASE WHEN b.publication_year IS NOT NULL THEN 8 ELSE 0 END) +
    (CASE WHEN b.language IS NOT NULL AND b.language <> '' THEN 8 ELSE 0 END) +
    (CASE WHEN b.faculty_id IS NOT NULL THEN 8 ELSE 0 END) +
    (CASE WHEN b.department_id IS NOT NULL THEN 8 ELSE 0 END) +
    (CASE WHEN b.category_id IS NOT NULL THEN 8 ELSE 0 END) +
    (CASE WHEN b.keywords IS NOT NULL AND b.keywords <> '' THEN 9 ELSE 0 END) +
    (CASE WHEN b.description IS NOT NULL AND b.description <> '' THEN 10 ELSE 0 END)
  );
