export function normalizeBook(book = {}) {
  return {
    ...book,
    author: book.author || book.full_name || 'Unknown Author',
    category: book.category || 'Uncategorized',
    faculty: book.faculty || '',
    department: book.department || '',
    course: book.course || '',
    availableCopies: book.availableCopies ?? book.available_copies ?? 0,
    totalCopies: book.totalCopies ?? book.total_copies ?? 0,
    publicationYear: book.publicationYear ?? book.publication_year,
    year: book.year ?? book.publication_year,
    files: book.files || [],
    coverImage: book.coverImage || book.cover_image || '',
    coverColor: book.coverColor || '#1f6b55',
  };
}

export function normalizeUser(user = {}) {
  return {
    ...user,
    name: user.name || user.full_name,
    role: user.role || (user.role_code === 'LIBRARIAN_ADMIN' ? 'librarian_admin' : String(user.role_code || '').toLowerCase()),
  };
}

export function normalizeBorrowing(row = {}) {
  return {
    ...row,
    bookTitle: row.bookTitle || row.book_title,
    dueDate: row.dueDate || row.due_date,
    progress: row.progress ?? row.progress_percentage ?? 0,
  };
}

export function normalizeReadingList(row = {}) {
  return {
    ...row,
    course: row.course || row.course_name || '',
    books: row.books ?? row.book_count ?? 0,
    status: row.status || row.visibility || 'draft',
  };
}
