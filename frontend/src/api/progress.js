import { apiRequest } from './client.js';
import { normalizeBorrowing } from './normalizers.js';

export function listProgress() {
  return apiRequest('/progress', {}, (db) => db.borrowings).then((response) => ({
    ...response,
    data: (response.data || []).map(normalizeBorrowing),
  }));
}

export function getProgress(bookId) {
  return apiRequest(`/progress/${bookId}`);
}

export function updateProgress(bookId, progress) {
  return apiRequest(`/progress/${bookId}`, { method: 'PATCH', body: JSON.stringify({ book_id: Number(bookId), ...progress }) }, { bookId, ...progress });
}
