import { apiRequest } from './client.js';

export function getAnalytics() {
  return apiRequest('/analytics', {}, (db) => ({
    totalUsers: db.users.length,
    totalBooks: db.books.length,
    availableBooks: db.books.reduce((sum, book) => sum + book.availableCopies, 0),
    borrowedBooks: db.borrowings.length,
    overdueBooks: 1,
    voiceSearches: 42,
    ttsPlays: 28,
  }));
}

export function getUsageAnalytics() {
  return apiRequest('/analytics/usage');
}

export function getStartupLayers() {
  return apiRequest('/startup/layers');
}
