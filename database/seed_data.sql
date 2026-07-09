USE multilingual_digital_library;

INSERT INTO roles (code, name, description)
VALUES
  ('STUDENT', 'Student', 'Student library user'),
  ('LECTURER', 'Lecturer', 'Lecturer and course material manager'),
  ('LIBRARIAN_ADMIN', 'Librarian/Admin', 'Library administrator with full system permissions')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

INSERT INTO faculties (name, code, description)
VALUES
  ('Faculty of Science', 'SCI', 'Science and technology programs'),
  ('Faculty of Education', 'EDU', 'Education and research programs')
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT INTO departments (faculty_id, name, code, description)
SELECT f.id, 'Computer Science', 'CS', 'Computing and information systems'
FROM faculties f WHERE f.code = 'SCI'
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT INTO departments (faculty_id, name, code, description)
SELECT f.id, 'Research Methods', 'RM', 'Research and academic methodology'
FROM faculties f WHERE f.code = 'EDU'
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT INTO courses (department_id, name, code, description)
SELECT d.id, 'Digital Library Systems', 'DLS101', 'Digital library architecture and search'
FROM departments d WHERE d.code = 'CS'
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT INTO courses (department_id, name, code, description)
SELECT d.id, 'Human Computer Interaction', 'HCI201', 'Usable and accessible systems'
FROM departments d WHERE d.code = 'CS'
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT INTO authors (full_name, biography)
VALUES
  ('Christine Borgman', 'Digital libraries and scholarly information systems author'),
  ('Alan Dix', 'Human-computer interaction author'),
  ('Ramez Elmasri', 'Database systems author')
ON DUPLICATE KEY UPDATE biography = VALUES(biography);

INSERT INTO categories (name, description)
VALUES
  ('Information Science', 'Information systems and library science'),
  ('Design', 'Interaction and user experience design'),
  ('Databases', 'Database design and administration'),
  ('Research', 'Academic research methods')
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT INTO users (role_id, full_name, email, phone, password_hash, email_verified_at, status)
SELECT r.id, 'Student Demo', 'student@library.rw', '+250700000001', '$2y$12$LbCWrHGIcrjNtq2PwZL8sO3JjBLhNUrO53QXn7OGiFMEAtVV3/20O', NOW(), 'active'
FROM roles r WHERE r.code = 'STUDENT'
ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), role_id = VALUES(role_id);

INSERT INTO users (role_id, full_name, email, phone, password_hash, email_verified_at, status)
SELECT r.id, 'Lecturer Demo', 'lecturer@library.rw', '+250700000002', '$2y$12$LbCWrHGIcrjNtq2PwZL8sO3JjBLhNUrO53QXn7OGiFMEAtVV3/20O', NOW(), 'active'
FROM roles r WHERE r.code = 'LECTURER'
ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), role_id = VALUES(role_id);

INSERT INTO users (role_id, full_name, email, phone, password_hash, email_verified_at, status)
SELECT r.id, 'Library Demo', 'library@gmail.com', '+250700000004', '$2y$10$HziApy0G1VqRb3QqtzjyXONikSzUq3ovq1AY0iC2zyufpogOg3E4C', NOW(), 'active'
FROM roles r WHERE r.code = 'LIBRARIAN_ADMIN'
ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), role_id = VALUES(role_id), password_hash = VALUES(password_hash), status = 'active';

INSERT INTO users (role_id, full_name, email, phone, password_hash, email_verified_at, status)
SELECT r.id, 'Lecturer Gmail Demo', 'lecturer@gmail.com', '+250700000005', '$2y$10$HziApy0G1VqRb3QqtzjyXONikSzUq3ovq1AY0iC2zyufpogOg3E4C', NOW(), 'active'
FROM roles r WHERE r.code = 'LECTURER'
ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), role_id = VALUES(role_id), password_hash = VALUES(password_hash), status = 'active';

INSERT INTO user_profiles (user_id, faculty_id, department_id, course_id)
SELECT u.id, f.id, d.id, c.id
FROM users u
JOIN faculties f ON f.code = 'SCI'
JOIN departments d ON d.code = 'CS'
JOIN courses c ON c.code = 'DLS101'
WHERE u.email = 'student@library.rw'
ON DUPLICATE KEY UPDATE faculty_id = VALUES(faculty_id), department_id = VALUES(department_id), course_id = VALUES(course_id);

INSERT INTO lecturer_courses (lecturer_id, course_id, status)
SELECT u.id, c.id, 'active'
FROM users u
JOIN courses c ON c.code IN ('DLS101', 'HCI201')
WHERE u.email = 'lecturer@library.rw'
ON DUPLICATE KEY UPDATE status = 'active';

INSERT INTO lecturer_courses (lecturer_id, course_id, status)
SELECT u.id, c.id, 'active'
FROM users u
JOIN courses c ON c.code IN ('DLS101', 'HCI201')
WHERE u.email = 'lecturer@gmail.com'
ON DUPLICATE KEY UPDATE status = 'active';

INSERT INTO books (author_id, category_id, faculty_id, department_id, course_id, title, isbn, publisher, publication_year, description, keywords, shelf_location, total_copies, available_copies, status)
SELECT a.id, cat.id, f.id, d.id, c.id, 'Digital Library Systems', '978-1-00101-001-1', 'Rwanda Library Press', 2022,
       'A practical guide to architecture, access, search, and preservation in digital libraries.',
       'digital library search metadata preservation', 'SCI-CS-001', 8, 5, 'active'
FROM authors a, categories cat, faculties f, departments d, courses c
WHERE a.full_name = 'Christine Borgman' AND cat.name = 'Information Science' AND f.code = 'SCI' AND d.code = 'CS' AND c.code = 'DLS101'
ON DUPLICATE KEY UPDATE available_copies = VALUES(available_copies), total_copies = VALUES(total_copies), status = 'active';

INSERT INTO books (author_id, category_id, faculty_id, department_id, course_id, title, isbn, publisher, publication_year, description, keywords, shelf_location, total_copies, available_copies, status)
SELECT a.id, cat.id, f.id, d.id, c.id, 'Human Computer Interaction', '978-1-00102-002-8', 'Rwanda Library Press', 2021,
       'Foundations for usable, accessible, and human-centered interactive systems.',
       'hci usability accessibility interface', 'SCI-CS-002', 5, 2, 'active'
FROM authors a, categories cat, faculties f, departments d, courses c
WHERE a.full_name = 'Alan Dix' AND cat.name = 'Design' AND f.code = 'SCI' AND d.code = 'CS' AND c.code = 'HCI201'
ON DUPLICATE KEY UPDATE available_copies = VALUES(available_copies), total_copies = VALUES(total_copies), status = 'active';

INSERT INTO book_courses (book_id, course_id, status)
SELECT b.id, c.id, 'active'
FROM books b
JOIN courses c ON c.id = b.course_id
WHERE b.status = 'active'
ON DUPLICATE KEY UPDATE status = 'active';

INSERT INTO reading_lists (lecturer_id, course_id, title, description, visibility, status)
SELECT u.id, c.id, 'HCI Week 1', 'Core introductions for interaction design and accessibility discussions.', 'published', 'active'
FROM users u, courses c
WHERE u.email = 'lecturer@gmail.com' AND c.code = 'HCI201'
  AND NOT EXISTS (SELECT 1 FROM reading_lists rl WHERE rl.lecturer_id = u.id AND rl.course_id = c.id AND rl.title = 'HCI Week 1');

INSERT INTO reading_lists (lecturer_id, course_id, title, description, visibility, status)
SELECT u.id, c.id, 'Database Core Reading', 'Database and digital-library architecture materials for practical labs.', 'draft', 'active'
FROM users u, courses c
WHERE u.email = 'lecturer@gmail.com' AND c.code = 'DLS101'
  AND NOT EXISTS (SELECT 1 FROM reading_lists rl WHERE rl.lecturer_id = u.id AND rl.course_id = c.id AND rl.title = 'Database Core Reading');

INSERT INTO reading_list_books (reading_list_id, book_id, requirement_type, sort_order, status)
SELECT rl.id, b.id, 'required', 1, 'active'
FROM reading_lists rl, books b
WHERE rl.title = 'HCI Week 1' AND b.title = 'Human Computer Interaction'
ON DUPLICATE KEY UPDATE requirement_type = VALUES(requirement_type), sort_order = VALUES(sort_order), status = 'active';

INSERT INTO reading_list_books (reading_list_id, book_id, requirement_type, sort_order, status)
SELECT rl.id, b.id, 'required', 1, 'active'
FROM reading_lists rl, books b
WHERE rl.title = 'Database Core Reading' AND b.title = 'Digital Library Systems'
ON DUPLICATE KEY UPDATE requirement_type = VALUES(requirement_type), sort_order = VALUES(sort_order), status = 'active';

INSERT INTO reading_list_books (reading_list_id, book_id, requirement_type, sort_order, status)
SELECT rl.id, b.id, 'recommended', 2, 'active'
FROM reading_lists rl, books b
WHERE rl.title = 'Database Core Reading' AND b.title = 'Human Computer Interaction'
ON DUPLICATE KEY UPDATE requirement_type = VALUES(requirement_type), sort_order = VALUES(sort_order), status = 'active';

INSERT INTO system_settings (setting_key, setting_value, value_type, description, is_public)
VALUES
  ('system_name', 'MULTILINGUAL DIGITAL LIBRARY', 'string', 'System display name', 1),
  ('institution_name', 'Rwanda Library Network', 'string', 'Institution name', 1),
  ('borrow_duration_days', '14', 'number', 'Default borrowing duration', 0),
  ('max_borrowed_books', '5', 'number', 'Maximum active borrowed books per user', 0),
  ('allowed_upload_types', 'pdf,docx,txt,jpg,png', 'string', 'Allowed uploaded file types', 0),
  ('upload_size_limit_mb', '25', 'number', 'Maximum upload size in MB', 0),
  ('stt_service_url', 'http://127.0.0.1:5006/api/stt/transcribe', 'string', 'Primary local Wav2Vec2 STT service URL', 0),
  ('tts_engine', 'SpeechT5 Transformer TTS via scripts/speecht5_synthesize.py with gTTS fallback', 'string', 'Backend English narration engine', 0)
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), description = VALUES(description);

INSERT INTO notifications (user_id, type, title, message)
SELECT u.id, 'system', 'Welcome to MULTILINGUAL DIGITAL LIBRARY', 'Your account is ready for the intelligent digital library.'
FROM users u
WHERE u.email IN ('student@library.rw', 'lecturer@library.rw', 'library@gmail.com', 'lecturer@gmail.com')
  AND NOT EXISTS (
    SELECT 1
    FROM notifications n
    WHERE n.user_id = u.id
      AND n.type = 'system'
      AND n.title = 'Welcome to MULTILINGUAL DIGITAL LIBRARY'
      AND n.status = 'active'
  );
