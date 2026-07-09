import { Plus, X } from 'lucide-react';
import { useState } from 'react';
import * as booksApi from '../../api/books.js';
import * as recommendationsApi from '../../api/recommendations.js';
import * as usersApi from '../../api/users.js';
import Button from '../../components/Button.jsx';
import Card from '../../components/Card.jsx';
import DataState from '../../components/DataState.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Table from '../../components/Table.jsx';
import { useAsync } from '../../hooks/useAsync.js';

export default function RecommendationManagement() {
  const records = useAsync(recommendationsApi.listManagedRecommendations, []);
  const books = useAsync(booksApi.listBooks, []);
  const users = useAsync(usersApi.listUsers, []);
  const [form, setForm] = useState({ book_id: '', user_id: '', source_type: 'lecturer', score: 50 });
  const [message, setMessage] = useState('');

  async function create(event) {
    event.preventDefault();
    const response = await recommendationsApi.createRecommendation(form);
    setMessage(response.message);
    records.reload();
  }

  async function dismiss(id) {
    await recommendationsApi.updateRecommendation(id, 'dismissed');
    setMessage('Recommendation dismissed.');
    records.reload();
  }

  return (
    <>
      <PageHeader title="Recommendation management" description="Create targeted recommendations and manage their lifecycle." />
      {message && <div className="inline-info">{message}</div>}
      <Card title="Create recommendation">
        <form className="inline-form" onSubmit={create}>
          <select value={form.book_id} onChange={(event) => setForm({ ...form, book_id: Number(event.target.value) })} required>
            <option value="">Select book</option>{books.data?.map((book) => <option key={book.id} value={book.id}>{book.title}</option>)}
          </select>
          <select value={form.user_id} onChange={(event) => setForm({ ...form, user_id: event.target.value ? Number(event.target.value) : '' })}>
            <option value="">All users</option>{users.data?.map((user) => <option key={user.id} value={user.id}>{user.name}</option>)}
          </select>
          <select value={form.source_type} onChange={(event) => setForm({ ...form, source_type: event.target.value })}>
            <option value="lecturer">Librarian</option><option value="course">Course</option><option value="faculty">Faculty</option><option value="popular">Popular</option>
          </select>
          <Button type="submit"><Plus size={15} /> Create</Button>
        </form>
      </Card>
      <DataState loading={records.loading} error={records.error} empty={!records.data?.length} onRetry={records.reload}>
        <Card><Table columns={[
          { key: 'book_title', label: 'Book' }, { key: 'user_name', label: 'User', render: (row) => row.user_name || 'All users' },
          { key: 'source_type', label: 'Source' }, { key: 'score', label: 'Score' },
          { key: 'status', label: 'Status', render: (row) => <span className={`status-pill status-${row.status}`}>{row.status}</span> },
          { key: 'actions', label: '', render: (row) => row.status === 'active' && <Button size="sm" variant="ghost" onClick={() => dismiss(row.id)}><X size={14} /> Dismiss</Button> },
        ]} rows={records.data || []} /></Card>
      </DataState>
    </>
  );
}
