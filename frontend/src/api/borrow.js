import { apiRequest } from './client.js';
import { normalizeBorrowing } from './normalizers.js';

export function requestBorrow(bookId) {
  return apiRequest('/borrow/request', { method: 'POST', body: JSON.stringify({ book_id: bookId }) }, { bookId, status: 'pending' });
}

export function myBorrowedBooks() {
  return apiRequest('/borrow/my-books', {}, (db) => db.borrowings).then((response) => ({
    ...response,
    data: (response.data || []).map(normalizeBorrowing),
  }));
}

export function listBorrowings() {
  return apiRequest('/borrow', {}, (db) => db.borrowings).then((response) => ({
    ...response,
    data: (response.data || []).map(normalizeBorrowing),
  }));
}

export function approveBorrowing(id) {
  return apiRequest(`/borrow/${id}/approve`, { method: 'PATCH' }, { id, status: 'approved' });
}

export function rejectBorrowing(id, reason) {
  return apiRequest(`/borrow/${id}/reject`, { method: 'PATCH', body: JSON.stringify({ reason }) }, { id, status: 'rejected', reason });
}

export function returnBorrowing(id) {
  return apiRequest(`/borrow/${id}/return`, { method: 'PATCH' }, { id, status: 'returned' });
}
