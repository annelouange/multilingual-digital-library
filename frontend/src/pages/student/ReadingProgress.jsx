import * as progressApi from '../../api/progress.js';
import Card from '../../components/Card.jsx';
import DataState from '../../components/DataState.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import { useAsync } from '../../hooks/useAsync.js';

export default function ReadingProgress() {
  const state = useAsync(progressApi.listProgress, []);
  return (
    <>
      <PageHeader title="Reading progress" description="Track active reading and continue from the last saved point." />
      <DataState loading={state.loading} error={state.error} empty={!state.data?.length} onRetry={state.reload}>
        <div className="stack">{state.data?.map((item) => <Card key={item.id} title={item.bookTitle} eyebrow={item.status}><div className="progress-track"><span style={{ width: `${item.progress}%` }} /></div><p>{item.progress}% completed</p></Card>)}</div>
      </DataState>
    </>
  );
}
