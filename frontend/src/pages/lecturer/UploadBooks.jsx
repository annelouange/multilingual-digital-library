import { Upload } from 'lucide-react';
import { useState } from 'react';
import * as booksApi from '../../api/books.js';
import Button from '../../components/Button.jsx';
import Card from '../../components/Card.jsx';
import DataState from '../../components/DataState.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Table from '../../components/Table.jsx';
import { useAsync } from '../../hooks/useAsync.js';

export default function UploadBooks() {
  const books = useAsync(() => booksApi.listBooks(), []);
  const [form, setForm] = useState({ title: '', isbn: '', publisher: '', publication_year: '', description: '', keywords: '', total_copies: 1 });
  const [selectedBookId, setSelectedBookId] = useState('');
  const [file, setFile] = useState(null);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  async function createBook(event) {
    event.preventDefault();
    setBusy(true);
    setMessage('');
    setError('');
    try {
      const response = await booksApi.createBook({ ...form, available_copies: form.total_copies });
      const bookId = response.data?.id || response.id;
      setSelectedBookId(String(bookId));
      setMessage('Book record created. Attach a readable file below.');
      setForm({ title: '', isbn: '', publisher: '', publication_year: '', description: '', keywords: '', total_copies: 1 });
      books.reload();
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  }

  async function uploadFile(event) {
    event.preventDefault();
    if (!selectedBookId || !file) return;
    setBusy(true);
    setMessage('');
    setError('');
    try {
      const response = await booksApi.uploadBookFile(selectedBookId, file);
      setFile(null);
      setMessage(response.data?.converted_to_pdf
        ? `${response.data.source_name} was converted to PDF and is ready for reading and audio narration.`
        : 'Book file uploaded and ready for reading and audio narration.');
      books.reload();
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  }

  const rows = (books.data || []).flatMap((book) => (book.files || []).map((item) => ({
    ...item,
    bookTitle: book.title,
    size: item.file_size ? `${Math.round(item.file_size / 1024)} KB` : '',
  })));

  return (
    <>
      <PageHeader title="Upload books" description="Create library records and attach PDF, Word, text, or audio files. Word files are converted to PDF automatically." />
      {message && <div className="inline-info">{message}</div>}
      {error && <div className="inline-error" role="alert">{error}</div>}
      <section className="upload-grid">
        <Card title="Create book">
          <form className="form-grid" onSubmit={createBook}>
            <label>Title<input value={form.title} onChange={(event) => setForm({ ...form, title: event.target.value })} required /></label>
            <label>ISBN<input value={form.isbn} onChange={(event) => setForm({ ...form, isbn: event.target.value })} /></label>
            <label>Publisher<input value={form.publisher} onChange={(event) => setForm({ ...form, publisher: event.target.value })} /></label>
            <label>Year<input type="number" value={form.publication_year} onChange={(event) => setForm({ ...form, publication_year: event.target.value })} /></label>
            <label>Total copies<input type="number" min="1" value={form.total_copies} onChange={(event) => setForm({ ...form, total_copies: Number(event.target.value) })} /></label>
            <label>Keywords<input value={form.keywords} onChange={(event) => setForm({ ...form, keywords: event.target.value })} /></label>
            <label className="span-2">Description<textarea value={form.description} onChange={(event) => setForm({ ...form, description: event.target.value })} /></label>
            <Button type="submit" className="span-2" disabled={busy}>Create book</Button>
          </form>
        </Card>
        <Card title="Attach file">
          <form className="form-stack" onSubmit={uploadFile}>
            <label>
              Book
              <select value={selectedBookId} onChange={(event) => setSelectedBookId(event.target.value)} required>
                <option value="">Select a book</option>
                {books.data?.map((book) => <option key={book.id} value={book.id}>{book.title}</option>)}
              </select>
            </label>
            <label>
              File
              <input type="file" accept=".pdf,.docx,.txt,.mp3,.wav,.m4a,.ogg,.webm" onChange={(event) => setFile(event.target.files?.[0] || null)} required />
            </label>
            {file && <p className="file-hint">{/\.docx$/i.test(file.name) ? 'This Word file will be converted to PDF during upload.' : file.name}</p>}
            <Button type="submit" disabled={busy || !file}><Upload size={16} /> {busy ? 'Processing...' : 'Upload file'}</Button>
          </form>
        </Card>
      </section>
      <DataState loading={books.loading} error={books.error} empty={!rows.length} onRetry={books.reload}>
        <Card title="Uploaded files">
          <Table
            columns={[
              { key: 'bookTitle', label: 'Book' },
              { key: 'original_name', label: 'File' },
              { key: 'file_type', label: 'Type' },
              { key: 'size', label: 'Size' },
            ]}
            rows={rows}
          />
        </Card>
      </DataState>
    </>
  );
}
