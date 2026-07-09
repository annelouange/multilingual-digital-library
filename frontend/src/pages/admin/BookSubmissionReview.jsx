import { Check, Download, X } from 'lucide-react';
import { useState } from 'react';
import * as submissionsApi from '../../api/bookSubmissions.js';
import * as lecturerApi from '../../api/lecturer.js';
import Button from '../../components/Button.jsx';
import Card from '../../components/Card.jsx';
import DataState from '../../components/DataState.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Table from '../../components/Table.jsx';
import { useAsync } from '../../hooks/useAsync.js';

export default function BookSubmissionReview() {
  const [filter, setFilter] = useState('pending');
  const [busyId, setBusyId] = useState(null);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const submissions = useAsync(() => submissionsApi.listSubmissions({ status: filter }), [filter]);
  const notes = useAsync(lecturerApi.listLectureNotes, []);

  async function approve(row) {
    if (!window.confirm(`Approve "${row.title}" and publish it in the library?`)) return;
    setBusyId(row.id);
    setMessage('');
    setError('');
    try {
      const response = await submissionsApi.approveSubmission(row.id);
      setMessage(response.message);
      submissions.reload();
    } catch (err) {
      setError(err.message);
    } finally {
      setBusyId(null);
    }
  }

  async function reject(row) {
    const note = window.prompt(`Why should "${row.title}" be rejected?`, '');
    if (note === null) return;
    setBusyId(row.id);
    setMessage('');
    setError('');
    try {
      const response = await submissionsApi.rejectSubmission(row.id, note);
      setMessage(response.message);
      submissions.reload();
    } catch (err) {
      setError(err.message);
    } finally {
      setBusyId(null);
    }
  }

  async function moderateNote(id, status) {
    try {
      const response = await lecturerApi.moderateLectureNote(id, status);
      setMessage(response.message);
      notes.reload();
    } catch (err) {
      setError(err.message);
    }
  }

  const rows = (submissions.data || []).map((item) => ({
    ...item,
    student: `${item.submitted_by_name} (${item.submitted_by_email})`,
    submittedAt: item.created_at ? new Date(item.created_at).toLocaleString() : '',
  }));

  return (
    <>
      <PageHeader
        title="Verify book submissions"
        description="Review student uploads. Approval publishes the book and file in the live library catalog."
        actions={(
          <label className="compact-field">
            Status
            <select value={filter} onChange={(event) => setFilter(event.target.value)}>
              <option value="pending">Pending</option>
              <option value="approved">Approved</option>
              <option value="rejected">Rejected</option>
              <option value="">All</option>
            </select>
          </label>
        )}
      />
      {message && <div className="inline-info">{message}</div>}
      {error && <div className="inline-error" role="alert">{error}</div>}
      <DataState loading={submissions.loading} error={submissions.error} empty={!rows.length} onRetry={submissions.reload}>
        <Card>
          <Table
            columns={[
              { key: 'title', label: 'Book' },
              { key: 'student', label: 'Student' },
              { key: 'original_name', label: 'File' },
              { key: 'submittedAt', label: 'Submitted' },
              { key: 'status', label: 'Status', render: (row) => <span className={`status-pill status-${row.status}`}>{row.status}</span> },
              {
                key: 'actions',
                label: 'Actions',
                render: (row) => (
                  <div className="button-row table-actions">
                    <a className="button button-ghost button-sm" href={submissionsApi.submissionDownloadUrl(row.id)}><Download size={15} /> Review file</a>
                    {row.status === 'pending' && (
                      <>
                        <Button size="sm" onClick={() => approve(row)} disabled={busyId === row.id}><Check size={15} /> Approve</Button>
                        <Button size="sm" variant="secondary" onClick={() => reject(row)} disabled={busyId === row.id}><X size={15} /> Reject</Button>
                      </>
                    )}
                  </div>
                ),
              },
            ]}
            rows={rows}
          />
        </Card>
      </DataState>
      <DataState loading={notes.loading} error={notes.error} empty={!notes.data?.length} onRetry={notes.reload}>
        <Card title="Lecture note verification">
          <Table
            columns={[
              { key: 'title', label: 'Note' },
              { key: 'lecturer_name', label: 'Lecturer' },
              { key: 'course', label: 'Course' },
              { key: 'status', label: 'Status', render: (row) => <span className={`status-pill status-${row.status}`}>{row.status}</span> },
              {
                key: 'actions',
                label: 'Actions',
                render: (row) => (
                  <div className="button-row table-actions">
                    <a className="button button-ghost button-sm" href={lecturerApi.lectureNoteDownloadUrl(row.id)}><Download size={15} /> Review file</a>
                    {row.status === 'pending' && (
                      <>
                        <Button size="sm" onClick={() => moderateNote(row.id, 'approved')}><Check size={15} /> Approve</Button>
                        <Button size="sm" variant="secondary" onClick={() => moderateNote(row.id, 'rejected')}><X size={15} /> Reject</Button>
                      </>
                    )}
                  </div>
                ),
              },
            ]}
            rows={notes.data || []}
          />
        </Card>
      </DataState>
    </>
  );
}
