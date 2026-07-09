import * as borrowApi from '../../api/borrow.js';
import Card from '../../components/Card.jsx';
import DataState from '../../components/DataState.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Table from '../../components/Table.jsx';
import { useAsync } from '../../hooks/useAsync.js';

export default function BorrowingManagement() {
  const state = useAsync(borrowApi.listBorrowings, []);
  return (
    <>
      <PageHeader title="Borrowing management" description="Review pending, approved, rejected, returned, and overdue borrowing records." />
      <DataState loading={state.loading} error={state.error} empty={!state.data?.length} onRetry={state.reload}>
        <Card><Table columns={[{ key: 'bookTitle', label: 'Book' }, { key: 'status', label: 'Status' }, { key: 'dueDate', label: 'Due date' }, { key: 'progress', label: 'Progress', render: (row) => `${row.progress}%` }]} rows={state.data || []} /></Card>
      </DataState>
    </>
  );
}
