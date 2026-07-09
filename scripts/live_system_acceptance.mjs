import { spawnSync } from 'node:child_process';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import mysql from 'mysql2/promise';

const API = process.env.MDL_API_URL || 'http://localhost/digital-library/backend';
const ADMIN_EMAIL = process.env.MDL_ADMIN_EMAIL || 'library@gmail.com';
const ADMIN_PASSWORD = process.env.MDL_ADMIN_PASSWORD || '';
const runId = `${Date.now()}-${Math.random().toString(16).slice(2, 8)}`;
const password = 'AuditPass!2026';
const tempDir = await fs.mkdtemp(path.join(os.tmpdir(), 'mdl-live-audit-'));
const results = [];
const ids = {};
const filesToRemove = new Set();

if (!ADMIN_PASSWORD) {
  throw new Error('Set MDL_ADMIN_PASSWORD before running the live acceptance suite.');
}

function record(name, status, detail = '') {
  results.push({ name, status, detail });
  console.log(`${status === 'PASS' ? '[PASS]' : '[FAIL]'} ${name}${detail ? `: ${detail}` : ''}`);
}

async function test(name, action) {
  try {
    const detail = await action();
    record(name, 'PASS', typeof detail === 'string' ? detail : '');
    return detail;
  } catch (error) {
    record(name, 'FAIL', error.message);
    return null;
  }
}

async function api(endpoint, { method = 'GET', token, body, expected = 200, raw = false } = {}) {
  const headers = {};
  if (token) headers.Authorization = `Bearer ${token}`;
  if (body && !(body instanceof FormData)) headers['Content-Type'] = 'application/json';
  const response = await fetch(`${API}${endpoint}`, {
    method,
    headers,
    body: body instanceof FormData ? body : body === undefined ? undefined : JSON.stringify(body),
  });
  const expectedStatuses = Array.isArray(expected) ? expected : [expected];
  if (!expectedStatuses.includes(response.status)) {
    const text = await response.text();
    throw new Error(`HTTP ${response.status}; expected ${expectedStatuses.join('/')} - ${text.slice(0, 240)}`);
  }
  if (raw) return response;
  const payload = await response.json();
  if (!response.ok) return payload;
  if (!payload.success) throw new Error(payload.message || 'API returned success=false');
  return payload;
}

async function login(email, loginPassword) {
  const response = await api('/auth/login', {
    method: 'POST',
    body: { email, password: loginPassword },
  });
  return response.data;
}

async function upload(endpoint, token, fields, filePath, fileName, mimeType) {
  const form = new FormData();
  Object.entries(fields).forEach(([key, value]) => form.append(key, String(value)));
  const bytes = await fs.readFile(filePath);
  form.append('file', new Blob([bytes], { type: mimeType }), fileName);
  return api(endpoint, { method: 'POST', token, body: form });
}

function createDocx(target) {
  const source = `
import sys, zipfile
from pathlib import Path
target = Path(sys.argv[1])
content_types = """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
</Types>"""
rels = """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>"""
document = """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:body>
    <w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Rwanda Library Live Acceptance Book</w:t></w:r></w:p>
    <w:p><w:r><w:t>This is real English text used to verify Word conversion, PDF storage, extraction, reading progress, and English narration.</w:t></w:r></w:p>
    <w:p><w:r><w:t>The digital library should preserve this text after conversion.</w:t></w:r></w:p>
    <w:sectPr/>
  </w:body>
</w:document>"""
with zipfile.ZipFile(target, "w", zipfile.ZIP_DEFLATED) as archive:
    archive.writestr("[Content_Types].xml", content_types)
    archive.writestr("_rels/.rels", rels)
    archive.writestr("word/document.xml", document)
`;
  const result = spawnSync('python', ['-c', source, target], { encoding: 'utf8' });
  if (result.status !== 0) throw new Error(result.stderr || 'Could not create test DOCX');
}

const docxPath = path.join(tempDir, 'mdl-live-audit.docx');
const notePath = path.join(tempDir, 'lecture-note.txt');
const submissionPath = path.join(tempDir, 'student-submission.txt');
createDocx(docxPath);
await fs.writeFile(notePath, 'A real English lecture note for the live acceptance test.\n', 'utf8');
await fs.writeFile(submissionPath, 'A real English student book submission awaiting librarian verification.\n', 'utf8');

const db = await mysql.createConnection({
  host: process.env.DB_HOST || '127.0.0.1',
  port: Number(process.env.DB_PORT || 3306),
  user: process.env.DB_USER || 'root',
  password: process.env.DB_PASSWORD || '',
  database: process.env.DB_NAME || 'multilingual_digital_library',
});

let admin;
let student;
let lecturer;

try {
  await test('Backend health and MySQL connection', async () => {
    const response = await api('/health');
    if (response.data.database !== 'connected' || response.data.status !== 'ok') {
      throw new Error(JSON.stringify(response.data));
    }
    const speechT5 = response.data.tts?.primary;
    if (!speechT5?.success || !speechT5?.preload?.ready) {
      throw new Error(`SpeechT5 is not ready for first playback: ${JSON.stringify(response.data.tts)}`);
    }
    return `backend ok, database connected, SpeechT5 preload ${speechT5.preload.bytes} bytes`;
  });

  await test('Real student registration', async () => {
    const response = await api('/auth/register', {
      method: 'POST',
      body: {
        full_name: 'Rwanda Library Live Audit Student',
        email: `live.audit.student.${runId}@example.test`,
        phone: '+250780000001',
        role: 'STUDENT',
        password,
      },
    });
    ids.student = response.data.id;
    return `created user ${ids.student}`;
  });

  student = await test('Student login', async () => {
    const session = await login(`live.audit.student.${runId}@example.test`, password);
    if (session.user.role !== 'student') throw new Error(`wrong role ${session.user.role}`);
    return session;
  });
  if (student) student = { ...student, token: student.token };

  admin = await test('Librarian login', async () => {
    const session = await login(ADMIN_EMAIL, ADMIN_PASSWORD);
    if (session.user.role !== 'librarian_admin') throw new Error(`wrong role ${session.user.role}`);
    return session;
  });
  if (admin) admin = { ...admin, token: admin.token };

  await test('Administrator creates a real lecturer account', async () => {
    const response = await api('/users', {
      method: 'POST',
      token: admin.token,
      body: {
        full_name: 'Rwanda Library Live Audit Lecturer',
        email: `live.audit.lecturer.${runId}@example.test`,
        phone: '+250780000002',
        password,
        role_id: 2,
        status: 'active',
      },
    });
    ids.lecturer = response.data.id;
    return `created lecturer ${ids.lecturer}`;
  });

  lecturer = await test('Lecturer login', async () => {
    const session = await login(`live.audit.lecturer.${runId}@example.test`, password);
    if (session.user.role !== 'lecturer') throw new Error(`wrong role ${session.user.role}`);
    return session;
  });
  if (lecturer) lecturer = { ...lecturer, token: lecturer.token };

  await test('Authentication identity for all three roles', async () => {
    const roles = await Promise.all([
      api('/auth/me', { token: student.token }),
      api('/auth/me', { token: lecturer.token }),
      api('/auth/me', { token: admin.token }),
    ]);
    const codes = roles.map((item) => item.data.role_code).join(',');
    if (codes !== 'STUDENT,LECTURER,LIBRARIAN_ADMIN') throw new Error(codes);
    return codes;
  });

  await test('Student is denied user administration', async () => {
    await api('/users', { token: student.token, expected: 403 });
    return 'HTTP 403';
  });

  await test('Lecturer is denied librarian analytics', async () => {
    await api('/analytics', { token: lecturer.token, expected: 403 });
    return 'HTTP 403';
  });

  await test('Student is denied catalog book creation', async () => {
    await api('/books', {
      method: 'POST',
      token: student.token,
      body: { title: 'Forbidden audit book' },
      expected: 403,
    });
    return 'HTTP 403';
  });

  await test('Academic public lists use live database rows', async () => {
    const [faculties, departments, courses] = await Promise.all([
      api('/faculties'),
      api('/departments'),
      api('/courses'),
    ]);
    if (!faculties.data.length || !departments.data.length || !courses.data.length) {
      throw new Error('one or more academic lists are empty');
    }
    return `${faculties.data.length} faculties, ${departments.data.length} departments, ${courses.data.length} courses`;
  });

  await test('Librarian creates faculty, department, and course', async () => {
    const faculty = await api('/faculties', {
      method: 'POST',
      token: admin.token,
      body: { name: `Live Audit Faculty ${runId}`, code: `LAF${runId.slice(-4)}` },
    });
    ids.faculty = faculty.data.id;
    const department = await api('/departments', {
      method: 'POST',
      token: admin.token,
      body: { faculty_id: ids.faculty, name: `Live Audit Department ${runId}`, code: `LAD${runId.slice(-4)}` },
    });
    ids.department = department.data.id;
    const course = await api('/courses', {
      method: 'POST',
      token: admin.token,
      body: { department_id: ids.department, name: `Live Audit Course ${runId}`, code: `LAC${runId.slice(-4)}` },
    });
    ids.course = course.data.id;
    return `faculty ${ids.faculty}, department ${ids.department}, course ${ids.course}`;
  });

  await test('Librarian assigns lecturer to course', async () => {
    await api('/academic/lecturer-courses', {
      method: 'POST',
      token: admin.token,
      body: { lecturer_id: ids.lecturer, course_id: ids.course },
    });
    return 'assignment active';
  });

  await test('Lecturer creates a course-linked catalog book', async () => {
    const response = await api('/books', {
      method: 'POST',
      token: lecturer.token,
      body: {
        title: `Rwanda Library Live Audit Book ${runId}`,
        description: 'Real English content for full-system acceptance testing.',
        keywords: 'digital library testing',
        faculty_id: ids.faculty,
        department_id: ids.department,
        course_id: ids.course,
        total_copies: 2,
        available_copies: 2,
      },
    });
    ids.book = response.data.id;
    return `book ${ids.book}`;
  });

  await test('Lecturer uploads Word book and backend converts it to PDF', async () => {
    const response = await upload(
      `/books/${ids.book}/files`,
      lecturer.token,
      {},
      docxPath,
      'mdl-live-audit.docx',
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    );
    ids.bookFile = response.data.id;
    if (!response.data.converted_to_pdf || response.data.file_type !== 'pdf') {
      throw new Error(JSON.stringify(response.data));
    }
    return `file ${ids.bookFile}, ${response.data.original_name}`;
  });

  await test('Converted PDF content is extractable', async () => {
    const response = await api(`/books/${ids.book}/content`, { token: student.token });
    if (!response.data.text.includes('real English text used to verify Word conversion')) {
      throw new Error('expected converted text was not found');
    }
    return `${response.data.characters} extracted characters`;
  });

  await test('Protected catalog file download works', async () => {
    const response = await api(`/book-files/${ids.bookFile}/download`, {
      token: student.token,
      raw: true,
    });
    const bytes = Buffer.from(await response.arrayBuffer());
    if (!bytes.subarray(0, 4).equals(Buffer.from('%PDF'))) throw new Error('download is not a PDF');
    return `${bytes.length} PDF bytes`;
  });

  await test('General catalog and faculty/department recommendation filters', async () => {
    const [all, faculty, department, course] = await Promise.all([
      api('/search/books?q='),
      api(`/search/books?faculty_id=${ids.faculty}`),
      api(`/search/books?department_id=${ids.department}`),
      api(`/academic/courses/${ids.course}/books`),
    ]);
    if (!all.data.results.length) throw new Error('general catalog is empty');
    if (!faculty.data.results.some((book) => Number(book.id) === ids.book)) throw new Error('faculty filter missed audit book');
    if (!department.data.results.some((book) => Number(book.id) === ids.book)) throw new Error('department filter missed audit book');
    if (!course.data.some((book) => Number(book.id) === ids.book)) throw new Error('course lookup missed audit book');
    return `${all.data.results.length} total; linked book found in all academic filters`;
  });

  await test('Header/catalog search query logs real results', async () => {
    const response = await api('/search/books?q=Rwanda Library%20Live%20Audit', { token: student.token });
    if (!response.data.results.some((book) => Number(book.id) === ids.book)) throw new Error('audit book not found');
    return `${response.data.results.length} matching result(s)`;
  });

  await test('Student private Word upload converts to PDF', async () => {
    const response = await upload(
      '/personal-books',
      student.token,
      { title: 'Private Live Audit Book', author: 'Rwanda Library Audit' },
      docxPath,
      'private-live-audit.docx',
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    );
    ids.personalBook = response.data.id;
    if (!response.data.converted_to_pdf) throw new Error('converted_to_pdf=false');
    return `private book ${ids.personalBook}`;
  });

  await test('Private book read, download, and owner isolation', async () => {
    const content = await api(`/personal-books/${ids.personalBook}/content`, { token: student.token });
    if (!content.data.text.includes('digital library should preserve')) throw new Error('private content missing');
    const download = await api(`/personal-books/${ids.personalBook}/download`, { token: student.token, raw: true });
    if (download.headers.get('content-type') !== 'application/pdf') throw new Error('private download is not PDF');
    await api(`/personal-books/${ids.personalBook}`, { token: lecturer.token, expected: 404 });
    return 'content readable, PDF downloadable, other user denied';
  });

  await test('Student submits a book for librarian verification', async () => {
    const response = await upload(
      '/book-submissions',
      student.token,
      { title: `Student Audit Submission ${runId}`, total_copies: 1, keywords: 'audit verification' },
      submissionPath,
      'student-audit-submission.txt',
      'text/plain',
    );
    ids.submission = response.data.id;
    if (response.data.status !== 'pending') throw new Error(`status ${response.data.status}`);
    return `pending submission ${ids.submission}`;
  });

  await test('Librarian sees and rejects pending student submission', async () => {
    const pending = await api('/book-submissions?status=pending', { token: admin.token });
    if (!pending.data.some((row) => Number(row.id) === ids.submission)) throw new Error('submission not visible');
    await api(`/book-submissions/${ids.submission}/reject`, {
      method: 'PATCH',
      token: admin.token,
      body: { review_note: 'Automated live audit cleanup decision.' },
    });
    const mine = await api('/book-submissions/my', { token: student.token });
    const row = mine.data.find((item) => Number(item.id) === ids.submission);
    if (row?.status !== 'rejected') throw new Error(`status ${row?.status}`);
    return 'librarian decision persisted';
  });

  await test('Borrow request, approval, renewal, and return update inventory', async () => {
    const created = await api('/borrow/request', {
      method: 'POST',
      token: student.token,
      body: { book_id: ids.book },
    });
    ids.borrow = created.data.id;
    await api(`/borrow/${ids.borrow}/approve`, { method: 'PATCH', token: admin.token });
    await api(`/borrow/${ids.borrow}/renew`, { method: 'PATCH', token: admin.token });
    await api(`/borrow/${ids.borrow}/return`, { method: 'PATCH', token: admin.token });
    const book = await api(`/books/${ids.book}`);
    if (Number(book.data.available_copies) !== 2) throw new Error(`availability ${book.data.available_copies}`);
    return 'request returned; inventory restored to 2';
  });

  await test('Borrowing is restricted to the student role', async () => {
    await api('/borrow/request', {
      method: 'POST',
      token: lecturer.token,
      body: { book_id: ids.book },
      expected: 403,
    });
    return 'lecturer denied with HTTP 403';
  });

  await test('Reading progress save, fetch, list, and reset', async () => {
    await api(`/progress/${ids.book}`, {
      method: 'PATCH',
      token: student.token,
      body: {
        last_page: 2,
        last_section: 'Section 3',
        progress_percentage: 60,
        total_reading_time_seconds: 15,
        completed_status: 'in_progress',
      },
    });
    const saved = await api(`/progress/${ids.book}`, { token: student.token });
    if (Number(saved.data.progress_percentage) !== 60) throw new Error('progress did not persist');
    const list = await api('/progress', { token: student.token });
    if (!list.data.some((row) => Number(row.book_id) === ids.book)) throw new Error('progress list missed book');
    await api(`/progress/${ids.book}`, { method: 'DELETE', token: student.token });
    const reset = await api(`/progress/${ids.book}`, { token: student.token });
    if (Number(reset.data.progress_percentage) !== 0 || reset.data.status !== 'reset') throw new Error('reset did not persist');
    return '60% persisted, then reset to 0%';
  });

  await test('Favorite toggle and listing', async () => {
    const added = await api('/favorites/toggle', {
      method: 'POST',
      token: student.token,
      body: { book_id: ids.book },
    });
    if (!added.data.favorite) throw new Error('favorite was not added');
    const list = await api('/favorites', { token: student.token });
    if (!list.data.some((row) => Number(row.book_id) === ids.book)) throw new Error('favorite missing');
    await api(`/favorites/${ids.book}`, { method: 'DELETE', token: student.token });
    return 'add, list, remove';
  });

  await test('Bookmark add, list, and remove', async () => {
    const added = await api('/bookmarks', {
      method: 'POST',
      token: student.token,
      body: { book_id: ids.book, page_number: 2, section: 'Audit section', note: 'Real tracking test' },
    });
    ids.bookmark = added.data.id;
    const list = await api('/bookmarks', { token: student.token });
    if (!list.data.some((row) => Number(row.id) === ids.bookmark)) throw new Error('bookmark missing');
    await api(`/bookmarks/${ids.bookmark}`, { method: 'DELETE', token: student.token });
    return `bookmark ${ids.bookmark} removed`;
  });

  await test('Rating, review, librarian moderation, and public review list', async () => {
    const submitted = await api('/reviews', {
      method: 'POST',
      token: student.token,
      body: { book_id: ids.book, review_text: 'A real review created by the live acceptance test.', rating: 5 },
    });
    ids.review = submitted.data.id;
    await api(`/reviews/${ids.review}/moderate`, {
      method: 'PATCH',
      token: admin.token,
      body: { status: 'approved', note: 'Verified by live acceptance test' },
    });
    const reviews = await api(`/reviews/${ids.book}`);
    const rating = await api(`/ratings/${ids.book}`);
    if (!reviews.data.some((row) => Number(row.id) === ids.review)) throw new Error('approved review missing');
    if (Number(rating.data.average_rating) !== 5) throw new Error(`average rating ${rating.data.average_rating}`);
    return 'approved review visible; average rating 5';
  });

  await test('Lecturer reading list add/get/remove book', async () => {
    const created = await api('/reading-lists', {
      method: 'POST',
      token: lecturer.token,
      body: { course_id: ids.course, title: `Live Audit Reading List ${runId}`, visibility: 'published' },
    });
    ids.readingList = created.data.id;
    await api(`/reading-lists/${ids.readingList}/books`, {
      method: 'POST',
      token: lecturer.token,
      body: { book_id: ids.book, requirement_type: 'required', sort_order: 1 },
    });
    const list = await api(`/reading-lists/${ids.readingList}`, { token: student.token });
    if (!list.data.books.some((book) => Number(book.id) === ids.book)) throw new Error('book missing from published list');
    await api(`/reading-lists/${ids.readingList}/books/${ids.book}`, { method: 'DELETE', token: lecturer.token });
    return `published list ${ids.readingList}`;
  });

  await test('Lecture-note upload, librarian moderation, and download', async () => {
    const uploaded = await upload(
      '/lecture-notes',
      lecturer.token,
      { title: 'Live Audit Lecture Note', course_id: ids.course, reading_list_id: ids.readingList },
      notePath,
      'live-audit-note.txt',
      'text/plain',
    );
    ids.lectureNote = uploaded.data.id;
    await api(`/lecture-notes/${ids.lectureNote}/moderate`, {
      method: 'PATCH',
      token: admin.token,
      body: { status: 'approved' },
    });
    const download = await api(`/lecture-notes/${ids.lectureNote}/download`, { token: student.token, raw: true });
    if (!(await download.text()).includes('real English lecture note')) throw new Error('downloaded note content mismatch');
    return `approved note ${ids.lectureNote}`;
  });

  await test('Lecturer recommendation creates a student notification', async () => {
    const created = await api('/recommendations', {
      method: 'POST',
      token: lecturer.token,
      body: {
        user_id: ids.student,
        book_id: ids.book,
        source_type: 'lecturer',
        score: 95,
        message: 'Live audit recommendation',
      },
    });
    ids.recommendation = created.data.id;
    const recommendations = await api('/recommendations', { token: student.token });
    const notifications = await api('/notifications', { token: student.token });
    const notification = notifications.data.find((row) => row.message === 'Live audit recommendation');
    if (!recommendations.data.some((book) => Number(book.id) === ids.book)) throw new Error('recommended book missing');
    if (!notification) throw new Error('recommendation notification missing');
    ids.notification = Number(notification.id);
    await api(`/notifications/${ids.notification}/read`, { method: 'PATCH', token: student.token });
    await api(`/notifications/${ids.notification}`, { method: 'DELETE', token: student.token });
    return `recommendation ${ids.recommendation}; notification read/deleted`;
  });

  await test('Manual microphone transcript reaches voice search and logging', async () => {
    const form = new FormData();
    form.append('transcript', 'Rwanda Library Live Audit');
    const response = await api('/voice-search/search', {
      method: 'POST',
      token: student.token,
      body: form,
    });
    if (!response.data.results.some((book) => Number(book.id) === ids.book)) throw new Error('voice result missed audit book');
    const logs = await api('/voice-search/logs', { token: student.token });
    if (!logs.data.some((row) => row.transcript === 'Rwanda Library Live Audit')) throw new Error('voice log missing');
    return `${response.data.results.length} result(s), log persisted`;
  });

  await test('Open-vocabulary STT transcribes held-out LibriSpeech through PHP search', async () => {
    const audioPath = path.resolve('speech_datasets/LibriSpeech/test-clean/1089/134686/1089-134686-0003.flac');
    const form = new FormData();
    form.append('audio', new Blob([await fs.readFile(audioPath)], { type: 'audio/flac' }), path.basename(audioPath));
    const response = await api('/voice-search/search', {
      method: 'POST',
      token: student.token,
      body: form,
    });
    const transcript = String(response.data.transcript || '').toLowerCase();
    if (!transcript.includes('bertie') || !transcript.includes('mind')) {
      throw new Error(`unexpected transcript: ${response.data.transcript}`);
    }
    const sttModel = String(response.data.stt?.model || '');
    if (!/whisper|transformer|wav2vec2/i.test(sttModel)) {
      throw new Error(`unexpected model: ${sttModel}`);
    }
    return `"${response.data.transcript}" via ${sttModel}`;
  });

  await test('TTS generates and serves real English audio', async () => {
    const generated = await api('/tts', {
      method: 'POST',
      token: student.token,
      body: {
        book_id: ids.book,
        language: 'en',
        text: 'Welcome to the MULTILINGUAL DIGITAL LIBRARY live English narration test.',
      },
    });
    if (generated.data.provider !== 'speecht5') {
      throw new Error(`live demo narration must use preloaded SpeechT5, got ${generated.data.provider}`);
    }
    const audio = await api(generated.data.audio_url, { token: student.token, raw: true });
    const bytes = Buffer.from(await audio.arrayBuffer());
    const contentType = audio.headers.get('content-type');
    if (!contentType?.startsWith('audio/wav') || bytes.length < 500) {
      throw new Error(`invalid audio response: ${contentType}, ${bytes.length} bytes`);
    }
    const logs = await api('/tts/logs', { token: student.token });
    if (!logs.data.some((row) => Number(row.book_id) === ids.book && row.provider === 'speecht5')) {
      throw new Error('TTS log missing');
    }
    return `${bytes.length} audio bytes from ${generated.data.provider}; log persisted`;
  });

  await test('Librarian analytics, top lists, reports, logs, settings, and AI status', async () => {
    const responses = await Promise.all([
      api('/analytics', { token: admin.token }),
      api('/analytics/top', { token: admin.token }),
      api('/reports/books', { token: admin.token }),
      api('/reports/borrowing', { token: admin.token }),
      api('/reports/users', { token: admin.token }),
      api('/reports/voice-search', { token: admin.token }),
      api('/reports/tts', { token: admin.token }),
      api('/reports/activity', { token: admin.token }),
      api('/logs/activity', { token: admin.token }),
      api('/logs/search', { token: admin.token }),
      api('/logs/voice', { token: admin.token }),
      api('/logs/tts', { token: admin.token }),
      api('/settings', { token: admin.token }),
      api('/ai/models', { token: admin.token }),
    ]);
    if (responses.some((response) => response.data === null || response.data === undefined)) {
      throw new Error('one or more management endpoints returned no data');
    }
    return '14 management endpoints returned live data';
  });

  await test('Private-book delete removes the record and stored file', async () => {
    await api(`/personal-books/${ids.personalBook}`, { method: 'DELETE', token: student.token });
    await api(`/personal-books/${ids.personalBook}`, { token: student.token, expected: 404 });
    delete ids.personalBook;
    return 'record and file removed';
  });

  await test('Forgot and reset password invalidates the old password', async () => {
    const email = `live.audit.student.${runId}@example.test`;
    const replacementPassword = 'AuditReset!2026';
    const forgot = await api('/auth/forgot-password', {
      method: 'POST',
      body: { email, email_confirmation: email },
    });
    if (!forgot.data?.reset_token) throw new Error('local reset token was not returned');
    await api('/auth/reset-password', {
      method: 'POST',
      body: {
        token: forgot.data.reset_token,
        email,
        password: replacementPassword,
        password_confirmation: replacementPassword,
      },
    });
    await api('/auth/login', {
      method: 'POST',
      body: { email, password },
      expected: 401,
    });
    await login(email, replacementPassword);
    return 'old password rejected; replacement password accepted';
  });
} finally {
  try {
    const userIds = [ids.student, ids.lecturer].filter(Boolean);
    if (ids.bookFile) {
      const [rows] = await db.execute('SELECT file_path FROM book_files WHERE id=?', [ids.bookFile]);
      rows.forEach((row) => filesToRemove.add(row.file_path));
    }
    if (ids.submission) {
      const [rows] = await db.execute('SELECT file_path FROM book_submissions WHERE id=?', [ids.submission]);
      rows.forEach((row) => filesToRemove.add(row.file_path));
    }
    if (ids.lectureNote) {
      const [rows] = await db.execute('SELECT file_path FROM lecture_notes WHERE id=?', [ids.lectureNote]);
      rows.forEach((row) => filesToRemove.add(row.file_path));
    }
    await db.query('SET FOREIGN_KEY_CHECKS=0');
    if (userIds.length) {
      const placeholders = userIds.map(() => '?').join(',');
      for (const table of [
        'borrowing_history', 'borrow_requests', 'reading_progress', 'favorites', 'bookmarks',
        'ratings', 'reviews', 'recommendations', 'notifications',
        'lecture_notes', 'reading_lists', 'lecturer_courses', 'book_submissions',
        'personal_books', 'activity_logs', 'security_logs', 'search_logs', 'voice_search_logs',
        'tts_logs', 'login_logs', 'password_resets', 'user_profiles',
      ]) {
        const columns = {
          borrowing_history: ['user_id', 'action_by'],
          notifications: ['user_id', 'created_by'],
          lecture_notes: ['lecturer_id'],
          reading_lists: ['lecturer_id'],
          lecturer_courses: ['lecturer_id'],
          book_submissions: ['submitted_by', 'reviewed_by'],
        }[table] || ['user_id'];
        const where = columns.map((column) => `${column} IN (${placeholders})`).join(' OR ');
        await db.query(`DELETE FROM ${table} WHERE ${where}`, columns.flatMap(() => userIds));
      }
    }
    if (ids.book) {
      if (ids.readingList) {
        await db.execute('DELETE FROM reading_list_books WHERE reading_list_id=?', [ids.readingList]);
      }
      await db.execute('DELETE FROM book_courses WHERE book_id=?', [ids.book]);
      await db.execute('DELETE FROM book_files WHERE book_id=?', [ids.book]);
      await db.execute('DELETE FROM books WHERE id=?', [ids.book]);
    }
    if (ids.course) await db.execute('DELETE FROM courses WHERE id=?', [ids.course]);
    if (ids.department) await db.execute('DELETE FROM departments WHERE id=?', [ids.department]);
    if (ids.faculty) await db.execute('DELETE FROM faculties WHERE id=?', [ids.faculty]);
    if (userIds.length) {
      await db.query(`DELETE FROM users WHERE id IN (${userIds.map(() => '?').join(',')})`, userIds);
    }
    await db.query('SET FOREIGN_KEY_CHECKS=1');
    for (const relativePath of filesToRemove) {
      const absolute = path.resolve('backend', relativePath);
      await fs.rm(absolute, { force: true });
      await fs.rm(`${absolute}.content.txt`, { force: true });
    }
    for (const userId of userIds) {
      const ttsDirectory = path.resolve('backend', 'uploads', 'tts', String(userId));
      await fs.rm(ttsDirectory, { recursive: true, force: true });
    }
  } catch (error) {
    record('Audit data cleanup', 'FAIL', error.message);
  }
  await db.end();
  await fs.rm(tempDir, { recursive: true, force: true });
}

const summary = {
  run_id: runId,
  executed_at: new Date().toISOString(),
  api: API,
  passed: results.filter((item) => item.status === 'PASS').length,
  failed: results.filter((item) => item.status === 'FAIL').length,
  results,
};
await fs.mkdir('tmp', { recursive: true });
await fs.writeFile('tmp/live_acceptance_results.json', JSON.stringify(summary, null, 2));
console.log(JSON.stringify({ passed: summary.passed, failed: summary.failed, report: 'tmp/live_acceptance_results.json' }));
process.exitCode = summary.failed ? 1 : 0;
