import { BookOpen, Search, Upload } from 'lucide-react';
import { useState } from 'react';
import { Link } from 'react-router-dom';
import * as booksApi from '../../api/books.js';
import Button from '../../components/Button.jsx';
import Card from '../../components/Card.jsx';
import PageHeader from '../../components/PageHeader.jsx';

const initialForm = {
  title: '',
  isbn: '',
  publisher: '',
  publication_year: '',
  description: '',
  keywords: '',
  total_copies: 1,
};

export default function UploadBook() {
  const [form, setForm] = useState(initialForm);
  const [file, setFile] = useState(null);
  const [fileKey, setFileKey] = useState(0);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [bookId, setBookId] = useState(null);

  async function handleSubmit(event) {
    event.preventDefault();
    if (!file) return;
    setBusy(true);
    setMessage('');
    setError('');
    setBookId(null);
    try {
      const response = await booksApi.uploadCatalogBook(form, file);
      setBookId(response.data?.id || null);
      setMessage(response.message || 'Book uploaded and published in the catalog.');
      setForm(initialForm);
      setFile(null);
      setFileKey((value) => value + 1);
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <PageHeader
        title="Upload book"
        description="Upload a book directly to the digital library. It is published immediately and becomes available in catalog and voice search."
      />
      {message && (
        <div className="inline-info">
          {message}
          {bookId && (
            <div className="button-row">
              <Link className="button button-secondary button-sm" to={`/books/${bookId}`}><BookOpen size={15} /> View book</Link>
              <Link className="button button-ghost button-sm" to="/search"><Search size={15} /> Search catalog</Link>
            </div>
          )}
        </div>
      )}
      {error && <div className="inline-error" role="alert">{error}</div>}

      <Card title="Book information" eyebrow="Direct catalog upload">
        <form className="form-grid" onSubmit={handleSubmit}>
          <label>Title<input value={form.title} onChange={(event) => setForm({ ...form, title: event.target.value })} required /></label>
          <label>ISBN<input value={form.isbn} onChange={(event) => setForm({ ...form, isbn: event.target.value })} /></label>
          <label>Publisher<input value={form.publisher} onChange={(event) => setForm({ ...form, publisher: event.target.value })} /></label>
          <label>Publication year<input type="number" min="1000" max="2100" value={form.publication_year} onChange={(event) => setForm({ ...form, publication_year: event.target.value })} /></label>
          <label>Number of copies<input type="number" min="1" value={form.total_copies} onChange={(event) => setForm({ ...form, total_copies: Number(event.target.value) })} /></label>
          <label>Keywords<input value={form.keywords} onChange={(event) => setForm({ ...form, keywords: event.target.value })} placeholder="Topics used by text and voice search" /></label>
          <label className="span-2">Description<textarea value={form.description} onChange={(event) => setForm({ ...form, description: event.target.value })} /></label>
          <label className="span-2">
            Book file
            <input
              key={fileKey}
              type="file"
              accept=".pdf,.docx,.txt,.mp3,.wav,.m4a,.ogg,.webm"
              onChange={(event) => setFile(event.target.files?.[0] || null)}
              required
            />
          </label>
          {file && <p className="file-hint span-2">{/\.docx$/i.test(file.name) ? `${file.name} will be converted to PDF before publication.` : file.name}</p>}
          <p className="file-hint span-2">This direct upload does not wait for librarian approval. Use Submit Book when librarian verification is required.</p>
          <Button type="submit" className="span-2" disabled={busy || !file}>
            <Upload size={16} /> {busy ? 'Uploading and publishing...' : 'Upload to catalog'}
          </Button>
        </form>
      </Card>
    </>
  );
}
