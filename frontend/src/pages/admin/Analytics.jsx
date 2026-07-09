import * as analyticsApi from '../../api/analytics.js';
import Card from '../../components/Card.jsx';
import DataState from '../../components/DataState.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import StatCard from '../../components/StatCard.jsx';
import { useAsync } from '../../hooks/useAsync.js';

function MiniTable({ rows = [], columns = [], empty = 'No data yet.' }) {
  if (!rows.length) return <p className="muted-copy">{empty}</p>;
  return (
    <div className="table-wrap compact-table-wrap">
      <table>
        <thead><tr>{columns.map((column) => <th key={column.key}>{column.label}</th>)}</tr></thead>
        <tbody>
          {rows.map((row, index) => (
            <tr key={row.id || `${columns[0]?.key || 'row'}-${index}`}>
              {columns.map((column) => <td key={column.key}>{row[column.key] ?? '-'}</td>)}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

export default function Analytics() {
  const state = useAsync(analyticsApi.getAnalytics, []);
  const usageState = useAsync(analyticsApi.getUsageAnalytics, []);
  const layerState = useAsync(analyticsApi.getStartupLayers, []);
  const data = state.data || {};
  const usage = usageState.data || {};
  const layerReport = layerState.data || {};
  const layers = layerReport.layers || [];

  return (
    <>
      <PageHeader title="Analytics" description="Operational metrics for discovery, AI usage, publisher rights, and SaaS readiness." />
      <DataState loading={state.loading} error={state.error} empty={!state.data} onRetry={state.reload}>
        <Card title="System metrics">
          <div className="stat-grid compact">
            {Object.entries(data).map(([key, value]) => <StatCard key={key} label={key.replace(/([A-Z])/g, ' $1')} value={value} />)}
          </div>
        </Card>
      </DataState>

      <DataState loading={layerState.loading} error={layerState.error} empty={false} onRetry={layerState.reload}>
        <Card title="Startup layer readiness" eyebrow="Commercial architecture">
          <div className="layer-readiness-grid">
            {layers.map((layer) => (
              <article className="layer-readiness-card" key={layer.key}>
                <strong>{String(layer.key).replaceAll('_', ' ')}</strong>
                <span>{layer.status}</span>
              </article>
            ))}
          </div>
        </Card>
      </DataState>

      <DataState loading={usageState.loading} error={usageState.error} empty={false} onRetry={usageState.reload}>
        <div className="analytics-grid">
          <Card title="Failed searches" eyebrow="Discovery gaps">
            <MiniTable rows={usage.failedSearches || []} columns={[{ key: 'query_text', label: 'Query' }, { key: 'attempts', label: 'Attempts' }, { key: 'last_seen', label: 'Last seen' }]} />
          </Card>
          <Card title="AI usage" eyebrow="Cost control">
            <MiniTable rows={usage.aiUsageByService || []} columns={[{ key: 'service_name', label: 'Service' }, { key: 'provider', label: 'Provider' }, { key: 'events', label: 'Events' }, { key: 'input_units', label: 'Input' }]} />
          </Card>
          <Card title="TTS usage" eyebrow="Audio access">
            <MiniTable rows={usage.ttsUsageByProvider || []} columns={[{ key: 'provider', label: 'Provider' }, { key: 'events', label: 'Events' }, { key: 'text_units', label: 'Text units' }, { key: 'generated_files', label: 'Files' }]} />
          </Card>
          <Card title="Translation usage" eyebrow="Language access">
            <MiniTable rows={usage.translationUsage || []} columns={[{ key: 'provider', label: 'Provider' }, { key: 'source_language', label: 'From' }, { key: 'target_language', label: 'To' }, { key: 'events', label: 'Events' }]} />
          </Card>
          <Card title="AI jobs" eyebrow="Background work">
            <MiniTable rows={usage.jobQueue || []} columns={[{ key: 'job_type', label: 'Type' }, { key: 'status', label: 'Status' }, { key: 'jobs', label: 'Jobs' }, { key: 'last_update', label: 'Updated' }]} />
          </Card>
          <Card title="Fallbacks" eyebrow="Reliability">
            <MiniTable rows={usage.modelFallbacks || []} columns={[{ key: 'task', label: 'Task' }, { key: 'primary_provider', label: 'Primary' }, { key: 'fallback_provider', label: 'Fallback' }, { key: 'events', label: 'Events' }]} />
          </Card>
        </div>
      </DataState>
    </>
  );
}
