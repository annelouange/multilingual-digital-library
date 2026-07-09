const API = process.env.MDL_API_URL || 'http://localhost/multilingual-library-startup/backend';
const EMAIL = process.env.MDL_ADMIN_EMAIL || 'library@gmail.com';
const PASSWORD = process.env.MDL_ADMIN_PASSWORD || '12345678';

async function timed(label, fn, budgetMs = 1500) {
  const started = performance.now();
  const result = await fn();
  const ms = Math.round(performance.now() - started);
  const ok = ms <= budgetMs;
  console.log(JSON.stringify({ label, ms, budgetMs, ok, ...result }));
  if (!ok) process.exitCode = 1;
}

async function request(path, options = {}) {
  const res = await fetch(`${API}${path}`, options);
  const raw = await res.text();
  const json = raw ? JSON.parse(raw.replace(/^\uFEFF+/, '')) : {};
  if (!res.ok || json.success === false) throw new Error(`${path} failed: ${res.status} ${json.message || ''}`);
  return json.data;
}

const login = await request('/auth/login', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ email: EMAIL, password: PASSWORD }),
});
const headers = { Authorization: `Bearer ${login.token}` };

await timed('metadata + content search', async () => {
  const data = await request('/search/books?q=content&language=en', { headers });
  return { books: data.results?.length || 0, contentMatches: data.content_matches?.length || 0 };
});

await timed('inside-book search', async () => {
  const data = await request('/books/71/search?q=culture&limit=8', { headers });
  return { matches: data.matches?.length || 0 };
});

await timed('retrieval-first context', async () => {
  const data = await request('/ai/retrieve?q=student%20culture&limit=6', { headers });
  return { chunks: data.chunks?.length || 0, retrievalFirst: data.retrieval_first === true };
});
