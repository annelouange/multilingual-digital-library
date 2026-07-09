import { apiRequest } from './client.js';

export function listNotifications() {
  return apiRequest('/notifications');
}

export function markNotificationRead(id) {
  return apiRequest(`/notifications/${id}/read`, { method: 'PATCH' });
}

export function deleteNotification(id) {
  return apiRequest(`/notifications/${id}`, { method: 'DELETE' });
}
