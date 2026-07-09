import { apiRequest, asQuery } from './client.js';

export function listAcademic(type, params = {}) {
  return apiRequest(`/${type}${asQuery(params)}`);
}

export function createAcademic(type, payload) {
  return apiRequest(`/${type}`, { method: 'POST', body: JSON.stringify(payload) });
}

export function updateAcademic(type, id, payload) {
  return apiRequest(`/${type}/${id}`, { method: 'PUT', body: JSON.stringify(payload) });
}

export function archiveAcademic(type, id) {
  return apiRequest(`/${type}/${id}`, { method: 'DELETE' });
}

export function assignLecturerCourse(lecturerId, courseId) {
  return apiRequest('/academic/lecturer-courses', {
    method: 'POST',
    body: JSON.stringify({ lecturer_id: lecturerId, course_id: courseId }),
  });
}
