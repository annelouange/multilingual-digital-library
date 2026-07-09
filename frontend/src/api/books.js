import { API_BASE_URL, apiRequest, asQuery } from './client.js';
import { normalizeBook } from './normalizers.js';

export function listBooks(params = {}) {
  return apiRequest(`/books${asQuery(params)}`, {}, (db) => db.books).then((response) => ({
    ...response,
    data: (response.data || []).map(normalizeBook),
  }));
}

export function getBook(id) {
  return apiRequest(`/books/${id}`, {}, (db) => db.books.find((book) => String(book.id) === String(id))).then((response) => ({
    ...response,
    data: normalizeBook(response.data),
  }));
}

export function createBook(payload) {
  return apiRequest('/books', { method: 'POST', body: JSON.stringify(payload) }, payload);
}

export function updateBook(id, payload) {
  return apiRequest(`/books/${id}`, { method: 'PUT', body: JSON.stringify(payload) }, { id, ...payload });
}

export function deleteBook(id) {
  return apiRequest(`/books/${id}`, { method: 'DELETE' }, { id });
}

export function archiveBook(id) {
  return apiRequest(`/books/${id}/archive`, { method: 'PATCH' }, { id });
}

export function uploadBookFile(bookId, file) {
  const payload = new FormData();
  payload.append('file', file);
  return apiRequest(`/books/${bookId}/files`, { method: 'POST', body: payload });
}

export function uploadCatalogBook(metadata, file) {
  const payload = new FormData();
  Object.entries(metadata).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') {
      payload.append(key, value);
    }
  });
  payload.append('file', file);
  return apiRequest('/books/upload', { method: 'POST', body: payload });
}

export function getBookContent(bookId) {
  return apiRequest(`/books/${bookId}/content`);
}

export function getBookPage(bookId, page = 1) {
  return apiRequest(`/books/${bookId}/page${asQuery({ page })}`);
}

export function bookPageImageUrl(bookId, page = 1) {
  const token = localStorage.getItem('mdl_token') || '';
  const query = asQuery({ page, token });
  return `${API_BASE_URL}/books/${bookId}/page-image${query}`;
}

export function fileDownloadUrl(fileId) {
  const token = localStorage.getItem('mdl_token') || '';
  const query = token ? `?token=${encodeURIComponent(token)}` : '';
  return `${API_BASE_URL}/book-files/${fileId}/download${query}`;
}

export function fileStreamUrl(fileId) {
  const token = localStorage.getItem('mdl_token') || '';
  const query = token ? `?token=${encodeURIComponent(token)}` : '';
  return `${API_BASE_URL}/book-files/${fileId}/stream${query}`;
}

export function assetUrl(path) {
  if (!path) return '';
  if (/^https?:\/\//i.test(path)) return path;
  return `${API_BASE_URL}/${String(path).replace(/^\/+/, '')}`;
}
