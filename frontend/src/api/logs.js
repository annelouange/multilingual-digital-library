import { apiRequest, asQuery } from './client.js';

export function listActivityLogs() {
  return apiRequest('/logs/activity', {}, (db) => db.activity);
}

export function listSecurityLogs(params = {}) {
  return apiRequest(`/logs/security${asQuery(params)}`);
}
