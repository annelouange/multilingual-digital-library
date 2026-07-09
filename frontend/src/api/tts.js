import { API_BASE_URL, apiRequest } from './client.js';

export function synthesize(payload) {
  return apiRequest('/tts', { method: 'POST', body: JSON.stringify(payload) });
}

export function listTtsLogs() {
  return apiRequest('/tts/logs');
}

export function audioUrl(path) {
  const token = localStorage.getItem('mdl_token') || '';
  const query = token ? `?token=${encodeURIComponent(token)}` : '';
  return `${API_BASE_URL}${path}${query}`;
}
