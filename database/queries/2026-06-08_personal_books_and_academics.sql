USE multilingual_digital_library;

CREATE TABLE IF NOT EXISTS personal_books (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  author VARCHAR(180) NULL,
  description TEXT NULL,
  language_code VARCHAR(10) NOT NULL DEFAULT 'en',
  file_type ENUM('pdf','txt') NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  source_name VARCHAR(255) NULL,
  mime_type VARCHAR(120) NULL,
  file_size BIGINT UNSIGNED NULL,
  converted_to_pdf TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('active','deleted') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_personal_books_user (user_id),
  INDEX idx_personal_books_status (status),
  CONSTRAINT fk_personal_books_user FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE faculties
SET name='Faculty of Sciences and Information Technology',
    code='FSIT',
    description='Computing, information science, statistics, systems technology, land management, and valuation programs.',
    status='active'
WHERE id=1;

UPDATE faculties
SET name='Faculty of Education',
    code='FED',
    description='Teacher education, languages, science education, and educational research programs.',
    status='active'
WHERE id=2;

INSERT INTO faculties (name, code, description, status) VALUES
('Faculty of Economics, Social Sciences and Management', 'FESSM', 'Economics, enterprise management, finance, taxation, cooperatives, and entrepreneurship programs.', 'active'),
('Faculty of Engineering and Technology', 'FET', 'Engineering, architecture, biotechnology, surveying, energy, water, and crop production programs.', 'active'),
('Faculty of Law and Public Administration', 'FLPA', 'Law, public administration, governance, and applied criminal law programs.', 'active'),
('Faculty of Health Sciences', 'FHS', 'Biomedical laboratory science, pharmacy, anaesthesia, nursing, and midwifery programs.', 'active')
ON DUPLICATE KEY UPDATE
  code=VALUES(code),
  description=VALUES(description),
  status='active';

INSERT INTO departments (faculty_id, name, code, description, status)
SELECT id, 'Computer Science', 'CS', 'Software, computing, artificial intelligence, and information systems.', 'active'
FROM faculties WHERE code='FSIT'
ON DUPLICATE KEY UPDATE code=VALUES(code), description=VALUES(description), status='active';

INSERT INTO departments (faculty_id, name, code, description, status)
SELECT id, 'Statistics Applied to Economy', 'SAE', 'Statistics, data analysis, and economic applications.', 'active'
FROM faculties WHERE code='FSIT'
ON DUPLICATE KEY UPDATE code=VALUES(code), description=VALUES(description), status='active';

INSERT INTO departments (faculty_id, name, code, description, status)
SELECT id, 'Computer System Technology', 'CST', 'Computer systems, networks, maintenance, and applied technology.', 'active'
FROM faculties WHERE code='FSIT'
ON DUPLICATE KEY UPDATE code=VALUES(code), description=VALUES(description), status='active';

INSERT INTO departments (faculty_id, name, code, description, status)
SELECT id, 'Information Sciences and Library Management', 'ISLM', 'Library science, information management, digital libraries, and archives.', 'active'
FROM faculties WHERE code='FSIT'
ON DUPLICATE KEY UPDATE code=VALUES(code), description=VALUES(description), status='active';

INSERT INTO departments (faculty_id, name, code, description, status)
SELECT id, 'Land Management and Valuation', 'LMV', 'Land administration, valuation, property, and geospatial management.', 'active'
FROM faculties WHERE code='FSIT'
ON DUPLICATE KEY UPDATE code=VALUES(code), description=VALUES(description), status='active';

INSERT INTO departments (faculty_id, name, code, description, status)
SELECT id, 'Applied Economics', 'AE', 'Applied economics, development, finance, and policy analysis.', 'active'
FROM faculties WHERE code='FESSM'
ON DUPLICATE KEY UPDATE code=VALUES(code), description=VALUES(description), status='active';

INSERT INTO departments (faculty_id, name, code, description, status)
SELECT id, 'Enterprises Management', 'EM', 'Accounting, management, entrepreneurship, and enterprise development.', 'active'
FROM faculties WHERE code='FESSM'
ON DUPLICATE KEY UPDATE code=VALUES(code), description=VALUES(description), status='active';

INSERT INTO departments (faculty_id, name, code, description, status)
SELECT id, 'Microfinance', 'MF', 'Microfinance institutions, inclusive finance, and community development.', 'active'
FROM faculties WHERE code='FESSM'
ON DUPLICATE KEY UPDATE code=VALUES(code), description=VALUES(description), status='active';

INSERT INTO departments (faculty_id, name, code, description, status)
SELECT id, 'Taxation', 'TAX', 'Tax policy, administration, compliance, and public revenue.', 'active'
FROM faculties WHERE code='FESSM'
ON DUPLICATE KEY UPDATE code=VALUES(code), description=VALUES(description), status='active';

INSERT INTO departments (faculty_id, name, code, description, status)
SELECT id, 'Cooperatives Management', 'COOP', 'Cooperative governance, finance, operations, and development.', 'active'
FROM faculties WHERE code='FESSM'
ON DUPLICATE KEY UPDATE code=VALUES(code), description=VALUES(description), status='active';

INSERT INTO departments (faculty_id, name, code, description, status)
SELECT id, 'Entrepreneurship and SME Management', 'ESME', 'Entrepreneurship, innovation, and small business management.', 'active'
FROM faculties WHERE code='FESSM'
ON DUPLICATE KEY UPDATE code=VALUES(code), description=VALUES(description), status='active';

INSERT INTO departments (faculty_id, name, code, description, status)
SELECT id, 'French and English Education', 'FEE', 'Language teaching, literature, linguistics, and pedagogy.', 'active'
FROM faculties WHERE code='FED'
ON DUPLICATE KEY UPDATE code=VALUES(code), description=VALUES(description), status='active';

INSERT INTO departments (faculty_id, name, code, description, status)
SELECT id, 'Biology and Chemistry Education', 'BCE', 'Biology and chemistry teacher education.', 'active'
FROM faculties WHERE code='FED'
ON DUPLICATE KEY UPDATE code=VALUES(code), description=VALUES(description), status='active';

INSERT INTO departments (faculty_id, name, code, description, status)
SELECT id, 'Mathematics and Physics Education', 'MPE', 'Mathematics and physics teacher education.', 'active'
FROM faculties WHERE code='FED'
ON DUPLICATE KEY UPDATE code=VALUES(code), description=VALUES(description), status='active';

INSERT INTO departments (faculty_id, name, code, description, status)
SELECT id, 'Mathematics and Computer Science Education', 'MCSE', 'Mathematics and computer science teacher education.', 'active'
FROM faculties WHERE code='FED'
ON DUPLICATE KEY UPDATE code=VALUES(code), description=VALUES(description), status='active';

INSERT INTO departments (faculty_id, name, code, description, status)
SELECT id, 'Civil Engineering', 'CE', 'Structures, construction, transportation, and geotechnical engineering.', 'active'
FROM faculties WHERE code='FET'
ON DUPLICATE KEY UPDATE code=VALUES(code), description=VALUES(description), status='active';

INSERT INTO departments (faculty_id, name, code, description, status)
SELECT id, 'Biotechnologies', 'BIO', 'Food biotechnology, plant biotechnology, and applied biosciences.', 'active'
FROM faculties WHERE code='FET'
ON DUPLICATE KEY UPDATE code=VALUES(code), description=VALUES(description), status='active';

INSERT INTO departments (faculty_id, name, code, description, status)
SELECT id, 'Land Survey', 'LS', 'Surveying, geo-informatics, mapping, and land measurement.', 'active'
FROM faculties WHERE code='FET'
ON DUPLICATE KEY UPDATE code=VALUES(code), description=VALUES(description), status='active';

INSERT INTO departments (faculty_id, name, code, description, status)
SELECT id, 'Architecture', 'ARCH', 'Architecture, built environment, design, and sustainable construction.', 'active'
FROM faculties WHERE code='FET'
ON DUPLICATE KEY UPDATE code=VALUES(code), description=VALUES(description), status='active';

INSERT INTO departments (faculty_id, name, code, description, status)
SELECT id, 'Water Engineering', 'WE', 'Water resources, hydraulics, sanitation, and environmental systems.', 'active'
FROM faculties WHERE code='FET'
ON DUPLICATE KEY UPDATE code=VALUES(code), description=VALUES(description), status='active';

INSERT INTO departments (faculty_id, name, code, description, status)
SELECT id, 'Electrical Power Engineering', 'EPE', 'Electrical systems, power generation, distribution, and control.', 'active'
FROM faculties WHERE code='FET'
ON DUPLICATE KEY UPDATE code=VALUES(code), description=VALUES(description), status='active';

INSERT INTO departments (faculty_id, name, code, description, status)
SELECT id, 'Renewable Energy Engineering', 'REE', 'Solar, wind, hydro, energy systems, and sustainability.', 'active'
FROM faculties WHERE code='FET'
ON DUPLICATE KEY UPDATE code=VALUES(code), description=VALUES(description), status='active';

INSERT INTO departments (faculty_id, name, code, description, status)
SELECT id, 'Crop Production', 'CP', 'Crop science, agronomy, production systems, and sustainable agriculture.', 'active'
FROM faculties WHERE code='FET'
ON DUPLICATE KEY UPDATE code=VALUES(code), description=VALUES(description), status='active';

INSERT INTO departments (faculty_id, name, code, description, status)
SELECT id, 'Law', 'LAW', 'Private, public, commercial, criminal, and international law.', 'active'
FROM faculties WHERE code='FLPA'
ON DUPLICATE KEY UPDATE code=VALUES(code), description=VALUES(description), status='active';

INSERT INTO departments (faculty_id, name, code, description, status)
SELECT id, 'Public Administration and Good Governance', 'PAGG', 'Public policy, administration, leadership, and governance.', 'active'
FROM faculties WHERE code='FLPA'
ON DUPLICATE KEY UPDATE code=VALUES(code), description=VALUES(description), status='active';

INSERT INTO departments (faculty_id, name, code, description, status)
SELECT id, 'Biomedical Laboratory Sciences', 'BLS', 'Clinical laboratory science, diagnostics, and biomedical research.', 'active'
FROM faculties WHERE code='FHS'
ON DUPLICATE KEY UPDATE code=VALUES(code), description=VALUES(description), status='active';

INSERT INTO departments (faculty_id, name, code, description, status)
SELECT id, 'Pharmacy', 'PHARM', 'Pharmaceutical science, medicines, patient care, and public health.', 'active'
FROM faculties WHERE code='FHS'
ON DUPLICATE KEY UPDATE code=VALUES(code), description=VALUES(description), status='active';

INSERT INTO departments (faculty_id, name, code, description, status)
SELECT id, 'Anaesthesia', 'ANES', 'Anaesthesia practice, perioperative care, and patient safety.', 'active'
FROM faculties WHERE code='FHS'
ON DUPLICATE KEY UPDATE code=VALUES(code), description=VALUES(description), status='active';

INSERT INTO departments (faculty_id, name, code, description, status)
SELECT id, 'Midwifery', 'MID', 'Maternal, newborn, reproductive, and community health.', 'active'
FROM faculties WHERE code='FHS'
ON DUPLICATE KEY UPDATE code=VALUES(code), description=VALUES(description), status='active';

INSERT INTO departments (faculty_id, name, code, description, status)
SELECT id, 'General Nursing', 'NURS', 'Clinical nursing, patient care, public health, and nursing leadership.', 'active'
FROM faculties WHERE code='FHS'
ON DUPLICATE KEY UPDATE code=VALUES(code), description=VALUES(description), status='active';

INSERT INTO categories (name, description, status)
VALUES ('Literature and Novels', 'General reading, fiction, literature, short stories, and novels.', 'active')
ON DUPLICATE KEY UPDATE description=VALUES(description), status='active';
