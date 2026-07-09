import { apiRequest } from './client.js';

const reportPaths = {
  books: 'books',
  borrowing: 'borrowing',
  users: 'users',
  'voice-search': 'voice-search',
  tts: 'tts',
  activity: 'activity',
};

export function getReport(type) {
  const path = reportPaths[type];
  if (!path) throw new Error('Unknown report type');
  return apiRequest(`/reports/${path}`);
}

export function toCsv(rows = []) {
  if (!rows.length) return '';
  const columns = [...new Set(rows.flatMap((row) => Object.keys(row)))];
  const escape = (value) => `"${String(value ?? '').replaceAll('"', '""')}"`;
  return [columns.map(escape).join(','), ...rows.map((row) => columns.map((column) => escape(row[column])).join(','))].join('\r\n');
}

export function toPrintableHtml(type, rows = []) {
  const columns = [...new Set(rows.flatMap((row) => Object.keys(row)))];
  const escape = (value) => String(value ?? '').replace(/[&<>\"]/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '\"': '&quot;',
  }[char]));
  const title = `${String(type).replaceAll('-', ' ')} report`;
  return `<!doctype html><html><head><meta charset="utf-8"><title>${escape(title)}</title><style>
    @page{size:landscape;margin:12mm}body{font-family:Arial,sans-serif;color:#17211e;margin:0}h1{color:#176b55;font-size:22px;margin:0 0 6px}.meta{color:#5e6e68;margin:0 0 18px}table{border-collapse:collapse;width:100%;font-size:10px}th,td{border:1px solid #cbd7d2;padding:6px;text-align:left;vertical-align:top;overflow-wrap:anywhere}th{background:#176b55;color:#fff}tr:nth-child(even) td{background:#f5f8f6}</style></head><body><h1>${escape(title)}</h1><p class="meta">MULTILINGUAL DIGITAL LIBRARY | Generated ${escape(new Date().toLocaleString())} | ${rows.length} record(s)</p><table><thead><tr>${columns.map((column) => `<th>${escape(column)}</th>`).join('')}</tr></thead><tbody>${rows.map((row) => `<tr>${columns.map((column) => `<td>${escape(row[column])}</td>`).join('')}</tr>`).join('')}</tbody></table></body></html>`;
}
