-- Lean AI stack registry and big-book page/chunk search foundation.

CREATE TABLE IF NOT EXISTS ai_model_registry (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  task ENUM('translation','stt','tts','search','rerank') NOT NULL,
  provider ENUM('local','api_fallback') NOT NULL DEFAULT 'local',
  name VARCHAR(120) NOT NULL,
  model_id VARCHAR(255) NOT NULL,
  languages JSON NULL,
  priority TINYINT UNSIGNED NOT NULL DEFAULT 1,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ai_model_registry (task, provider, model_id),
  KEY idx_ai_model_task_enabled (task, enabled, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS book_page_index (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institution_id BIGINT UNSIGNED NULL,
  book_id BIGINT UNSIGNED NOT NULL,
  file_id BIGINT UNSIGNED NOT NULL,
  page_number INT UNSIGNED NOT NULL DEFAULT 0,
  chunk_number INT UNSIGNED NOT NULL DEFAULT 0,
  language VARCHAR(12) NOT NULL DEFAULT 'en',
  content_hash CHAR(64) NOT NULL,
  content_text MEDIUMTEXT NOT NULL,
  character_count INT UNSIGNED NOT NULL DEFAULT 0,
  indexed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_book_page_chunk (file_id, page_number, chunk_number, language),
  KEY idx_book_page_book (book_id, page_number),
  KEY idx_book_page_institution (institution_id, book_id),
  FULLTEXT KEY ft_book_page_content (content_text),
  CONSTRAINT fk_book_page_index_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
  CONSTRAINT fk_book_page_index_file FOREIGN KEY (file_id) REFERENCES book_files(id) ON DELETE CASCADE,
  CONSTRAINT fk_book_page_index_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS book_processing_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institution_id BIGINT UNSIGNED NULL,
  book_id BIGINT UNSIGNED NOT NULL,
  file_id BIGINT UNSIGNED NULL,
  job_type ENUM('extract_pages','index_pages','translate_book','tts_book','ocr_book') NOT NULL,
  status ENUM('queued','running','completed','failed','cancelled') NOT NULL DEFAULT 'queued',
  progress_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
  payload JSON NULL,
  error_message TEXT NULL,
  locked_at TIMESTAMP NULL,
  completed_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_book_jobs_status (status, job_type, created_at),
  KEY idx_book_jobs_book (book_id, job_type),
  CONSTRAINT fk_book_jobs_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
  CONSTRAINT fk_book_jobs_file FOREIGN KEY (file_id) REFERENCES book_files(id) ON DELETE SET NULL,
  CONSTRAINT fk_book_jobs_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ai_model_registry (task, provider, name, model_id, languages, priority, enabled, notes)
VALUES
  ('translation', 'local', 'NLLB-200 distilled 600M', 'facebook/nllb-200-distilled-600M', JSON_ARRAY('en','fr','rw','sw'), 1, 1, 'Primary frozen local text translation model.'),
  ('stt', 'local', 'Whisper multilingual', 'whisper-tiny', JSON_ARRAY('en','fr','rw','sw'), 1, 1, 'Pilot multilingual STT. Upgrade to whisper-small/medium or fine-tuned Kinyarwanda checkpoint on GPU.'),
  ('tts', 'local', 'Meta MMS-TTS multilingual set', 'facebook/mms-tts-{eng,fra,kin,swh}', JSON_ARRAY('en','fr','rw','sw'), 1, 1, 'Primary frozen local multilingual TTS checkpoints.'),
  ('tts', 'local', 'SpeechT5 English', 'microsoft/speecht5_tts', JSON_ARRAY('en'), 2, 1, 'English fallback/legacy narration model.'),
  ('translation', 'api_fallback', 'API fallback 1', 'MODEL_API_FALLBACK_1', JSON_ARRAY('en','fr','rw','sw'), 1, 0, 'Configure only for production overflow/outage.'),
  ('translation', 'api_fallback', 'API fallback 2', 'MODEL_API_FALLBACK_2', JSON_ARRAY('en','fr','rw','sw'), 2, 0, 'Second provider for resilience.')
ON DUPLICATE KEY UPDATE
  languages=VALUES(languages), priority=VALUES(priority), enabled=VALUES(enabled), notes=VALUES(notes), updated_at=CURRENT_TIMESTAMP;
