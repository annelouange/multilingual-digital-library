import { apiRequest } from './client.js';
import { normalizeBook } from './normalizers.js';

export function listRecommendations() {
  return apiRequest('/recommendations', {}, (db) => db.books.slice(0, 3)).then((response) => ({
    ...response,
    data: (response.data || []).map(normalizeBook),
  }));
}

export function createRecommendation(payload) {
  return apiRequest('/recommendations', { method: 'POST', body: JSON.stringify(payload) }, payload);
}

export function listManagedRecommendations() {
  return apiRequest('/recommendations/admin');
}

export function updateRecommendation(id, status) {
  return apiRequest(`/recommendations/${id}`, {
    method: 'PATCH',
    body: JSON.stringify({ status }),
  });
}
