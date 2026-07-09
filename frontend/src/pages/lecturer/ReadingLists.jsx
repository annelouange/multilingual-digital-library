import { useState } from 'react';
import * as readingListsApi from '../../api/readingLists.js';
import Button from '../../components/Button.jsx';
import Card from '../../components/Card.jsx';
import DataState from '../../components/DataState.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Table from '../../components/Table.jsx';
import { useAsync } from '../../hooks/useAsync.js';

export default function ReadingLists() {
  const [title, setTitle] = useState('');
  const state = useAsync(readingListsApi.listReadingLists, []);

  async function createList(event) {
    event.preventDefault();
    await readingListsApi.createReadingList({ title });
    setTitle('');
    state.reload();
  }

  return (
    <>
      <PageHeader title="Reading lists" description="Create and publish course reading lists." />
      <Card title="Create reading list"><form className="inline-form" onSubmit={createList}><input value={title} onChange={(e) => setTitle(e.target.value)} placeholder="List title" required /><Button type="submit">Create</Button></form></Card>
      <DataState loading={state.loading} error={state.error} empty={!state.data?.length} onRetry={state.reload}>
        <Card><Table columns={[{ key: 'title', label: 'Title' }, { key: 'course', label: 'Course' }, { key: 'books', label: 'Books' }, { key: 'status', label: 'Status' }]} rows={state.data || []} /></Card>
      </DataState>
    </>
  );
}
