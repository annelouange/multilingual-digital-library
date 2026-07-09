import { AlertTriangle, BookOpen, ClipboardList, Mic, Users } from 'lucide-react';
import * as analyticsApi from '../../api/analytics.js';
import * as logsApi from '../../api/logs.js';
import Card from '../../components/Card.jsx';
import DataState from '../../components/DataState.jsx';
import DashboardBrand from '../../components/DashboardBrand.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import StatCard from '../../components/StatCard.jsx';
import Table from '../../components/Table.jsx';
import { useAsync } from '../../hooks/useAsync.js';

export default function AdminDashboard() {
  const analytics = useAsync(analyticsApi.getAnalytics, []);
  const logs = useAsync(logsApi.listActivityLogs, []);
  const data = analytics.data || {};

  return (
    <>
      <PageHeader eyebrow="Librarian/Admin workspace" title="Operations dashboard" description="Monitor users, books, borrowing, voice usage, and system activity." />
      <DashboardBrand title="Welcome to MULTILINGUAL DIGITAL LIBRARY" description="Manage the institutional catalog, academic structure, verification, and library operations." />
      <div className="stat-grid">
        <StatCard label="Total users" value={data.totalUsers || 0} icon={Users} tone="blue" />
        <StatCard label="Total books" value={data.totalBooks || 0} icon={BookOpen} tone="green" />
        <StatCard label="Borrowed books" value={data.borrowedBooks || 0} icon={ClipboardList} tone="amber" />
        <StatCard label="Voice searches" value={data.voiceSearches || 0} icon={Mic} tone="rose" />
        <StatCard label="Overdue books" value={data.overdueBooks || 0} icon={AlertTriangle} tone="amber" />
      </div>
      <DataState loading={logs.loading} error={logs.error} empty={!logs.data?.length} onRetry={logs.reload}>
        <Card title="Recent activity">
          <Table columns={[{ key: 'actor', label: 'Actor' }, { key: 'action', label: 'Action' }, { key: 'status', label: 'Status' }, { key: 'created_at', label: 'Date' }]} rows={logs.data || []} />
        </Card>
      </DataState>
    </>
  );
}
