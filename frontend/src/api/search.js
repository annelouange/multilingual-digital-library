import { apiRequest, asQuery } from './client.js';
import { normalizeBook } from './normalizers.js';

export function searchBooks(params = {}) {
  return apiRequest(`/search/books${asQuery(params)}`).then((response) => ({
    ...response,
    data: {
      results: (response.data?.results || []).map(normalizeBook),
      parsedQuery: response.data?.parsed_query || null,
      contentMatches: (response.data?.content_matches || []).map(normalizeBook),
    },
  }));
}

export function searchInsideBook(bookId, params = {}) {
  return apiRequest(`/books/${bookId}/search${asQuery(params)}`);
}

export function retrieveAiContext(params = {}) {
  return apiRequest(`/ai/retrieve${asQuery(params)}`);
}