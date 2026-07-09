import { apiRequest } from './client.js';

export function listBookmarks() {
  return apiRequest('/bookmarks');
}

export function addBookmark(bookId, details = {}) {
  return apiRequest('/bookmarks', {
    method: 'POST',
    body: JSON.stringify({ book_id: Number(bookId), ...details }),
  });
}

export function removeBookmark(id) {
  return apiRequest(`/bookmarks/${id}`, { method: 'DELETE' });
}
