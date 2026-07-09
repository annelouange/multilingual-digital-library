import * as booksApi from '../../api/books.js';
import { Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Link } from 'react-router-dom';
import Button from '../../components/Button.jsx';
import Card from '../../components/Card.jsx';
import DataState from '../../components/DataState.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Table from '../../components/Table.jsx';
import { useAsync } from '../../hooks/useAsync.js';

export default function BookManagement() {
  const state = useAsync(() => booksApi.listBooks(), []);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  async function deleteBook(book) {
    if (!window.confirm(`Delete "${book.title}" from the public catalogue? Existing activity history will be preserved.`)) return;
    setMessage('');
    setError('');
    try {
      await booksApi.deleteBook(book.id);
      setMessage(`"${book.title}" was removed from the catalogue.`);
      state.reload();
    } catch (err) {
      setError(err.message);
    }
  }

  return (
    <>
      <PageHeader
        title="Book management"
        description="Maintain metadata, copies, covers, uploaded files, and student submissions."
        actions={(
          <div className="button-row">
            <Link className="button button-secondary button-md" to="/admin/book-submissions">Verify student submissions</Link>
            <Link className="button button-primary button-md" to="/admin/uploads">Add or upload book</Link>
          </div>
        )}
      />
      {message && <div className="inline-info">{message}</div>}
      {error && <div className="inline-error" role="alert">{error}</div>}
      <DataState loading={state.loading} error={state.error} empty={!state.data?.length} onRetry={state.reload}>
        <Card><Table columns={[{ key: 'title', label: 'Title' }, { key: 'author', label: 'Author' }, { key: 'category', label: 'Category' }, { key: 'course', label: 'Course' }, { key: 'availableCopies', label: 'Available' }, { key: 'totalCopies', label: 'Total' }, { key: 'actions', label: 'Actions', render: (book) => <div className="button-row"><Link className="button button-secondary button-sm" to={`/books/${book.id}`}>View</Link><Button size="sm" variant="danger" onClick={() => deleteBook(book)}><Trash2 size={15} /> Delete</Button></div> }]} rows={state.data || []} /></Card>
      </DataState>
    </>
  );
}
