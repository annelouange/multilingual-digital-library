import { apiRequest } from './client.js';

export async function login(email, password) {
  return apiRequest('/auth/login', { method: 'POST', body: JSON.stringify({ email, password }) });
}

export async function register(payload) {
  return apiRequest('/auth/register', {
    method: 'POST',
    body: JSON.stringify({
      ...payload,
      full_name: payload.full_name || payload.name,
      role: payload.role_code || payload.role?.toUpperCase?.() || payload.role,
    }),
  });
}

export async function me() {
  return apiRequest('/auth/me');
}

export async function logout() {
  return apiRequest('/auth/logout', { method: 'POST' });
}

export async function forgotPassword(email) {
  return apiRequest('/auth/forgot-password', {
    method: 'POST',
    body: JSON.stringify({ email }),
  });
}

export async function validateResetPassword(payload) {
  return apiRequest('/auth/reset-password/validate', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export async function resetPassword(payload) {
  return apiRequest('/auth/reset-password', { method: 'POST', body: JSON.stringify(payload) });
}
