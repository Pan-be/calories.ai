// Thin client for the Kalorie API: bearer token, JSON errors, authenticated photos.

const TOKEN_KEY = 'kalorie.token';

export class ApiError extends Error {
  constructor(status, code, message) {
    super(message);
    this.status = status;
    this.code = code;
  }
}

export function getToken() {
  try { return localStorage.getItem(TOKEN_KEY); } catch { return null; }
}

export function setToken(token) {
  try {
    if (token) localStorage.setItem(TOKEN_KEY, token);
    else localStorage.removeItem(TOKEN_KEY);
  } catch { /* private mode: the session lasts until the tab closes */ }
  memoryToken = token;
}

let memoryToken = getToken();
let onUnauthorized = () => {};

export function onLoggedOut(fn) { onUnauthorized = fn; }

export async function api(method, path, { json, form } = {}) {
  const headers = { Accept: 'application/json', 'X-Client': 'kalorie-web' };
  if (memoryToken) headers.Authorization = `Bearer ${memoryToken}`;
  let body;
  if (json !== undefined) {
    headers['Content-Type'] = 'application/json';
    body = JSON.stringify(json);
  } else if (form) {
    body = form;
  }

  let response;
  try {
    response = await fetch(`/api${path}`, { method, headers, body });
  } catch {
    throw new ApiError(0, 'offline', 'Brak połączenia z serwerem.');
  }
  const data = await response.json().catch(() => null);
  if (!response.ok) {
    const err = data?.error;
    if (response.status === 401 && memoryToken && !path.startsWith('/auth/')) {
      setToken(null);
      onUnauthorized();
    }
    throw new ApiError(response.status, err?.code || `http_${response.status}`,
      err?.message || (response.status === 413 ? 'Plik jest za duży.' : 'Coś poszło nie tak. Spróbuj jeszcze raz.'));
  }
  return data;
}

// Photos need the Authorization header, so they're fetched and shown as blob URLs.
const photoCache = new Map();

export function photo(url) {
  if (!url) return Promise.resolve(null);
  if (!photoCache.has(url)) {
    const p = fetch(url, { headers: { Authorization: `Bearer ${memoryToken}` } })
      .then((r) => (r.ok ? r.blob() : null))
      .then((b) => (b ? URL.createObjectURL(b) : null))
      .catch(() => null);
    photoCache.set(url, p);
  }
  return photoCache.get(url);
}

export function forgetPhotos() {
  photoCache.forEach((p) => p.then((u) => u && URL.revokeObjectURL(u)));
  photoCache.clear();
}
