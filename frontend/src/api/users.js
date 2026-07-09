import { apiRequest } from './client.js';
import { normalizeUser } from './normalizers.js';

export function listUsers(params = {}) {
  return apiRequest('/users', {}, (db) => {
    if (!params.role) return db.users;
    return db.users.filter((user) => user.role === params.role);
  }).then((response) => ({ ...response, data: (response.data || []).map(normalizeUser) }));
}

export function updateUser(id, payload) {
  return apiRequest(`/users/${id}`, { method: 'PUT', body: JSON.stringify(payload) }, { id, ...payload });
}
