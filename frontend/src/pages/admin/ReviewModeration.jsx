import { Check, EyeOff, Trash2 } from 'lucide-react';
import { useState } from 'react';
import * as reviewsApi from '../../api/reviews.js';
import Button from '../../components/Button.jsx';
import Card from '../../components/Card.jsx';
import DataState from '../../components/DataState.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Table from '../../components/Table.jsx';
import { useAsync } from '../../hooks/useAsync.js';

export default function ReviewModeration() {
  const [status, setStatus] = useState('pending');
  const [message, setMessage] = useState('');
  const state = useAsync(() => reviewsApi.listAllReviews({ status }), [status]);

  async function moderate(id, nextStatus) {
    const note = nextStatus === 'approved' ? '' : window.prompt('Optional moderation note', '') ?? null;
    if (note === null) return;
    const response = await reviewsApi.moderateReview(id, nextStatus, note);
    setMessage(response.message);
    state.reload();
  }

  return (
    <>
      <PageHeader title="Review moderation" description="Approve, hide, or delete submitted book reviews." actions={(
        <select value={status} onChange={(event) => setStatus(event.target.value)}>
          <option value="pending">Pending</option><option value="approved">Approved</option>
          <option value="hidden">Hidden</option><option value="deleted">Deleted</option><option value="">All</option>
        </select>
      )} />
      {message && <div className="inline-info">{message}</div>}
      <DataState loading={state.loading} error={state.error} empty={!state.data?.length} onRetry={state.reload}>
        <Card><Table columns={[
          { key: 'book_title', label: 'Book' },
          { key: 'author', label: 'Reviewer' },
          { key: 'text', label: 'Review' },
          { key: 'status', label: 'Status', render: (row) => <span className={`status-pill status-${row.status}`}>{row.status}</span> },
          { key: 'actions', label: 'Actions', render: (row) => <div className="button-row table-actions">
            <Button size="sm" onClick={() => moderate(row.id, 'approved')}><Check size={14} /> Approve</Button>
            <Button size="sm" variant="secondary" onClick={() => moderate(row.id, 'hidden')}><EyeOff size={14} /> Hide</Button>
            <Button size="sm" variant="ghost" onClick={() => moderate(row.id, 'deleted')}><Trash2 size={14} /> Delete</Button>
          </div> },
        ]} rows={state.data || []} /></Card>
      </DataState>
    </>
  );
}
