import { apiRequest } from './client.js';

export function getSettings() {
  return apiRequest('/settings').then((response) => {
    if (Array.isArray(response.data)) {
      const mapped = {};
      response.data.forEach((item) => {
        mapped[item.setting_key] = item.setting_value;
      });
      return {
        ...response,
        data: {
          systemName: mapped.system_name,
          borrowDuration: mapped.borrow_duration_days,
          maxBorrowedBooks: mapped.max_borrowed_books,
          uploadLimitMb: mapped.upload_size_limit_mb,
          allowedFileTypes: mapped.allowed_upload_types,
        },
      };
    }
    return response;
  });
}

export function updateSettings(payload) {
  return apiRequest('/settings', {
    method: 'PUT',
    body: JSON.stringify({
      system_name: payload.systemName,
      borrow_duration_days: payload.borrowDuration,
      max_borrowed_books: payload.maxBorrowedBooks,
      upload_size_limit_mb: payload.uploadLimitMb,
      allowed_upload_types: payload.allowedFileTypes,
    }),
  });
}
