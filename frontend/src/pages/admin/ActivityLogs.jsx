import * as logsApi from '../../api/logs.js';
import Card from '../../components/Card.jsx';
import DataState from '../../components/DataState.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Table from '../../components/Table.jsx';
import { useAsync } from '../../hooks/useAsync.js';

export default function ActivityLogs() {
  const state = useAsync(logsApi.listActivityLogs, []);
  return (
    <>
      <PageHeader title="Activity logs" description="Login, search, voice search, TTS, borrowing, and authorization events." />
      <DataState loading={state.loading} error={state.error} empty={!state.data?.length} onRetry={state.reload}>
        <Card><Table columns={[{ key: 'user_id', label: 'User ID' }, { key: 'role_code', label: 'Role' }, { key: 'action', label: 'Action' }, { key: 'status', label: 'Status' }, { key: 'created_at', label: 'Date' }]} rows={state.data || []} /></Card>
      </DataState>
    </>
  );
}
