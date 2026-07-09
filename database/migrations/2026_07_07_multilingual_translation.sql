-- Multilingual foundation for English, French, Kinyarwanda, Kiswahili and
-- future regional languages. Safe additive migration.

CREATE TABLE IF NOT EXISTS supported_languages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(10) NOT NULL UNIQUE,
  name VARCHAR(80) NOT NULL,
  native_name VARCHAR(120) NOT NULL,
  direction ENUM('ltr','rtl') NOT NULL DEFAULT 'ltr',
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  supports_ui TINYINT(1) NOT NULL DEFAULT 1,
  supports_translation TINYINT(1) NOT NULL DEFAULT 1,
  supports_stt TINYINT(1) NOT NULL DEFAULT 0,
  supports_tts TINYINT(1) NOT NULL DEFAULT 0,
  quality_status ENUM('production','beta','roadmap') NOT NULL DEFAULT 'beta',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_supported_languages_active (is_active),
  INDEX idx_supported_languages_translation (supports_translation)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS translation_memory (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_language VARCHAR(10) NOT NULL,
  target_language VARCHAR(10) NOT NULL,
  source_text TEXT NOT NULL,
  translated_text TEXT NOT NULL,
  provider ENUM('human','internal_dictionary','external_api','model','imported') NOT NULL DEFAULT 'internal_dictionary',
  domain VARCHAR(80) NOT NULL DEFAULT 'library_ui',
  quality_status ENUM('approved','machine','needs_review','rejected') NOT NULL DEFAULT 'approved',
  created_by BIGINT UNSIGNED NULL,
  status ENUM('active','archived') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_translation_memory_pair (source_language, target_language),
  INDEX idx_translation_memory_status (status),
  UNIQUE KEY uq_translation_memory_phrase (source_language, target_language, domain, source_text(255)),
  FULLTEXT KEY ft_translation_source (source_text),
  CONSTRAINT fk_translation_memory_user FOREIGN KEY (created_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS translation_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institution_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NULL,
  source_language VARCHAR(10) NOT NULL,
  target_language VARCHAR(10) NOT NULL,
  source_text MEDIUMTEXT NOT NULL,
  translated_text MEDIUMTEXT NULL,
  provider ENUM('internal_dictionary','external_api','model','human_review','none') NOT NULL DEFAULT 'internal_dictionary',
  confidence DECIMAL(5,2) NULL,
  quality_status ENUM('translated','partial','needs_review','failed') NOT NULL DEFAULT 'needs_review',
  status ENUM('completed','queued','failed','cancelled') NOT NULL DEFAULT 'completed',
  error_message TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_translation_requests_institution (institution_id),
  INDEX idx_translation_requests_user (user_id),
  INDEX idx_translation_requests_pair (source_language, target_language),
  CONSTRAINT fk_translation_requests_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_translation_requests_user FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO supported_languages
  (code, name, native_name, is_default, is_active, supports_ui, supports_translation, supports_stt, supports_tts, quality_status)
VALUES
  ('en', 'English', 'English', 1, 1, 1, 1, 1, 1, 'production'),
  ('fr', 'French', 'Francais', 0, 1, 1, 1, 0, 0, 'beta'),
  ('rw', 'Kinyarwanda', 'Ikinyarwanda', 0, 1, 1, 1, 0, 0, 'beta'),
  ('sw', 'Kiswahili', 'Kiswahili', 0, 1, 1, 1, 0, 0, 'beta')
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  native_name = VALUES(native_name),
  is_default = VALUES(is_default),
  is_active = VALUES(is_active),
  supports_ui = VALUES(supports_ui),
  supports_translation = VALUES(supports_translation),
  supports_stt = VALUES(supports_stt),
  supports_tts = VALUES(supports_tts),
  quality_status = VALUES(quality_status);

INSERT INTO translation_memory
  (source_language, target_language, source_text, translated_text, provider, domain, quality_status)
VALUES
  ('en', 'fr', 'Search Books', 'Rechercher des livres', 'internal_dictionary', 'library_ui', 'approved'),
  ('en', 'rw', 'Search Books', 'Shakisha ibitabo', 'internal_dictionary', 'library_ui', 'approved'),
  ('en', 'sw', 'Search Books', 'Tafuta vitabu', 'internal_dictionary', 'library_ui', 'approved'),
  ('en', 'fr', 'My Borrowed Books', 'Mes livres empruntes', 'internal_dictionary', 'library_ui', 'approved'),
  ('en', 'rw', 'My Borrowed Books', 'Ibitabo natije', 'internal_dictionary', 'library_ui', 'approved'),
  ('en', 'sw', 'My Borrowed Books', 'Vitabu nilivyoazima', 'internal_dictionary', 'library_ui', 'approved'),
  ('en', 'fr', 'Reading Progress', 'Progression de lecture', 'internal_dictionary', 'library_ui', 'approved'),
  ('en', 'rw', 'Reading Progress', 'Aho ngeze nsoma', 'internal_dictionary', 'library_ui', 'approved'),
  ('en', 'sw', 'Reading Progress', 'Maendeleo ya kusoma', 'internal_dictionary', 'library_ui', 'approved'),
  ('en', 'fr', 'Favorites', 'Favoris', 'internal_dictionary', 'library_ui', 'approved'),
  ('en', 'rw', 'Favorites', 'Ibyo nkunda', 'internal_dictionary', 'library_ui', 'approved'),
  ('en', 'sw', 'Favorites', 'Vipendwa', 'internal_dictionary', 'library_ui', 'approved'),
  ('en', 'fr', 'Bookmarks', 'Signets', 'internal_dictionary', 'library_ui', 'approved'),
  ('en', 'rw', 'Bookmarks', 'Utumenyetso two gusoma', 'internal_dictionary', 'library_ui', 'approved'),
  ('en', 'sw', 'Bookmarks', 'Alamisho', 'internal_dictionary', 'library_ui', 'approved'),
  ('en', 'fr', 'Recommendations', 'Recommandations', 'internal_dictionary', 'library_ui', 'approved'),
  ('en', 'rw', 'Recommendations', 'Ibyifuzo', 'internal_dictionary', 'library_ui', 'approved'),
  ('en', 'sw', 'Recommendations', 'Mapendekezo', 'internal_dictionary', 'library_ui', 'approved'),
  ('en', 'fr', 'Notifications', 'Notifications', 'internal_dictionary', 'library_ui', 'approved'),
  ('en', 'rw', 'Notifications', 'Amatangazo', 'internal_dictionary', 'library_ui', 'approved'),
  ('en', 'sw', 'Notifications', 'Arifa', 'internal_dictionary', 'library_ui', 'approved'),
  ('en', 'fr', 'Upload Book', 'Televerser un livre', 'internal_dictionary', 'library_ui', 'approved'),
  ('en', 'rw', 'Upload Book', 'Ohereza igitabo', 'internal_dictionary', 'library_ui', 'approved'),
  ('en', 'sw', 'Upload Book', 'Pakia kitabu', 'internal_dictionary', 'library_ui', 'approved')
ON DUPLICATE KEY UPDATE
  translated_text = VALUES(translated_text),
  provider = VALUES(provider),
  domain = VALUES(domain),
  quality_status = VALUES(quality_status),
  status = 'active';

