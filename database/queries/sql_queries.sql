USE multilingual_digital_library;

-- AUTH
-- register user
INSERT INTO users (role_id, full_name, email, phone, gender, password_hash, status)
VALUES (:role_id, :full_name, :email, :phone, :gender, :password_hash, 'active');

-- login user / get user by email
SELECT u.*, r.code AS role_code, r.name AS role_name
FROM users u
JOIN roles r ON r.id = u.role_id
WHERE u.email = :email
LIMIT 1;

-- get user by ID
SELECT u.id, u.full_name, u.email, u.phone, u.gender, u.status, r.code AS role_code, r.name AS role_name,
       p.faculty_id, p.department_id, p.course_id
FROM users u
JOIN roles r ON r.id = u.role_id
LEFT JOIN user_profiles p ON p.user_id = u.id
WHERE u.id = :id;

-- update password
UPDATE users SET password_hash = :password_hash WHERE id = :id;

-- deactivate user
UPDATE users SET status = :status WHERE id = :id;

-- insert login log
INSERT INTO login_logs (user_id, email, ip_address, user_agent, status, failure_reason)
VALUES (:user_id, :email, :ip_address, :user_agent, :status, :failure_reason);

-- BOOKS
-- add book
INSERT INTO books (author_id, category_id, faculty_id, department_id, course_id, title, subtitle, isbn, publisher,
publication_year, description, keywords, shelf_location, total_copies, available_copies, created_by)
VALUES (:author_id, :category_id, :faculty_id, :department_id, :course_id, :title, :subtitle, :isbn, :publisher,
:publication_year, :description, :keywords, :shelf_location, :total_copies, :available_copies, :created_by);

-- edit book
UPDATE books SET author_id=:author_id, category_id=:category_id, faculty_id=:faculty_id, department_id=:department_id,
course_id=:course_id, title=:title, subtitle=:subtitle, isbn=:isbn, publisher=:publisher, publication_year=:publication_year,
description=:description, keywords=:keywords, shelf_location=:shelf_location, total_copies=:total_copies,
available_copies=:available_copies WHERE id=:id;

-- archive book
UPDATE books SET status='archived' WHERE id=:id;

-- delete book safely
UPDATE books SET status='deleted' WHERE id=:id;

-- get all books
SELECT b.*, a.full_name AS author, c.name AS category, f.name AS faculty, d.name AS department, co.name AS course
FROM books b
LEFT JOIN authors a ON a.id = b.author_id
LEFT JOIN categories c ON c.id = b.category_id
LEFT JOIN faculties f ON f.id = b.faculty_id
LEFT JOIN departments d ON d.id = b.department_id
LEFT JOIN courses co ON co.id = b.course_id
WHERE b.status <> 'deleted'
ORDER BY b.created_at DESC
LIMIT :limit OFFSET :offset;

-- get book by ID
SELECT b.*, a.full_name AS author, c.name AS category, f.name AS faculty, d.name AS department, co.name AS course
FROM books b
LEFT JOIN authors a ON a.id = b.author_id
LEFT JOIN categories c ON c.id = b.category_id
LEFT JOIN faculties f ON f.id = b.faculty_id
LEFT JOIN departments d ON d.id = b.department_id
LEFT JOIN courses co ON co.id = b.course_id
WHERE b.id=:id AND b.status <> 'deleted';

-- search/filter books
SELECT b.*, a.full_name AS author, c.name AS category, f.name AS faculty, d.name AS department, co.name AS course
FROM books b
LEFT JOIN authors a ON a.id = b.author_id
LEFT JOIN categories c ON c.id = b.category_id
LEFT JOIN faculties f ON f.id = b.faculty_id
LEFT JOIN departments d ON d.id = b.department_id
LEFT JOIN courses co ON co.id = b.course_id
WHERE b.status = 'active'
  AND (:q IS NULL OR b.title LIKE :like_title OR a.full_name LIKE :like_author OR b.isbn LIKE :like_isbn OR b.keywords LIKE :like_keywords)
  AND (:faculty_id IS NULL OR b.faculty_id = :faculty_id)
  AND (:department_id IS NULL OR b.department_id = :department_id)
  AND (:course_id IS NULL OR b.course_id = :course_id)
  AND (:category_id IS NULL OR b.category_id = :category_id)
  AND (:availability IS NULL OR (:availability='available' AND b.available_copies > 0) OR (:availability='unavailable' AND b.available_copies = 0))
ORDER BY b.title ASC
LIMIT :limit OFFSET :offset;

-- update available copies
UPDATE books SET available_copies = :available_copies WHERE id = :id;

-- ACADEMIC STRUCTURE
INSERT INTO faculties (name, code, description) VALUES (:name, :code, :description);
INSERT INTO departments (faculty_id, name, code, description) VALUES (:faculty_id, :name, :code, :description);
INSERT INTO courses (department_id, name, code, description) VALUES (:department_id, :name, :code, :description);
INSERT INTO book_courses (book_id, course_id) VALUES (:book_id, :course_id) ON DUPLICATE KEY UPDATE status='active';
INSERT INTO lecturer_courses (lecturer_id, course_id) VALUES (:lecturer_id, :course_id) ON DUPLICATE KEY UPDATE status='active';
SELECT * FROM books WHERE faculty_id=:faculty_id AND status='active';
SELECT * FROM books WHERE department_id=:department_id AND status='active';
SELECT * FROM books WHERE course_id=:course_id AND status='active';

-- BORROWING
INSERT INTO borrow_requests (user_id, book_id, status) VALUES (:user_id, :book_id, 'pending');
UPDATE borrow_requests SET status='approved', approved_by=:approved_by, approved_at=NOW(), due_date=:due_date WHERE id=:id;
UPDATE borrow_requests SET status='rejected', rejected_by=:rejected_by, rejected_at=NOW(), rejection_reason=:reason WHERE id=:id;
UPDATE borrow_requests SET status='returned', returned_at=NOW() WHERE id=:id;
UPDATE borrow_requests SET renewed_until=:renewed_until, due_date=:renewed_until WHERE id=:id;
SELECT br.*, b.title AS book_title FROM borrow_requests br JOIN books b ON b.id=br.book_id WHERE br.user_id=:user_id;
SELECT br.*, b.title AS book_title, u.full_name FROM borrow_requests br JOIN books b ON b.id=br.book_id JOIN users u ON u.id=br.user_id WHERE br.status='approved' AND br.due_date < CURDATE();
UPDATE books SET available_copies = available_copies - 1 WHERE id=:book_id AND available_copies > 0;
UPDATE books SET available_copies = available_copies + 1 WHERE id=:book_id AND available_copies < total_copies;

-- READING
INSERT INTO reading_progress (user_id, book_id, last_page, last_section, progress_percentage, total_reading_time_seconds, completed_status)
VALUES (:user_id, :book_id, :last_page, :last_section, :progress_percentage, :total_reading_time_seconds, :completed_status)
ON DUPLICATE KEY UPDATE last_page=VALUES(last_page), last_section=VALUES(last_section), progress_percentage=VALUES(progress_percentage),
total_reading_time_seconds=VALUES(total_reading_time_seconds), completed_status=VALUES(completed_status);
SELECT * FROM reading_progress WHERE user_id=:user_id AND book_id=:book_id;
UPDATE reading_progress SET status='reset', last_page=0, progress_percentage=0 WHERE user_id=:user_id AND book_id=:book_id;

-- FAVORITES / BOOKMARKS
INSERT INTO favorites (user_id, book_id) VALUES (:user_id, :book_id) ON DUPLICATE KEY UPDATE status='active';
UPDATE favorites SET status='removed' WHERE user_id=:user_id AND book_id=:book_id;
SELECT f.*, b.title, b.cover_image FROM favorites f JOIN books b ON b.id=f.book_id WHERE f.user_id=:user_id AND f.status='active';
INSERT INTO bookmarks (user_id, book_id, page_number, section, note) VALUES (:user_id, :book_id, :page_number, :section, :note);
UPDATE bookmarks SET status='removed' WHERE id=:id AND user_id=:user_id;
SELECT bm.*, b.title FROM bookmarks bm JOIN books b ON b.id=bm.book_id WHERE bm.user_id=:user_id AND bm.status='active';

-- REVIEWS / RATINGS
INSERT INTO ratings (user_id, book_id, rating) VALUES (:user_id, :book_id, :rating) ON DUPLICATE KEY UPDATE rating=VALUES(rating);
SELECT AVG(rating) AS average_rating, COUNT(*) AS total_ratings FROM ratings WHERE book_id=:book_id AND status='active';
INSERT INTO reviews (user_id, book_id, review_text) VALUES (:user_id, :book_id, :review_text);
UPDATE reviews SET review_text=:review_text WHERE id=:id AND user_id=:user_id;
UPDATE reviews SET status='deleted' WHERE id=:id AND user_id=:user_id;
UPDATE reviews SET status=:status, moderated_by=:moderated_by, moderated_at=NOW(), moderation_note=:note WHERE id=:id;

-- RECOMMENDATIONS
SELECT * FROM books WHERE faculty_id=:faculty_id AND status='active' ORDER BY created_at DESC LIMIT 20;
SELECT * FROM books WHERE department_id=:department_id AND status='active' ORDER BY created_at DESC LIMIT 20;
SELECT * FROM books WHERE course_id=:course_id AND status='active' ORDER BY created_at DESC LIMIT 20;
SELECT b.*, COUNT(br.id) AS borrow_count FROM books b JOIN borrow_requests br ON br.book_id=b.id WHERE br.status IN ('approved','returned') GROUP BY b.id ORDER BY borrow_count DESC LIMIT 20;
SELECT b.* FROM search_logs sl JOIN books b ON b.title LIKE CONCAT('%', sl.query_text, '%') WHERE sl.user_id=:user_id LIMIT 20;
SELECT DISTINCT b2.* FROM borrow_requests br JOIN books b1 ON b1.id=br.book_id JOIN books b2 ON b2.category_id=b1.category_id WHERE br.user_id=:user_id AND b2.id<>b1.id LIMIT 20;

-- READING LISTS
INSERT INTO reading_lists (lecturer_id, course_id, title, description, visibility) VALUES (:lecturer_id, :course_id, :title, :description, :visibility);
INSERT INTO reading_list_books (reading_list_id, book_id, requirement_type, sort_order) VALUES (:reading_list_id, :book_id, :requirement_type, :sort_order) ON DUPLICATE KEY UPDATE status='active';
UPDATE reading_list_books SET status='removed' WHERE reading_list_id=:reading_list_id AND book_id=:book_id;
SELECT rl.*, c.name AS course FROM reading_lists rl JOIN courses c ON c.id=rl.course_id WHERE rl.course_id=:course_id AND rl.status='active';
INSERT INTO lecture_notes (reading_list_id, lecturer_id, course_id, title, file_path, original_name, mime_type, file_size) VALUES (:reading_list_id, :lecturer_id, :course_id, :title, :file_path, :original_name, :mime_type, :file_size);

-- NOTIFICATIONS
INSERT INTO notifications (user_id, created_by, type, title, message) VALUES (:user_id, :created_by, :type, :title, :message);
SELECT * FROM notifications WHERE user_id=:user_id AND status='active' ORDER BY created_at DESC;
UPDATE notifications SET read_at=NOW() WHERE id=:id AND user_id=:user_id;
UPDATE notifications SET status='deleted' WHERE id=:id AND user_id=:user_id;

-- LOGS
INSERT INTO activity_logs (user_id, role_code, action, entity_type, entity_id, ip_address, user_agent, status, metadata) VALUES (:user_id, :role_code, :action, :entity_type, :entity_id, :ip_address, :user_agent, :status, :metadata);
INSERT INTO security_logs (user_id, event_type, severity, ip_address, user_agent, details) VALUES (:user_id, :event_type, :severity, :ip_address, :user_agent, :details);
INSERT INTO search_logs (user_id, query_text, filters, results_count, status) VALUES (:user_id, :query_text, :filters, :results_count, :status);
INSERT INTO voice_search_logs (user_id, audio_path, transcript, confidence, language, duration_seconds, results_count, status) VALUES (:user_id, :audio_path, :transcript, :confidence, :language, :duration_seconds, :results_count, :status);
INSERT INTO tts_logs (user_id, book_id, text_length, audio_path, provider, status) VALUES (:user_id, :book_id, :text_length, :audio_path, :provider, :status);
SELECT * FROM activity_logs WHERE created_at BETWEEN :from_date AND :to_date ORDER BY created_at DESC;
SELECT * FROM activity_logs WHERE user_id=:user_id ORDER BY created_at DESC;

-- ANALYTICS
SELECT COUNT(*) AS total_users FROM users WHERE status='active';
SELECT COUNT(*) AS total_books FROM books WHERE status='active';
SELECT COUNT(*) AS total_borrowed_books FROM borrow_requests WHERE status='approved';
SELECT SUM(available_copies) AS available_books FROM books WHERE status='active';
SELECT COUNT(*) AS overdue_books FROM borrow_requests WHERE status='approved' AND due_date < CURDATE();
SELECT query_text, COUNT(*) AS searches FROM search_logs GROUP BY query_text ORDER BY searches DESC LIMIT 10;
SELECT b.title, COUNT(*) AS borrow_count FROM borrow_requests br JOIN books b ON b.id=br.book_id GROUP BY b.id ORDER BY borrow_count DESC LIMIT 10;
SELECT u.full_name, COUNT(*) AS activity_count FROM activity_logs al JOIN users u ON u.id=al.user_id GROUP BY u.id ORDER BY activity_count DESC LIMIT 10;
SELECT COUNT(*) AS voice_search_usage FROM voice_search_logs;
SELECT COUNT(*) AS tts_usage FROM tts_logs;

-- REPORTS
SELECT b.title, b.isbn, b.total_copies, b.available_copies, b.status FROM books b ORDER BY b.title;
SELECT br.*, u.full_name, b.title FROM borrow_requests br JOIN users u ON u.id=br.user_id JOIN books b ON b.id=br.book_id WHERE br.created_at BETWEEN :from_date AND :to_date;
SELECT u.full_name, u.email, r.code AS role_code, COUNT(al.id) AS activities FROM users u JOIN roles r ON r.id=u.role_id LEFT JOIN activity_logs al ON al.user_id=u.id GROUP BY u.id;
SELECT br.*, u.full_name, b.title FROM borrow_requests br JOIN users u ON u.id=br.user_id JOIN books b ON b.id=br.book_id WHERE br.status='approved' AND br.due_date < CURDATE();
SELECT * FROM voice_search_logs WHERE created_at BETWEEN :from_date AND :to_date;
SELECT * FROM tts_logs WHERE created_at BETWEEN :from_date AND :to_date;
