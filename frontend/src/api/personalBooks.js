import { API_BASE_URL, apiRequest } from './client.js';

export function listPersonalBooks() {
  return apiRequest('/personal-books');
}

export function uploadPersonalBook(details, file) {
  const form = new FormData();
  Object.entries(details).forEach(([key, value]) => form.append(key, value));
  form.append('file', file);
  return apiRequest('/personal-books', { method: 'POST', body: form });
}

export function getPersonalBook(id) {
  return apiRequest(`/personal-books/${id}`);
}

export function getPersonalBookContent(id) {
  return apiRequest(`/personal-books/${id}/content`);
}

export function deletePersonalBook(id) {
  return apiRequest(`/personal-books/${id}`, { method: 'DELETE' });
}

export function personalBookDownloadUrl(id) {
  const token = localStorage.getItem('mdl_token') || '';
  const query = token ? `?token=${encodeURIComponent(token)}` : '';
  return `${API_BASE_URL}/personal-books/${id}/download${query}`;
}
