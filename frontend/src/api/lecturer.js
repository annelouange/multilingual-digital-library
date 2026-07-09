import { API_BASE_URL, apiRequest } from './client.js';

export function getResources() {
  return apiRequest('/lecturer/resources');
}

export function getEngagement() {
  return apiRequest('/lecturer/engagement');
}

export function listLectureNotes() {
  return apiRequest('/lecture-notes');
}

export function uploadLectureNote(payload, file) {
  const form = new FormData();
  Object.entries(payload).forEach(([key, value]) => {
    if (value !== '' && value !== undefined && value !== null) form.append(key, value);
  });
  form.append('file', file);
  return apiRequest('/lecture-notes', { method: 'POST', body: form });
}

export function moderateLectureNote(id, status) {
  return apiRequest(`/lecture-notes/${id}/moderate`, {
    method: 'PATCH',
    body: JSON.stringify({ status }),
  });
}

export function lectureNoteDownloadUrl(id) {
  const token = localStorage.getItem('mdl_token') || '';
  return `${API_BASE_URL}/lecture-notes/${id}/download${token ? `?token=${encodeURIComponent(token)}` : ''}`;
}
