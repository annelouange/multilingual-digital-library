import * as usersApi from '../../api/users.js';
import Card from '../../components/Card.jsx';
import DataState from '../../components/DataState.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Table from '../../components/Table.jsx';
import { roleLabels } from '../../utils/roles.js';
import { useAsync } from '../../hooks/useAsync.js';

export default function UserManagement() {
  const state = useAsync(() => usersApi.listUsers(), []);
  return (
    <>
      <PageHeader title="User management" description="Search, review, and manage students, lecturers, and librarians." />
      <DataState loading={state.loading} error={state.error} empty={!state.data?.length} onRetry={state.reload}>
        <Card>
          <p className="table-explanation">User ID is the permanent database key assigned automatically when an account is created. It links that account to borrowing, favorites, bookmarks, reports, and activity history.</p>
          <Table columns={[{ key: 'id', label: 'User ID' }, { key: 'name', label: 'Name' }, { key: 'email', label: 'Email' }, { key: 'role', label: 'Role', render: (row) => roleLabels[row.role] }, { key: 'status', label: 'Status' }, { key: 'department', label: 'Department' }]} rows={state.data || []} />
        </Card>
      </DataState>
    </>
  );
}
