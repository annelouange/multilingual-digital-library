import { apiRequest, asQuery } from './client.js';

export function listReviews(bookId) {
  return apiRequest(`/reviews/${bookId}`, {}, [
    { id: 1, author: 'Student reviewer', rating: 5, text: 'Useful and easy to understand.' },
  ]);
}

export function submitReview(payload) {
  return apiRequest('/reviews', { method: 'POST', body: JSON.stringify(payload) }, payload);
}

export function listAllReviews(params = {}) {
  return apiRequest(`/reviews${asQuery(params)}`);
}

export function moderateReview(id, status, note = '') {
  return apiRequest(`/reviews/${id}/moderate`, {
    method: 'PATCH',
    body: JSON.stringify({ status, note }),
  });
}
