import { Archive, Pencil, Plus, X } from 'lucide-react';
import { useState } from 'react';
import * as academicApi from '../../api/academic.js';
import Button from '../../components/Button.jsx';
import Card from '../../components/Card.jsx';
import DataState from '../../components/DataState.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Table from '../../components/Table.jsx';
import { useAsync } from '../../hooks/useAsync.js';

const blank = () => ({ id: null, name: '', code: '', description: '', parent_id: '' });

export default function AcademicManagement() {
  const faculties = useAsync(() => academicApi.listAcademic('faculties'), []);
  const departments = useAsync(() => academicApi.listAcademic('departments'), []);
  const courses = useAsync(() => academicApi.listAcademic('courses'), []);
  const [forms, setForms] = useState({ faculties: blank(), departments: blank(), courses: blank() });
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  const reloadAll = () => {
    faculties.reload();
    departments.reload();
    courses.reload();
  };

  async function save(type, event) {
    event.preventDefault();
    setMessage('');
    setError('');
    const form = forms[type];
    const payload = { name: form.name, code: form.code, description: form.description, status: 'active' };
    if (type === 'departments') payload.faculty_id = Number(form.parent_id);
    if (type === 'courses') payload.department_id = Number(form.parent_id);
    try {
      const response = form.id
        ? await academicApi.updateAcademic(type, form.id, payload)
        : await academicApi.createAcademic(type, payload);
      setMessage(response.message);
      setForms({ ...forms, [type]: blank() });
      reloadAll();
    } catch (err) {
      setError(err.message);
    }
  }

  function edit(type, row) {
    setMessage('');
    setError('');
    setForms({
      ...forms,
      [type]: {
        id: row.id,
        name: row.name || '',
        code: row.code || '',
        description: row.description || '',
        parent_id: type === 'departments' ? row.faculty_id : type === 'courses' ? row.department_id : '',
      },
    });
  }

  async function archive(type, row) {
    if (!window.confirm(`Archive "${row.name}"? Existing linked records will be preserved.`)) return;
    try {
      await academicApi.archiveAcademic(type, row.id);
      setMessage(`${row.name} archived.`);
      reloadAll();
    } catch (err) {
      setError(err.message);
    }
  }

  const sections = [
    { type: 'faculties', title: 'Faculties', state: faculties },
    { type: 'departments', title: 'Departments', state: departments, parents: faculties.data, parentLabel: 'Faculty', parentKey: 'faculty_name' },
    { type: 'courses', title: 'Courses', state: courses, parents: departments.data, parentLabel: 'Department', parentKey: 'department_name' },
  ];

  return (
    <>
      <PageHeader title="Faculties and departments" description="Maintain the official Rwanda library structure used for catalog filtering and field-specific recommendations." />
      {message && <div className="inline-info">{message}</div>}
      {error && <div className="inline-error">{error}</div>}
      <div className="academic-management-grid">
        {sections.map(({ type, title, state, parents, parentLabel, parentKey }) => {
          const form = forms[type];
          return (
            <Card key={type} title={title} eyebrow={form.id ? `Editing record #${form.id}` : `Add ${title.slice(0, -1).toLowerCase()}`}>
              <form className="form-stack academic-form" onSubmit={(event) => save(type, event)}>
                {parents && (
                  <label>{parentLabel}
                    <select value={form.parent_id} onChange={(event) => setForms({ ...forms, [type]: { ...form, parent_id: event.target.value } })} required>
                      <option value="">Select {parentLabel.toLowerCase()}</option>
                      {(parents || []).map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
                    </select>
                  </label>
                )}
                <label>Name<input value={form.name} onChange={(event) => setForms({ ...forms, [type]: { ...form, name: event.target.value } })} required /></label>
                <label>Code<input value={form.code} onChange={(event) => setForms({ ...forms, [type]: { ...form, code: event.target.value } })} /></label>
                <label>Description<textarea value={form.description} onChange={(event) => setForms({ ...forms, [type]: { ...form, description: event.target.value } })} /></label>
                <div className="button-row">
                  <Button type="submit">{form.id ? <Pencil size={16} /> : <Plus size={16} />} {form.id ? 'Save changes' : `Add ${title.slice(0, -1)}`}</Button>
                  {form.id && <Button type="button" variant="ghost" onClick={() => setForms({ ...forms, [type]: blank() })}><X size={16} /> Cancel</Button>}
                </div>
              </form>
              <DataState loading={state.loading} error={state.error} empty={!state.data?.length} onRetry={state.reload}>
                <Table
                  columns={[
                    { key: 'name', label: 'Name' },
                    ...(parentKey ? [{ key: parentKey, label: parentLabel }] : []),
                    { key: 'code', label: 'Code' },
                    {
                      key: 'action',
                      label: 'Actions',
                      render: (row) => (
                        <div className="button-row table-actions">
                          <Button size="icon" variant="secondary" aria-label={`Edit ${row.name}`} onClick={() => edit(type, row)}><Pencil size={15} /></Button>
                          <Button size="icon" variant="ghost" aria-label={`Archive ${row.name}`} onClick={() => archive(type, row)}><Archive size={15} /></Button>
                        </div>
                      ),
                    },
                  ]}
                  rows={state.data || []}
                />
              </DataState>
            </Card>
          );
        })}
      </div>
    </>
  );
}
