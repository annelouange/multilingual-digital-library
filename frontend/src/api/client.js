const DEFAULT_API_BASE_URL = 'http://localhost/digital-library/backend';
const configuredBaseUrl = import.meta.env.VITE_API_BASE_URL || DEFAULT_API_BASE_URL;

export const API_BASE_URL = configuredBaseUrl;

async function request(path, options = {}) {
  const token = localStorage.getItem('mdl_token');
  const isFormData = options.body instanceof FormData;
  const headers = {
    ...options.headers,
  };

  if (!isFormData) headers['Content-Type'] = headers['Content-Type'] || 'application/json';
  if (token) headers.Authorization = `Bearer ${token}`;

  let response;
  try {
    response = await fetch(`${configuredBaseUrl}${path}`, { ...options, headers });
  } catch {
    throw new Error('Unable to reach the library backend. Confirm Apache and MySQL are running.');
  }

  const contentType = response.headers.get('content-type') || '';
  const body = contentType.includes('application/json')
    ? await response.json()
    : { message: await response.text() };

  if (!response.ok) {
    const error = new Error(body.message || `Request failed with status ${response.status}`);
    error.status = response.status;
    if (response.status === 401 && path !== '/auth/login') {
      window.dispatchEvent(new Event('mdl:unauthorized'));
    }
    throw error;
  }

  return body;
}

export function apiRequest(path, options = {}) {
  return request(path, options);
}

export function asQuery(params = {}) {
  const query = new URLSearchParams(
    Object.entries(params).filter(([, value]) => value !== undefined && value !== '')
  ).toString();
  return query ? `?${query}` : '';
}
