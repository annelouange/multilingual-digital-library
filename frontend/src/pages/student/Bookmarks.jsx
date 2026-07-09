import * as bookmarksApi from '../../api/bookmarks.js';
import Button from '../../components/Button.jsx';
import Card from '../../components/Card.jsx';
import DataState from '../../components/DataState.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import Table from '../../components/Table.jsx';
import { useAsync } from '../../hooks/useAsync.js';

export default function Bookmarks() {
  const state = useAsync(bookmarksApi.listBookmarks, []);

  async function remove(id) {
    await bookmarksApi.removeBookmark(id);
    state.reload();
  }

  return (
    <>
      <PageHeader title="Bookmarks" description="Saved sections and notes from active reading." />
      <DataState loading={state.loading} error={state.error} empty={!state.data?.length} onRetry={state.reload}>
        <Card title="Saved notes">
          <Table
            columns={[
              { key: 'title', label: 'Book' },
              { key: 'section', label: 'Section' },
              { key: 'page_number', label: 'Page' },
              { key: 'note', label: 'Note' },
              { key: 'actions', label: '', render: (row) => <Button size="sm" variant="ghost" onClick={() => remove(row.id)}>Remove</Button> },
            ]}
            rows={state.data || []}
          />
        </Card>
      </DataState>
    </>
  );
}
