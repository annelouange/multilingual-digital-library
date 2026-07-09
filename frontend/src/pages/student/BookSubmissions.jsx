import { Headphones, Send } from 'lucide-react';
import { useState } from 'react';
import { Link } from 'react-router-dom';
import * as submissionsApi from '../../api/bookSubmissions.js';
import Button from '../../components/Button.jsx';
import Card from '../../components/Card.jsx';
import DataState from '../../components/DataState.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Table from '../../components/Table.jsx';
import { useAsync } from '../../hooks/useAsync.js';

const initialForm = {
  title: '',
  isbn: '',
  publisher: '',
  publication_year: '',
  description: '',
  keywords: '',
  total_copies: 1,
};

export default function BookSubmissions() {
  const submissions = useAsync(submissionsApi.mySubmissions, []);
  const [form, setForm] = useState(initialForm);
  const [file, setFile] = useState(null);
  const [fileKey, setFileKey] = useState(0);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  async function handleSubmit(event) {
    event.preventDefault();
    if (!file) return;
    setBusy(true);
    setMessage('');
    setError('');
    try {
      const response = await submissionsApi.submitBook(form, file);
      setMessage(response.message);
      setForm(initialForm);
      setFile(null);
      setFileKey((value) => value + 1);
      submissions.reload();
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  }

  const rows = (submissions.data || []).map((item) => ({
    ...item,
    submittedAt: item.created_at ? new Date(item.created_at).toLocaleString() : '',
    decision: item.review_note || (item.status === 'pending' ? 'Waiting for librarian verification' : ''),
  }));

  return (
    <>
      <PageHeader
        title="Submit book"
        description="Submit a book for librarian verification. It becomes searchable and readable only after the librarian approves it."
      />
      {message && <div className="inline-info">{message}</div>}
      {error && <div className="inline-error" role="alert">{error}</div>}

      <Card title="Book submission" eyebrow="Librarian approval required">
        <form className="form-grid" onSubmit={handleSubmit}>
          <label>Title<input value={form.title} onChange={(event) => setForm({ ...form, title: event.target.value })} required /></label>
          <label>ISBN<input value={form.isbn} onChange={(event) => setForm({ ...form, isbn: event.target.value })} /></label>
          <label>Publisher<input value={form.publisher} onChange={(event) => setForm({ ...form, publisher: event.target.value })} /></label>
          <label>Publication year<input type="number" min="1000" max="2100" value={form.publication_year} onChange={(event) => setForm({ ...form, publication_year: event.target.value })} /></label>
          <label>Number of copies<input type="number" min="1" value={form.total_copies} onChange={(event) => setForm({ ...form, total_copies: Number(event.target.value) })} /></label>
          <label>Keywords<input value={form.keywords} onChange={(event) => setForm({ ...form, keywords: event.target.value })} /></label>
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
          {file && <p className="file-hint span-2">{/\.docx$/i.test(file.name) ? `${file.name} will be converted to PDF before review.` : file.name}</p>}
          <Button type="submit" className="span-2" disabled={busy || !file}>
            <Send size={16} /> {busy ? 'Sending for verification...' : 'Send to librarian'}
          </Button>
        </form>
      </Card>

      <DataState loading={submissions.loading} error={submissions.error} empty={!rows.length} onRetry={submissions.reload}>
        <Card title="My submissions">
          <Table
            columns={[
              { key: 'title', label: 'Title' },
              { key: 'original_name', label: 'File' },
              { key: 'status', label: 'Status', render: (row) => <span className={`status-pill status-${row.status}`}>{row.status}</span> },
              { key: 'submittedAt', label: 'Submitted' },
              { key: 'decision', label: 'Librarian note' },
              {
                key: 'catalog',
                label: 'Catalog',
                render: (row) => row.approved_book_id ? (
                  <div className="button-row table-actions">
                    <Link className="button button-secondary button-sm" to={`/books/${row.approved_book_id}`}>View</Link>
                    <Link className="button button-ghost button-sm" to={`/reader/${row.approved_book_id}`}><Headphones size={15} /> Read & listen</Link>
                  </div>
                ) : <span className="muted-text">Pending approval</span>,
              },
            ]}
            rows={rows}
          />
        </Card>
      </DataState>
    </>
  );
}
