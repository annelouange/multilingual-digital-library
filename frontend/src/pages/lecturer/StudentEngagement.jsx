import { Activity, BookOpen, GraduationCap, Users } from 'lucide-react';
import * as lecturerApi from '../../api/lecturer.js';
import Card from '../../components/Card.jsx';
import DataState from '../../components/DataState.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import StatCard from '../../components/StatCard.jsx';
import Table from '../../components/Table.jsx';
import { useAsync } from '../../hooks/useAsync.js';

export default function StudentEngagement() {
  const state = useAsync(lecturerApi.getEngagement, []);
  const courses = state.data?.courses || [];
  const recent = state.data?.recent_activity || [];
  const students = courses.reduce((sum, row) => sum + Number(row.enrolled_students || 0), 0);
  const readers = courses.reduce((sum, row) => sum + Number(row.active_readers || 0), 0);
  const borrowActions = courses.reduce((sum, row) => sum + Number(row.borrow_actions || 0), 0);
  const average = courses.length
    ? Math.round(courses.reduce((sum, row) => sum + Number(row.average_progress || 0), 0) / courses.length)
    : 0;

  return (
    <>
      <PageHeader title="Student engagement" description="Aggregated reading and borrowing activity for students in your assigned courses." />
      <div className="stat-grid compact">
        <StatCard label="Enrolled students" value={students} icon={Users} tone="blue" />
        <StatCard label="Active readers" value={readers} icon={BookOpen} tone="green" />
        <StatCard label="Borrow actions" value={borrowActions} icon={Activity} tone="amber" />
        <StatCard label="Average progress" value={`${average}%`} icon={GraduationCap} tone="rose" />
      </div>
      <DataState loading={state.loading} error={state.error} empty={!courses.length && !recent.length} onRetry={state.reload}>
        <Card title="Course engagement"><Table columns={[
          { key: 'course', label: 'Course' }, { key: 'enrolled_students', label: 'Students' },
          { key: 'active_readers', label: 'Readers' }, { key: 'borrow_actions', label: 'Borrows' },
          { key: 'average_progress', label: 'Average progress', render: (row) => `${row.average_progress}%` },
        ]} rows={courses} /></Card>
        <Card title="Recent student activity"><Table columns={[
          { key: 'student', label: 'Student' }, { key: 'action', label: 'Action' },
          { key: 'entity_type', label: 'Resource' }, { key: 'created_at', label: 'Date' },
        ]} rows={recent} /></Card>
      </DataState>
    </>
  );
}
