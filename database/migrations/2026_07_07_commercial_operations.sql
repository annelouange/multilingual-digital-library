-- Commercial operations foundation for billing, AI metering, background jobs,
-- and publisher/content-rights workflows. Safe additive migration.

CREATE TABLE IF NOT EXISTS invoices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institution_id BIGINT UNSIGNED NOT NULL,
  subscription_id BIGINT UNSIGNED NULL,
  invoice_number VARCHAR(80) NOT NULL UNIQUE,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  subtotal_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  issued_at DATE NOT NULL,
  due_at DATE NULL,
  paid_at DATETIME NULL,
  status ENUM('draft','issued','paid','overdue','void') NOT NULL DEFAULT 'draft',
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_invoices_institution (institution_id),
  INDEX idx_invoices_status (status),
  CONSTRAINT fk_invoices_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_invoices_subscription FOREIGN KEY (subscription_id) REFERENCES institution_subscriptions(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institution_id BIGINT UNSIGNED NOT NULL,
  invoice_id BIGINT UNSIGNED NULL,
  provider ENUM('mtn_momo','airtel_money','card','bank_transfer','cash','other') NOT NULL DEFAULT 'bank_transfer',
  provider_reference VARCHAR(160) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  paid_at DATETIME NULL,
  status ENUM('pending','successful','failed','refunded','cancelled') NOT NULL DEFAULT 'pending',
  metadata JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_payments_institution (institution_id),
  INDEX idx_payments_invoice (invoice_id),
  INDEX idx_payments_status (status),
  CONSTRAINT fk_payments_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_payments_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usage_limits (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institution_id BIGINT UNSIGNED NOT NULL,
  subscription_id BIGINT UNSIGNED NULL,
  metric_key VARCHAR(80) NOT NULL,
  limit_value BIGINT UNSIGNED NULL,
  used_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
  period_start DATE NULL,
  period_end DATE NULL,
  status ENUM('active','exceeded','disabled') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_usage_limit_period (institution_id, metric_key, period_start),
  CONSTRAINT fk_usage_limits_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_usage_limits_subscription FOREIGN KEY (subscription_id) REFERENCES institution_subscriptions(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_usage_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institution_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NULL,
  service_name ENUM('stt','tts','translation','summary','recommendation','ocr','embedding','other') NOT NULL DEFAULT 'other',
  provider VARCHAR(80) NULL,
  model_name VARCHAR(160) NULL,
  input_units BIGINT UNSIGNED NOT NULL DEFAULT 0,
  output_units BIGINT UNSIGNED NOT NULL DEFAULT 0,
  cost_amount DECIMAL(12,6) NULL,
  currency CHAR(3) NULL,
  status ENUM('success','failed','cancelled') NOT NULL DEFAULT 'success',
  metadata JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ai_usage_institution (institution_id),
  INDEX idx_ai_usage_user (user_id),
  INDEX idx_ai_usage_service (service_name),
  INDEX idx_ai_usage_created (created_at),
  CONSTRAINT fk_ai_usage_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_ai_usage_user FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institution_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NULL,
  job_type ENUM('stt','tts','translation','summary','recommendation','ocr','embedding','other') NOT NULL DEFAULT 'other',
  priority TINYINT UNSIGNED NOT NULL DEFAULT 5,
  payload JSON NULL,
  status ENUM('queued','running','completed','failed','cancelled') NOT NULL DEFAULT 'queued',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  failed_at DATETIME NULL,
  last_error TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_ai_jobs_status (status, available_at),
  INDEX idx_ai_jobs_institution (institution_id),
  CONSTRAINT fk_ai_jobs_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_ai_jobs_user FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_job_results (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ai_job_id BIGINT UNSIGNED NOT NULL,
  result_type VARCHAR(80) NOT NULL,
  result_payload JSON NULL,
  output_path VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ai_job_results_job FOREIGN KEY (ai_job_id) REFERENCES ai_jobs(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS model_health_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  service_name VARCHAR(80) NOT NULL,
  model_name VARCHAR(160) NULL,
  status ENUM('ready','degraded','offline','unknown') NOT NULL DEFAULT 'unknown',
  latency_ms INT UNSIGNED NULL,
  details JSON NULL,
  checked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_model_health_service (service_name),
  INDEX idx_model_health_checked (checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institution_id BIGINT UNSIGNED NULL,
  queue_name VARCHAR(80) NOT NULL DEFAULT 'default',
  job_type VARCHAR(120) NOT NULL,
  payload JSON NULL,
  priority TINYINT UNSIGNED NOT NULL DEFAULT 5,
  status ENUM('queued','running','completed','failed','cancelled') NOT NULL DEFAULT 'queued',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts INT UNSIGNED NOT NULL DEFAULT 3,
  available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  locked_at DATETIME NULL,
  locked_by VARCHAR(120) NULL,
  completed_at DATETIME NULL,
  failed_at DATETIME NULL,
  last_error TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_jobs_queue_status (queue_name, status, available_at),
  INDEX idx_jobs_institution (institution_id),
  CONSTRAINT fk_jobs_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS job_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id BIGINT UNSIGNED NOT NULL,
  attempt_number INT UNSIGNED NOT NULL,
  worker_name VARCHAR(120) NULL,
  status ENUM('started','completed','failed') NOT NULL DEFAULT 'started',
  error_message TEXT NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  CONSTRAINT fk_job_attempts_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS publishers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(180) NOT NULL,
  slug VARCHAR(120) NOT NULL UNIQUE,
  contact_name VARCHAR(160) NULL,
  contact_email VARCHAR(190) NULL,
  contact_phone VARCHAR(60) NULL,
  country_code CHAR(2) NULL,
  status ENUM('active','pending','suspended','archived') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_publishers_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS content_licenses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(80) NOT NULL UNIQUE,
  name VARCHAR(160) NOT NULL,
  description TEXT NULL,
  allows_download TINYINT(1) NOT NULL DEFAULT 0,
  allows_streaming TINYINT(1) NOT NULL DEFAULT 1,
  allows_translation TINYINT(1) NOT NULL DEFAULT 0,
  allows_tts TINYINT(1) NOT NULL DEFAULT 0,
  allows_ai_summary TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('active','archived') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS content_rights (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  book_id BIGINT UNSIGNED NOT NULL,
  publisher_id BIGINT UNSIGNED NULL,
  license_id BIGINT UNSIGNED NULL,
  rights_owner VARCHAR(190) NULL,
  copyright_status ENUM('unknown','institution_owned','publisher_licensed','public_domain','open_access','author_owned','restricted') NOT NULL DEFAULT 'unknown',
  visibility ENUM('private','institution','public','marketplace') NOT NULL DEFAULT 'institution',
  access_level ENUM('metadata_only','read_online','download','premium') NOT NULL DEFAULT 'read_online',
  starts_at DATE NULL,
  ends_at DATE NULL,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_content_rights_book (book_id),
  CONSTRAINT fk_content_rights_book FOREIGN KEY (book_id) REFERENCES books(id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_content_rights_publisher FOREIGN KEY (publisher_id) REFERENCES publishers(id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_content_rights_license FOREIGN KEY (license_id) REFERENCES content_licenses(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS publisher_books (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  publisher_id BIGINT UNSIGNED NOT NULL,
  book_id BIGINT UNSIGNED NOT NULL,
  contract_reference VARCHAR(160) NULL,
  royalty_percent DECIMAL(5,2) NULL,
  status ENUM('active','pending','expired','removed') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_publisher_book (publisher_id, book_id),
  CONSTRAINT fk_publisher_books_publisher FOREIGN KEY (publisher_id) REFERENCES publishers(id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_publisher_books_book FOREIGN KEY (book_id) REFERENCES books(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS takedown_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institution_id BIGINT UNSIGNED NULL,
  book_id BIGINT UNSIGNED NULL,
  requester_name VARCHAR(160) NOT NULL,
  requester_email VARCHAR(190) NOT NULL,
  reason TEXT NOT NULL,
  status ENUM('open','reviewing','resolved','rejected') NOT NULL DEFAULT 'open',
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  resolution_note TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_takedown_status (status),
  CONSTRAINT fk_takedown_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_takedown_book FOREIGN KEY (book_id) REFERENCES books(id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_takedown_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO content_licenses
  (code, name, description, allows_download, allows_streaming, allows_translation, allows_tts, allows_ai_summary, status)
VALUES
  ('institution_owned', 'Institution Owned', 'Content owned or uploaded by an institution for its own users.', 1, 1, 1, 1, 1, 'active'),
  ('read_online', 'Read Online Only', 'Licensed content that can be read in the platform but not downloaded.', 0, 1, 0, 1, 1, 'active'),
  ('public_domain', 'Public Domain', 'Content that is public domain or otherwise unrestricted.', 1, 1, 1, 1, 1, 'active'),
  ('metadata_only', 'Metadata Only', 'Catalog record without a readable digital file.', 0, 0, 0, 0, 0, 'active')
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  description = VALUES(description),
  allows_download = VALUES(allows_download),
  allows_streaming = VALUES(allows_streaming),
  allows_translation = VALUES(allows_translation),
  allows_tts = VALUES(allows_tts),
  allows_ai_summary = VALUES(allows_ai_summary),
  status = VALUES(status);

ALTER TABLE books ADD COLUMN IF NOT EXISTS license_type VARCHAR(80) NULL AFTER keywords;
ALTER TABLE books ADD COLUMN IF NOT EXISTS rights_owner VARCHAR(190) NULL AFTER license_type;
ALTER TABLE books ADD COLUMN IF NOT EXISTS copyright_status ENUM('unknown','institution_owned','publisher_licensed','public_domain','open_access','author_owned','restricted') NOT NULL DEFAULT 'unknown' AFTER rights_owner;
ALTER TABLE books ADD COLUMN IF NOT EXISTS visibility ENUM('private','institution','public','marketplace') NOT NULL DEFAULT 'institution' AFTER copyright_status;
ALTER TABLE books ADD COLUMN IF NOT EXISTS access_level ENUM('metadata_only','read_online','download','premium') NOT NULL DEFAULT 'read_online' AFTER visibility;
ALTER TABLE books ADD INDEX IF NOT EXISTS idx_books_visibility (visibility);
ALTER TABLE books ADD INDEX IF NOT EXISTS idx_books_access_level (access_level);

