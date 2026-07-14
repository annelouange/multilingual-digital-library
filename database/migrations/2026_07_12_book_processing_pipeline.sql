-- Safe additive foundation for restartable book translation and listening jobs.

CREATE TABLE IF NOT EXISTS translation_jobs (
  id CHAR(36) PRIMARY KEY,
  book_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  source_language VARCHAR(10) NOT NULL,
  target_language VARCHAR(10) NOT NULL,
  model_name VARCHAR(255) NOT NULL,
  model_version VARCHAR(100) NULL,
  quality_mode ENUM('fast','balanced','accurate') NOT NULL DEFAULT 'balanced',
  generate_audio TINYINT(1) NOT NULL DEFAULT 0,
  preserve_layout TINYINT(1) NOT NULL DEFAULT 1,
  status ENUM('queued','extracting','segmenting','translating','validating','reconstructing','generating_audio','completed','failed','cancelled') NOT NULL DEFAULT 'queued',
  total_segments INT UNSIGNED NOT NULL DEFAULT 0,
  completed_segments INT UNSIGNED NOT NULL DEFAULT 0,
  failed_segments INT UNSIGNED NOT NULL DEFAULT 0,
  progress_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
  error_message TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_translation_jobs_book (book_id),
  INDEX idx_translation_jobs_user (user_id),
  INDEX idx_translation_jobs_status (status),
  CONSTRAINT fk_translation_jobs_book FOREIGN KEY (book_id) REFERENCES books(id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_translation_jobs_user FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS translation_segments (
  id CHAR(36) PRIMARY KEY,
  job_id CHAR(36) NOT NULL,
  chapter_number INT UNSIGNED NULL,
  section_number INT UNSIGNED NULL,
  paragraph_number INT UNSIGNED NULL,
  segment_order INT UNSIGNED NOT NULL,
  source_text LONGTEXT NOT NULL,
  translated_text LONGTEXT NULL,
  source_token_count INT UNSIGNED NULL,
  target_token_count INT UNSIGNED NULL,
  validation_score DECIMAL(5,4) NULL,
  retry_count INT UNSIGNED NOT NULL DEFAULT 0,
  checksum VARCHAR(64) NOT NULL,
  status ENUM('queued','processing','translated','needs_review','failed','skipped') NOT NULL DEFAULT 'queued',
  error_message TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_translation_segment_order (job_id, segment_order),
  UNIQUE KEY uq_translation_segment_checksum (job_id, checksum),
  INDEX idx_translation_segments_status (status),
  CONSTRAINT fk_translation_segments_job FOREIGN KEY (job_id) REFERENCES translation_jobs(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tts_segments (
  id CHAR(36) PRIMARY KEY,
  translation_segment_id CHAR(36) NULL,
  book_id BIGINT UNSIGNED NOT NULL,
  paragraph_id VARCHAR(100) NULL,
  language VARCHAR(10) NOT NULL,
  segment_order INT UNSIGNED NOT NULL,
  text_content TEXT NOT NULL,
  audio_path VARCHAR(500) NULL,
  duration_seconds DECIMAL(10,3) NULL,
  checksum VARCHAR(64) NOT NULL,
  status ENUM('queued','generating','generated','validating','completed','failed') NOT NULL DEFAULT 'queued',
  retry_count INT UNSIGNED NOT NULL DEFAULT 0,
  error_message TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_tts_segments_book (book_id),
  INDEX idx_tts_segments_status (status),
  CONSTRAINT fk_tts_segments_book FOREIGN KEY (book_id) REFERENCES books(id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_tts_segments_translation FOREIGN KEY (translation_segment_id) REFERENCES translation_segments(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS translation_corrections (
  id CHAR(36) PRIMARY KEY,
  segment_id CHAR(36) NOT NULL,
  machine_translation LONGTEXT NOT NULL,
  corrected_translation LONGTEXT NOT NULL,
  corrected_by BIGINT UNSIGNED NOT NULL,
  approved TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_translation_corrections_segment (segment_id),
  CONSTRAINT fk_translation_corrections_segment FOREIGN KEY (segment_id) REFERENCES translation_segments(id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_translation_corrections_user FOREIGN KEY (corrected_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
