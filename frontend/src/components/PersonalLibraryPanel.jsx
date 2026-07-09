import { BookOpen, Headphones, Trash2, Upload } from 'lucide-react';
import { useState } from 'react';
import { Link } from 'react-router-dom';
import * as personalBooksApi from '../api/personalBooks.js';
import Button from './Button.jsx';
import Card from './Card.jsx';
import DataState from './DataState.jsx';
import { useAsync } from '../hooks/useAsync.js';

const emptyForm = { title: '', author: '', description: '' };

export default function PersonalLibraryPanel({ compact = false }) {
  const books = useAsync(personalBooksApi.listPersonalBooks, []);
  const [form, setForm] = useState(emptyForm);
  const [file, setFile] = useState(null);
  const [fileKey, setFileKey] = useState(0);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  async function upload(event) {
    event.preventDefault();
    setBusy(true);
    setMessage('');
    setError('');
    try {
      const response = await personalBooksApi.uploadPersonalBook(form, file);
      setMessage(response.message);
      setForm(emptyForm);
      setFile(null);
      setFileKey((value) => value + 1);
      books.reload();
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  }

  async function remove(book) {
    if (!window.confirm(`Permanently delete "${book.title}" from your personal reading shelf?`)) return;
    setMessage('');
    setError('');
    try {
      const response = await personalBooksApi.deletePersonalBook(book.id);
      setMessage(response.message);
      books.reload();
    } catch (err) {
      setError(err.message);
    }
  }

  return (
    <section className={compact ? 'personal-library-compact' : ''}>
      {message && <div className="inline-info">{message}</div>}
      {error && <div className="inline-error" role="alert">{error}</div>}
      <div className="personal-library-layout">
        <Card title="Upload an English book" eyebrow="Personal reading shelf">
          <form className="form-stack" onSubmit={upload}>
            <label>Title<input value={form.title} onChange={(event) => setForm({ ...form, title: event.target.value })} required /></label>
            <label>Author<input value={form.author} onChange={(event) => setForm({ ...form, author: event.target.value })} /></label>
            {!compact && <label>Description<textarea value={form.description} onChange={(event) => setForm({ ...form, description: event.target.value })} /></label>}
            <label>Book file
              <input key={fileKey} type="file" accept=".pdf,.docx,.txt" onChange={(event) => setFile(event.target.files?.[0] || null)} required />
            </label>
            <p className="file-hint">PDF, Word, or TXT. Word files are converted to PDF automatically. For the public catalog, use Upload Book from the student menu.</p>
            <Button type="submit" disabled={busy || !file}><Upload size={16} /> {busy ? 'Uploading...' : 'Add to my library'}</Button>
          </form>
        </Card>
        <Card title="My saved reading files" eyebrow={`${books.data?.length || 0} saved`}>
          <DataState loading={books.loading} error={books.error} empty={!books.data?.length} onRetry={books.reload}>
            <div className="personal-book-list">
              {(books.data || []).map((book) => (
                <article className="personal-book-row" key={book.id}>
                  <div className="personal-book-icon"><BookOpen size={20} /></div>
                  <div>
                    <strong>{book.title}</strong>
                    <span>{book.author || 'Unknown author'} · {book.file_type.toUpperCase()}</span>
                  </div>
                  <div className="button-row">
                    <Link className="button button-primary button-sm" to={`/my-library/${book.id}`}><Headphones size={15} /> Read or listen</Link>
                    <Button size="icon" variant="ghost" aria-label={`Delete ${book.title}`} onClick={() => remove(book)}><Trash2 size={15} /></Button>
                  </div>
                </article>
              ))}
            </div>
          </DataState>
        </Card>
      </div>
    </section>
  );
}
