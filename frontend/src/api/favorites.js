import { apiRequest } from './client.js';
import { normalizeBook } from './normalizers.js';

export function listFavorites() {
  return apiRequest('/favorites', {}, (db) => db.books.slice(0, 2)).then((response) => ({
    ...response,
    data: (response.data || []).map(normalizeBook),
  }));
}

export function toggleFavorite(bookId) {
  return apiRequest('/favorites/toggle', { method: 'POST', body: JSON.stringify({ bookId }) }, { bookId });
}
