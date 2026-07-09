import { useState } from 'react';
import { Link } from 'react-router-dom';
import Button from '../../components/Button.jsx';
import * as authApi from '../../api/auth.js';

export default function ForgotPassword() {
  const [email, setEmail] = useState('');
  const [message, setMessage] = useState('');
  const [resetUrl, setResetUrl] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  async function handleSubmit(event) {
    event.preventDefault();
    setLoading(true);
    setError('');
    setMessage('');
    setResetUrl('');
    try {
      const response = await authApi.forgotPassword(email.trim().toLowerCase());
      setMessage(response.message || 'If the account exists, reset instructions were created.');
      setResetUrl(response.data?.reset_url || '');
    } catch (requestError) {
      setError(requestError.message);
    } finally {
      setLoading(false);
    }
  }

  return (
    <main className="auth-page login-page">
      <section className="login-showcase">
        <div className="login-showcase-copy">
          <img className="login-logo" src="/library-logo.svg" alt="Rwanda Library Network logo" />
          <p className="eyebrow">Rwanda Library Network</p>
          <h1>Recover your library account</h1>
          <p>Request a secure, time-limited link to choose a new password.</p>
        </div>
      </section>
      <section className="auth-panel login-panel">
        <div className="auth-brand">
          <img src="/library-logo.svg" alt="" />
          <div><strong>MULTILINGUAL DIGITAL LIBRARY</strong><span>Secure account recovery</span></div>
        </div>
        <h2>Reset password</h2>
        <p>Enter the email linked to your library account.</p>
        {message && <div className="inline-info">{message}</div>}
        {error && <div className="inline-error" role="alert">{error}</div>}
        <form className="form-stack" onSubmit={handleSubmit}>
          <label>
            Account email
            <input type="email" autoComplete="email" placeholder="name@library.rw" value={email} onChange={(event) => setEmail(event.target.value)} required />
          </label>
          <Button type="submit" disabled={loading}>{loading ? 'Creating secure link...' : 'Send reset link'}</Button>
        </form>
        {resetUrl && (
          <div className="reset-ready">
            <strong>Development reset link</strong>
            <span>Email delivery is not configured in this local installation, so continue securely here.</span>
            <Link className="button button-primary button-md reset-continue" to={resetUrl}>Continue to set a new password</Link>
          </div>
        )}
        <div className="auth-links"><Link to="/login">Back to login</Link></div>
      </section>
    </main>
  );
}
