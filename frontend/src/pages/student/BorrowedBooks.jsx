import * as borrowApi from '../../api/borrow.js';
import Card from '../../components/Card.jsx';
import DataState from '../../components/DataState.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Table from '../../components/Table.jsx';
import { useAsync } from '../../hooks/useAsync.js';

export default function BorrowedBooks() {
  const state = useAsync(borrowApi.myBorrowedBooks, []);
  return (
    <>
      <PageHeader title="My borrowed books" description="Current requests, due dates, and reading progress." />
      <DataState loading={state.loading} error={state.error} empty={!state.data?.length} onRetry={state.reload}>
        <Card><Table columns={[{ key: 'bookTitle', label: 'Book' }, { key: 'status', label: 'Status' }, { key: 'dueDate', label: 'Due date' }, { key: 'progress', label: 'Progress', render: (row) => `${row.progress}%` }]} rows={state.data || []} /></Card>
      </DataState>
    </>
  );
}
