import { useState } from 'react';
import * as settingsApi from '../../api/settings.js';
import Button from '../../components/Button.jsx';
import Card from '../../components/Card.jsx';
import DataState from '../../components/DataState.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import { useAsync } from '../../hooks/useAsync.js';

export default function Settings() {
  const state = useAsync(settingsApi.getSettings, []);
  const [form, setForm] = useState(null);
  const [saved, setSaved] = useState(false);
  const settings = form || state.data || {};

  async function save(event) {
    event.preventDefault();
    await settingsApi.updateSettings(settings);
    setSaved(true);
    state.reload();
  }

  return (
    <>
      <PageHeader title="System settings" description="Configure borrowing, uploads, notifications, STT, and TTS settings." />
      <DataState loading={state.loading} error={state.error} empty={!state.data} onRetry={state.reload}>
        <Card title="Library settings">
          {saved && <div className="inline-info">Settings save request sent.</div>}
          <form className="form-grid" onSubmit={save}>
            <label>System name<input value={settings.systemName || ''} onChange={(event) => setForm({ ...settings, systemName: event.target.value })} /></label>
            <label>Borrow duration<input type="number" value={settings.borrowDuration || 0} onChange={(event) => setForm({ ...settings, borrowDuration: Number(event.target.value) })} /></label>
            <label>Max borrowed books<input type="number" value={settings.maxBorrowedBooks || 0} onChange={(event) => setForm({ ...settings, maxBorrowedBooks: Number(event.target.value) })} /></label>
            <label>Upload limit MB<input type="number" value={settings.uploadLimitMb || 0} onChange={(event) => setForm({ ...settings, uploadLimitMb: Number(event.target.value) })} /></label>
            <label className="span-2">Allowed file types<input value={settings.allowedFileTypes || ''} onChange={(event) => setForm({ ...settings, allowedFileTypes: event.target.value })} /></label>
            <Button type="submit">Save settings</Button>
          </form>
        </Card>
      </DataState>
    </>
  );
}
