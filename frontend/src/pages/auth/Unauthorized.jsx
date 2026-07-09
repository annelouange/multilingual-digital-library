import Card from '../../components/Card.jsx';

export default function Unauthorized() {
  return (
    <main className="auth-page">
      <Card title="Access denied" className="auth-panel">
        <p>Your role does not have permission to open this page.</p>
      </Card>
    </main>
  );
}
