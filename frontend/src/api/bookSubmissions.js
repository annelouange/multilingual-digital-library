import { API_BASE_URL, apiRequest, asQuery } from './client.js';

export function submitBook(payload, file) {
  const form = new FormData();
  Object.entries(payload).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') {
      form.append(key, value);
    }
  });
  form.append('file', file);
  return apiRequest('/book-submissions', { method: 'POST', body: form });
}

export function mySubmissions() {
  return apiRequest('/book-submissions/my');
}

export function listSubmissions(params = {}) {
  return apiRequest(`/book-submissions${asQuery(params)}`);
}

export function approveSubmission(id, reviewNote = '') {
  return apiRequest(`/book-submissions/${id}/approve`, {
    method: 'PATCH',
    body: JSON.stringify({ review_note: reviewNote }),
  });
}

export function rejectSubmission(id, reviewNote = '') {
  return apiRequest(`/book-submissions/${id}/reject`, {
    method: 'PATCH',
    body: JSON.stringify({ review_note: reviewNote }),
  });
}

export function submissionDownloadUrl(id) {
  const token = localStorage.getItem('mdl_token') || '';
  const query = token ? `?token=${encodeURIComponent(token)}` : '';
  return `${API_BASE_URL}/book-submissions/${id}/download${query}`;
}
