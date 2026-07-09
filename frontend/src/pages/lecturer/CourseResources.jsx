import { Download, Upload } from 'lucide-react';
import { useState } from 'react';
import * as lecturerApi from '../../api/lecturer.js';
import Button from '../../components/Button.jsx';
import Card from '../../components/Card.jsx';
import DataState from '../../components/DataState.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Table from '../../components/Table.jsx';
import { useAsync } from '../../hooks/useAsync.js';

export default function CourseResources() {
  const resources = useAsync(lecturerApi.getResources, []);
  const [form, setForm] = useState({ title: '', course_id: '', reading_list_id: '' });
  const [file, setFile] = useState(null);
  const [fileKey, setFileKey] = useState(0);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  async function upload(event) {
    event.preventDefault();
    setMessage('');
    setError('');
    try {
      const response = await lecturerApi.uploadLectureNote(form, file);
      setMessage(`${response.message}. It is pending librarian approval.`);
      setForm({ title: '', course_id: '', reading_list_id: '' });
      setFile(null);
      setFileKey((value) => value + 1);
      resources.reload();
    } catch (err) {
      setError(err.message);
    }
  }

  const data = resources.data || { courses: [], books: [], notes: [] };
  return (
    <>
      <PageHeader title="Course resources" description="View assigned courses and books, then upload lecture notes for librarian approval." />
      {message && <div className="inline-info">{message}</div>}
      {error && <div className="inline-error">{error}</div>}
      <Card title="Upload lecture note">
        <form className="inline-form" onSubmit={upload}>
          <input value={form.title} onChange={(event) => setForm({ ...form, title: event.target.value })} placeholder="Note title" required />
          <select value={form.course_id} onChange={(event) => setForm({ ...form, course_id: Number(event.target.value) })} required>
            <option value="">Select course</option>{data.courses.map((course) => <option key={course.id} value={course.id}>{course.name}</option>)}
          </select>
          <input key={fileKey} type="file" accept=".pdf,.docx,.txt" onChange={(event) => setFile(event.target.files?.[0] || null)} required />
          <Button type="submit" disabled={!file}><Upload size={15} /> Upload</Button>
        </form>
      </Card>
      <DataState loading={resources.loading} error={resources.error} empty={!data.courses.length && !data.books.length && !data.notes.length} onRetry={resources.reload}>
        <div className="three-grid">
          <Card title="Assigned courses"><Table columns={[{ key: 'name', label: 'Course' }, { key: 'code', label: 'Code' }, { key: 'department', label: 'Department' }]} rows={data.courses} /></Card>
          <Card title="Course books"><Table columns={[{ key: 'title', label: 'Book' }, { key: 'course', label: 'Course' }, { key: 'author', label: 'Author' }]} rows={data.books} /></Card>
          <Card title="Lecture notes"><Table columns={[
            { key: 'title', label: 'Note' }, { key: 'course', label: 'Course' },
            { key: 'status', label: 'Status', render: (row) => <span className={`status-pill status-${row.status}`}>{row.status}</span> },
            { key: 'file', label: '', render: (row) => <a className="button button-ghost button-sm" href={lecturerApi.lectureNoteDownloadUrl(row.id)}><Download size={14} /> File</a> },
          ]} rows={data.notes} /></Card>
        </div>
      </DataState>
    </>
  );
}
