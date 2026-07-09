import { apiRequest } from './client.js';

export function listLanguages() {
  return apiRequest('/languages');
}

export function translationHealth() {
  return apiRequest('/translation/health');
}

export function translateText(payload) {
  return apiRequest('/translation/translate', { method: 'POST', body: JSON.stringify(payload) });
}

export function translateBook(bookId, payload) {
  return apiRequest(`/books/${bookId}/translate`, { method: 'POST', body: JSON.stringify(payload) });
}