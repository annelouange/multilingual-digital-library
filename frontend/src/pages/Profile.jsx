import PageHeader from '../components/PageHeader.jsx';
import Card from '../components/Card.jsx';
import { useAuth } from '../context/AuthContext.jsx';
import { roleLabels } from '../utils/roles.js';

export default function Profile() {
  const { user } = useAuth();
  return (
    <>
      <PageHeader title="Profile" description="Account information and current authorization context." />
      <Card title={user.name} eyebrow={roleLabels[user.role]}>
        <div className="detail-grid">
          <span>Email</span><strong>{user.email}</strong>
          <span>Status</span><strong>{user.status}</strong>
          <span>Faculty</span><strong>{user.faculty}</strong>
          <span>Department</span><strong>{user.department}</strong>
        </div>
      </Card>
    </>
  );
}
