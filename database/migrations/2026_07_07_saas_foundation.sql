-- Multilingual Library SaaS foundation.
-- Safe additive migration: no existing tables or columns are removed.

CREATE TABLE IF NOT EXISTS institutions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(180) NOT NULL,
  slug VARCHAR(120) NOT NULL UNIQUE,
  legal_name VARCHAR(220) NULL,
  institution_type ENUM('university','school','library','publisher','ngo','government','company','other') NOT NULL DEFAULT 'university',
  country_code CHAR(2) NOT NULL DEFAULT 'RW',
  city VARCHAR(120) NULL,
  contact_name VARCHAR(160) NULL,
  contact_email VARCHAR(190) NULL,
  contact_phone VARCHAR(60) NULL,
  status ENUM('active','trial','suspended','archived') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_institutions_status (status),
  INDEX idx_institutions_country (country_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS institution_users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institution_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  institution_role ENUM('institution_admin','librarian','lecturer','student','reader','support') NOT NULL DEFAULT 'reader',
  is_primary TINYINT(1) NOT NULL DEFAULT 1,
  status ENUM('active','invited','suspended','removed') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_institution_user (institution_id, user_id),
  INDEX idx_institution_users_user (user_id),
  INDEX idx_institution_users_role (institution_role),
  CONSTRAINT fk_institution_users_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_institution_users_user FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS institution_settings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institution_id BIGINT UNSIGNED NOT NULL,
  setting_key VARCHAR(120) NOT NULL,
  setting_value TEXT NULL,
  value_type ENUM('string','number','boolean','json') NOT NULL DEFAULT 'string',
  is_public TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_institution_setting (institution_id, setting_key),
  CONSTRAINT fk_institution_settings_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscription_plans (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(80) NOT NULL UNIQUE,
  name VARCHAR(140) NOT NULL,
  description TEXT NULL,
  billing_cycle ENUM('monthly','quarterly','annual','custom') NOT NULL DEFAULT 'annual',
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  price_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  max_users INT UNSIGNED NULL,
  max_books INT UNSIGNED NULL,
  max_storage_mb BIGINT UNSIGNED NULL,
  ai_quota_units BIGINT UNSIGNED NULL,
  features JSON NULL,
  status ENUM('active','hidden','archived') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_subscription_plans_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS institution_subscriptions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institution_id BIGINT UNSIGNED NOT NULL,
  plan_id BIGINT UNSIGNED NOT NULL,
  started_at DATE NOT NULL,
  current_period_start DATE NULL,
  current_period_end DATE NULL,
  trial_ends_at DATE NULL,
  cancelled_at DATETIME NULL,
  status ENUM('trial','active','past_due','cancelled','expired') NOT NULL DEFAULT 'trial',
  billing_email VARCHAR(190) NULL,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_institution_subscriptions_institution (institution_id),
  INDEX idx_institution_subscriptions_status (status),
  CONSTRAINT fk_institution_subscriptions_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_institution_subscriptions_plan FOREIGN KEY (plan_id) REFERENCES subscription_plans(id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS institution_domains (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institution_id BIGINT UNSIGNED NOT NULL,
  domain VARCHAR(190) NOT NULL UNIQUE,
  verification_token_hash CHAR(64) NULL,
  verified_at DATETIME NULL,
  status ENUM('pending','verified','rejected','disabled') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_institution_domains_institution (institution_id),
  CONSTRAINT fk_institution_domains_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS institution_branding (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institution_id BIGINT UNSIGNED NOT NULL UNIQUE,
  display_name VARCHAR(180) NULL,
  logo_path VARCHAR(255) NULL,
  primary_color VARCHAR(20) NULL,
  secondary_color VARCHAR(20) NULL,
  accent_color VARCHAR(20) NULL,
  public_homepage_url VARCHAR(255) NULL,
  support_email VARCHAR(190) NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_institution_branding_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Commercial role mapping keeps the existing dashboard roles working while
-- allowing SaaS roles to evolve separately.
CREATE TABLE IF NOT EXISTS role_mappings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  legacy_role_code VARCHAR(40) NOT NULL,
  commercial_role_code VARCHAR(60) NOT NULL,
  scope ENUM('platform','institution') NOT NULL DEFAULT 'institution',
  description TEXT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_role_mapping (legacy_role_code, commercial_role_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO institutions
  (name, slug, legal_name, institution_type, country_code, city, contact_email, status)
VALUES
  ('Rwanda Library Network', 'rwanda-library-network', 'Rwanda multilingual library network', 'library_network', 'RW', 'Kigali', 'library@gmail.com', 'active')
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  legal_name = VALUES(legal_name),
  institution_type = VALUES(institution_type),
  country_code = VALUES(country_code),
  city = VALUES(city),
  contact_email = VALUES(contact_email),
  status = VALUES(status);

INSERT INTO subscription_plans
  (code, name, description, billing_cycle, currency, price_amount, max_users, max_books, max_storage_mb, ai_quota_units, features, status)
VALUES
  ('pilot', 'Pilot Institution', 'Pilot plan for the first institutional deployments.', 'annual', 'USD', 0.00, 500, 5000, 51200, 10000,
   JSON_OBJECT('voice_search', true, 'tts', true, 'analytics', true, 'support_level', 'pilot'), 'active')
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  description = VALUES(description),
  max_users = VALUES(max_users),
  max_books = VALUES(max_books),
  max_storage_mb = VALUES(max_storage_mb),
  ai_quota_units = VALUES(ai_quota_units),
  features = VALUES(features),
  status = VALUES(status);

INSERT INTO institution_subscriptions
  (institution_id, plan_id, started_at, current_period_start, current_period_end, status, billing_email)
SELECT i.id, p.id, CURDATE(), CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR), 'active', i.contact_email
FROM institutions i
JOIN subscription_plans p ON p.code = 'pilot'
WHERE i.slug = 'rwanda-library-network'
ON DUPLICATE KEY UPDATE
  status = VALUES(status),
  current_period_start = VALUES(current_period_start),
  current_period_end = VALUES(current_period_end),
  billing_email = VALUES(billing_email);

INSERT INTO institution_branding
  (institution_id, display_name, logo_path, primary_color, secondary_color, accent_color, support_email, status)
SELECT id, 'MULTILINGUAL DIGITAL LIBRARY', 'library-logo.svg', '#0f766e', '#1e293b', '#f59e0b', contact_email, 'active'
FROM institutions
WHERE slug = 'rwanda-library-network'
ON DUPLICATE KEY UPDATE
  display_name = VALUES(display_name),
  logo_path = VALUES(logo_path),
  primary_color = VALUES(primary_color),
  secondary_color = VALUES(secondary_color),
  accent_color = VALUES(accent_color),
  support_email = VALUES(support_email),
  status = VALUES(status);

INSERT INTO institution_users (institution_id, user_id, institution_role, is_primary, status)
SELECT i.id, u.id,
       CASE r.code
         WHEN 'LIBRARIAN_ADMIN' THEN 'librarian'
         WHEN 'LECTURER' THEN 'lecturer'
         WHEN 'STUDENT' THEN 'student'
         ELSE 'reader'
       END AS institution_role,
       1,
       'active'
FROM institutions i
JOIN users u
JOIN roles r ON r.id = u.role_id
WHERE i.slug = 'rwanda-library-network'
ON DUPLICATE KEY UPDATE
  institution_role = VALUES(institution_role),
  is_primary = VALUES(is_primary),
  status = VALUES(status);

INSERT INTO role_mappings (legacy_role_code, commercial_role_code, scope, description)
VALUES
  ('LIBRARIAN_ADMIN', 'institution_admin', 'institution', 'Existing librarian/admin users can manage the pilot institution.'),
  ('LIBRARIAN_ADMIN', 'librarian', 'institution', 'Existing librarian/admin users keep library operations permissions.'),
  ('LECTURER', 'lecturer', 'institution', 'Existing lecturer dashboard behavior remains intact.'),
  ('STUDENT', 'student', 'institution', 'Existing student dashboard behavior remains intact.')
ON DUPLICATE KEY UPDATE
  scope = VALUES(scope),
  description = VALUES(description),
  status = 'active';

ALTER TABLE users ADD COLUMN IF NOT EXISTS primary_institution_id BIGINT UNSIGNED NULL AFTER role_id;
ALTER TABLE users ADD INDEX IF NOT EXISTS idx_users_primary_institution (primary_institution_id);

ALTER TABLE books ADD COLUMN IF NOT EXISTS institution_id BIGINT UNSIGNED NULL AFTER id;
ALTER TABLE books ADD INDEX IF NOT EXISTS idx_books_institution (institution_id);

ALTER TABLE book_submissions ADD COLUMN IF NOT EXISTS institution_id BIGINT UNSIGNED NULL AFTER id;
ALTER TABLE book_submissions ADD INDEX IF NOT EXISTS idx_book_submissions_institution (institution_id);

ALTER TABLE personal_books ADD COLUMN IF NOT EXISTS institution_id BIGINT UNSIGNED NULL AFTER id;
ALTER TABLE personal_books ADD INDEX IF NOT EXISTS idx_personal_books_institution (institution_id);

ALTER TABLE borrow_requests ADD COLUMN IF NOT EXISTS institution_id BIGINT UNSIGNED NULL AFTER id;
ALTER TABLE borrow_requests ADD INDEX IF NOT EXISTS idx_borrow_institution (institution_id);

ALTER TABLE reading_progress ADD COLUMN IF NOT EXISTS institution_id BIGINT UNSIGNED NULL AFTER id;
ALTER TABLE reading_progress ADD INDEX IF NOT EXISTS idx_progress_institution (institution_id);

ALTER TABLE activity_logs ADD COLUMN IF NOT EXISTS institution_id BIGINT UNSIGNED NULL AFTER id;
ALTER TABLE activity_logs ADD INDEX IF NOT EXISTS idx_activity_institution (institution_id);

ALTER TABLE search_logs ADD COLUMN IF NOT EXISTS institution_id BIGINT UNSIGNED NULL AFTER id;
ALTER TABLE search_logs ADD INDEX IF NOT EXISTS idx_search_logs_institution (institution_id);

ALTER TABLE voice_search_logs ADD COLUMN IF NOT EXISTS institution_id BIGINT UNSIGNED NULL AFTER id;
ALTER TABLE voice_search_logs ADD INDEX IF NOT EXISTS idx_voice_logs_institution (institution_id);

ALTER TABLE tts_logs ADD COLUMN IF NOT EXISTS institution_id BIGINT UNSIGNED NULL AFTER id;
ALTER TABLE tts_logs ADD INDEX IF NOT EXISTS idx_tts_logs_institution (institution_id);

UPDATE users u
JOIN institutions i ON i.slug = 'rwanda-library-network'
SET u.primary_institution_id = i.id
WHERE u.primary_institution_id IS NULL;

UPDATE books b
JOIN institutions i ON i.slug = 'rwanda-library-network'
SET b.institution_id = i.id
WHERE b.institution_id IS NULL;

UPDATE book_submissions bs
JOIN institutions i ON i.slug = 'rwanda-library-network'
SET bs.institution_id = i.id
WHERE bs.institution_id IS NULL;

UPDATE personal_books pb
JOIN institutions i ON i.slug = 'rwanda-library-network'
SET pb.institution_id = i.id
WHERE pb.institution_id IS NULL;

UPDATE borrow_requests br
JOIN institutions i ON i.slug = 'rwanda-library-network'
SET br.institution_id = i.id
WHERE br.institution_id IS NULL;

UPDATE reading_progress rp
JOIN institutions i ON i.slug = 'rwanda-library-network'
SET rp.institution_id = i.id
WHERE rp.institution_id IS NULL;

UPDATE activity_logs al
JOIN institutions i ON i.slug = 'rwanda-library-network'
SET al.institution_id = i.id
WHERE al.institution_id IS NULL;

UPDATE search_logs sl
JOIN institutions i ON i.slug = 'rwanda-library-network'
SET sl.institution_id = i.id
WHERE sl.institution_id IS NULL;

UPDATE voice_search_logs vsl
JOIN institutions i ON i.slug = 'rwanda-library-network'
SET vsl.institution_id = i.id
WHERE vsl.institution_id IS NULL;

UPDATE tts_logs tl
JOIN institutions i ON i.slug = 'rwanda-library-network'
SET tl.institution_id = i.id
WHERE tl.institution_id IS NULL;

