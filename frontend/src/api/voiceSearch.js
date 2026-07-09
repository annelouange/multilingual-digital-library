import { apiRequest } from './client.js';
import { normalizeBook } from './normalizers.js';

export async function sendVoiceSearch(payload) {
  const response = await apiRequest('/voice-search/search', { method: 'POST', body: payload }, (db) => ({
    transcript: 'digital library systems',
    results: db.books.filter((book) => book.title.toLowerCase().includes('digital')),
  }));
  return {
    ...response,
    data: {
      ...response.data,
      results: (response.data?.results || []).map(normalizeBook),
    },
  };
}

export function listVoiceLogs() {
  return apiRequest('/voice-search/logs', {}, (db) => db.activity);
}

export function getAiModels() {
  return apiRequest('/ai/models');
}
