-- Cache and timing tables for low-latency translation and narration.

CREATE TABLE IF NOT EXISTS translation_cache (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  book_id BIGINT UNSIGNED NULL,
  page_number INT UNSIGNED NULL,
  source_language VARCHAR(20) NOT NULL,
  target_language VARCHAR(20) NOT NULL,
  source_hash CHAR(64) NOT NULL,
  translated_text LONGTEXT NOT NULL,
  model_name VARCHAR(255) NOT NULL,
  model_version VARCHAR(80) NOT NULL DEFAULT 'default',
  provider VARCHAR(100) NOT NULL,
  processing_ms INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_translation_cache_content (book_id, page_number, source_language, target_language, source_hash, model_version),
  INDEX idx_translation_cache_lookup (source_language, target_language, source_hash),
  CONSTRAINT fk_translation_cache_book FOREIGN KEY (book_id) REFERENCES books(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS narration_cache (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  book_id BIGINT UNSIGNED NULL,
  page_number INT UNSIGNED NULL,
  language VARCHAR(20) NOT NULL,
  voice VARCHAR(80) NOT NULL DEFAULT 'default',
  speed DECIMAL(4,2) NOT NULL DEFAULT 1.00,
  source_hash CHAR(64) NOT NULL,
  cache_key CHAR(64) NOT NULL,
  provider VARCHAR(100) NOT NULL,
  model_name VARCHAR(255) NULL,
  model_version VARCHAR(80) NOT NULL DEFAULT 'default',
  audio_path VARCHAR(500) NOT NULL,
  mime_type VARCHAR(80) NOT NULL,
  file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  duration_seconds DECIMAL(10,3) NULL,
  processing_ms INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_narration_cache_key (cache_key),
  INDEX idx_narration_cache_lookup (language, voice, source_hash),
  CONSTRAINT fk_narration_cache_book FOREIGN KEY (book_id) REFERENCES books(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_processing_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  book_id BIGINT UNSIGNED NULL,
  page_number INT UNSIGNED NULL,
  job_type ENUM('translation','tts') NOT NULL,
  status ENUM('queued','processing','completed','failed','cancelled') NOT NULL DEFAULT 'queued',
  source_language VARCHAR(20) NULL,
  target_language VARCHAR(20) NULL,
  provider VARCHAR(100) NULL,
  cache_key CHAR(64) NULL,
  output_path TEXT NULL,
  processing_ms INT UNSIGNED NULL,
  error_message TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at TIMESTAMP NULL,
  completed_at TIMESTAMP NULL,
  INDEX idx_ai_processing_jobs_status (status),
  INDEX idx_ai_processing_jobs_user (user_id),
  INDEX idx_ai_processing_jobs_book (book_id),
  CONSTRAINT fk_ai_processing_jobs_user FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_ai_processing_jobs_book FOREIGN KEY (book_id) REFERENCES books(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS model_versions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  service_name VARCHAR(80) NOT NULL,
  provider VARCHAR(100) NOT NULL,
  model_name VARCHAR(255) NOT NULL,
  model_version VARCHAR(80) NOT NULL DEFAULT 'default',
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_model_versions (service_name, provider, model_name, model_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO model_versions (service_name, provider, model_name, model_version, status)
VALUES
  ('translation', 'opus', 'Helsinki-NLP/opus-mt-en-fr', 'default', 'active'),
  ('translation', 'opus', 'D:\\mdl-translation-models\\opus-fr-en\\final', 'default', 'active'),
  ('translation', 'nllb', 'facebook/nllb-200-distilled-600M', 'default', 'active'),
  ('tts', 'speecht5', 'microsoft/speecht5_tts', 'default', 'active'),
  ('tts', 'mms_tts', 'facebook/mms-tts', 'default', 'active'),
  ('tts', 'gtts', 'google-text-to-speech', 'default', 'active')
ON DUPLICATE KEY UPDATE status = VALUES(status);
