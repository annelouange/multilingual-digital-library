import { apiRequest } from './client.js';
import { normalizeReadingList } from './normalizers.js';

export async function listReadingLists() {
  const response = await apiRequest('/reading-lists', {}, [
    { id: 1, title: 'HCI Week 1', course: 'HCI', books: 2, status: 'published' },
    { id: 2, title: 'Database Core Reading', course: 'Database Systems', books: 3, status: 'draft' },
  ]);
  return { ...response, data: (response.data || []).map(normalizeReadingList) };
}

export function createReadingList(payload) {
  return apiRequest('/reading-lists', { method: 'POST', body: JSON.stringify(payload) }, payload);
}
