import { Eye, FileSpreadsheet, Printer, ScrollText } from 'lucide-react';
import { useEffect, useState } from 'react';
import * as reportsApi from '../../api/reports.js';
import Button from '../../components/Button.jsx';
import Card from '../../components/Card.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Table from '../../components/Table.jsx';

const reports = [
  { title: 'Book report', type: 'books', description: 'Catalog IDs, copy totals, available copies, copies in use, and lifecycle status.' },
  { title: 'Borrowing report', type: 'borrowing', description: 'Each request, the user and book involved, approval, due, renewal, return, and current status.' },
  { title: 'User report', type: 'users', description: 'Permanent user IDs, account identity, role, status, registration date, and last login.' },
  { title: 'Voice search report', type: 'voice-search', description: 'Recognized speech, confidence, processing time, result count, user, and outcome.' },
  { title: 'TTS report', type: 'tts', description: 'Narration requests, provider, text length, related book and user, status, and time.' },
  { title: 'Activity report', type: 'activity', description: 'Audit trail showing who performed each action, on which entity, and when.' },
];

export default function Reports() {
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState('');
  const [preview, setPreview] = useState({ ...reports[0], rows: [] });

  async function loadRows(type) {
    const response = await reportsApi.getReport(type);
    return response.data || [];
  }

  async function loadReport(report) {
    setBusy(`${report.type}-load`);
    setMessage('');
    setError('');
    try {
      const rows = await loadRows(report.type);
      setPreview({ ...report, rows });
      setMessage(rows.length ? `${report.title} loaded with ${rows.length} record(s).` : `${report.title} currently has no records.`);
      return rows;
    } catch (err) {
      setError(err.message);
      setPreview({ ...report, rows: [] });
      return [];
    } finally {
      setBusy('');
    }
  }

  useEffect(() => {
    loadReport(reports[0]);
  }, []);

  async function exportCsv(type) {
    setBusy(`${type}-excel`);
    setMessage('');
    setError('');
    try {
      const rows = await loadRows(type);
      if (!rows.length) return setMessage('This report has no rows to export.');
      const url = URL.createObjectURL(new Blob([`\ufeff${reportsApi.toCsv(rows)}`], { type: 'text/csv;charset=utf-8' }));
      const link = document.createElement('a');
      link.href = url;
      link.download = `${type}-report.csv`;
      link.click();
      URL.revokeObjectURL(url);
      setMessage('Excel-compatible CSV report downloaded.');
    } catch (error) {
      setError(error.message);
    } finally {
      setBusy('');
    }
  }

  async function previewReport(report) {
    await loadReport(report);
  }

  async function printReport(type, mode = 'print') {
    setBusy(`${type}-${mode}`);
    setMessage('');
    setError('');
    const printWindow = window.open('', '_blank', 'width=1100,height=800');
    try {
      const rows = await loadRows(type);
      if (!rows.length) {
        printWindow?.close();
        return setMessage('This report has no rows to print.');
      }
      if (!printWindow) {
        setError('The browser blocked the print window. Allow popups for this site and try again.');
        return;
      }
      printWindow.document.open();
      printWindow.document.write(reportsApi.toPrintableHtml(type, rows));
      printWindow.document.close();
      printWindow.focus();
      window.setTimeout(() => printWindow.print(), 250);
      setMessage(mode === 'pdf'
        ? 'Print window opened. Select "Save as PDF" as the destination.'
        : 'Print window opened. Select your printer and print the report.');
    } catch (error) {
      printWindow?.close();
      setError(error.message);
    } finally {
      setBusy('');
    }
  }

  return (
    <>
      <PageHeader title="Reports" description="Generate book, borrowing, user, voice search, TTS, and activity reports." />
      {message && <div className="inline-info">{message}</div>}
      {error && <div className="inline-error" role="alert">{error}</div>}
      <div className="three-grid">
        {reports.map((report) => (
          <Card key={report.type} title={report.title}>
            <p>{report.description}</p>
            <div className="button-row">
              <Button size="sm" variant="ghost" disabled={Boolean(busy)} onClick={() => previewReport(report)}><Eye size={15} /> Open report</Button>
              <Button size="sm" disabled={Boolean(busy)} onClick={() => printReport(report.type, 'pdf')}><ScrollText size={15} /> PDF</Button>
              <Button size="sm" variant="secondary" disabled={Boolean(busy)} onClick={() => exportCsv(report.type)}><FileSpreadsheet size={15} /> Excel</Button>
              <Button size="sm" variant="ghost" disabled={Boolean(busy)} onClick={() => printReport(report.type)}><Printer size={15} /> Print</Button>
            </div>
          </Card>
        ))}
      </div>
      <Card title={`${preview.title} data`} eyebrow={busy.endsWith('-load') ? 'Loading...' : `${preview.rows.length} record(s)`}>
        <p>{preview.description}</p>
        {preview.rows.length > 0 ? (
          <Table
            columns={Object.keys(preview.rows[0]).map((key) => ({
              key,
              label: key.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()),
            }))}
            rows={preview.rows.slice(0, 100)}
          />
        ) : (
          <div className="state-panel">
            <h3>No report rows shown</h3>
            <p>{busy ? 'Loading report data...' : 'Open a report above or generate activity in the system first.'}</p>
          </div>
        )}
        {preview.rows.length > 100 && <p className="table-explanation">Showing the first 100 records. Export the report to include every row.</p>}
      </Card>
    </>
  );
}
