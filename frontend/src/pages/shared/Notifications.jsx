import Button from '../../components/Button.jsx';
import * as notificationsApi from '../../api/notifications.js';
import Card from '../../components/Card.jsx';
import DataState from '../../components/DataState.jsx';
import PageHeader from '../../components/PageHeader.jsx';
import { useAsync } from '../../hooks/useAsync.js';

export default function Notifications() {
  const state = useAsync(notificationsApi.listNotifications, []);

  async function markRead(id) {
    await notificationsApi.markNotificationRead(id);
    state.reload();
  }

  async function remove(id) {
    await notificationsApi.deleteNotification(id);
    state.reload();
  }

  return (
    <>
      <PageHeader title="Notifications" description="Borrowing, due date, recommendation, and system messages." />
      <DataState loading={state.loading} error={state.error} empty={!state.data?.length} onRetry={state.reload}>
        <div className="stack">{state.data?.map((item) => (
          <Card
            key={item.id}
            title={item.title}
            eyebrow={item.created_at}
            actions={(
              <div className="button-row">
                {!item.read_at && <Button size="sm" variant="secondary" onClick={() => markRead(item.id)}>Mark read</Button>}
                <Button size="sm" variant="ghost" onClick={() => remove(item.id)}>Delete</Button>
              </div>
            )}
          >
            <p>{item.message}</p>
          </Card>
        ))}</div>
      </DataState>
    </>
  );
}
