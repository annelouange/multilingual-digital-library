import { useEffect, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import * as authApi from '../../api/auth.js';
import Button from '../../components/Button.jsx';

function passwordChecks(password) {
  return [
    { label: 'At least 10 characters', passed: password.length >= 10 },
    { label: 'Uppercase and lowercase letters', passed: /[A-Z]/.test(password) && /[a-z]/.test(password) },
    { label: 'At least one number', passed: /\d/.test(password) },
    { label: 'At least one symbol', passed: /[^A-Za-z0-9]/.test(password) },
  ];
}

export default function ResetPassword() {
  const [params] = useSearchParams();
  const token = params.get('token') || '';
  const email = params.get('email') || '';
  const [form, setForm] = useState({
    token,
    email,
    password: '',
    password_confirmation: '',
  });
  const [error, setError] = useState('');
  const [verification, setVerification] = useState({ status: 'checking', email: '' });
  const [loading, setLoading] = useState(false);
  const navigate = useNavigate();
  const checks = passwordChecks(form.password);
  const passwordIsStrong = checks.every((check) => check.passed);

  useEffect(() => {
    let active = true;
    if (!token || !email) {
      setVerification({ status: 'invalid', email: '' });
      setError('The reset link is incomplete. Request a new reset link.');
      return () => { active = false; };
    }

    authApi.validateResetPassword({ token, email })
      .then((response) => {
        if (!active) return;
        const verifiedEmail = response.data?.email || email;
        setError('');
        setForm((current) => ({ ...current, token, email: verifiedEmail }));
        setVerification({ status: 'valid', email: verifiedEmail });
      })
      .catch((requestError) => {
        if (!active) return;
        setVerification({ status: 'invalid', email: '' });
        setError(requestError.message);
      });

    return () => { active = false; };
  }, [email, token]);

  async function handleSubmit(event) {
    event.preventDefault();
    setError('');
    if (form.password !== form.password_confirmation) {
      setError('Password confirmation does not match.');
      return;
    }
    if (!passwordIsStrong) {
      setError('Choose a password that meets every requirement below.');
      return;
    }
    setLoading(true);
    try {
      await authApi.resetPassword(form);
      navigate('/login', {
        replace: true,
        state: {
          email: params.get('email') || '',
          message: 'Password reset successfully. Sign in with your new password.',
        },
      });
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
          <h1>Create a new password</h1>
          <p>Use a strong password that you have not used previously for your library account.</p>
        </div>
      </section>
      <section className="auth-panel login-panel">
        <div className="auth-brand">
          <img src="/library-logo.svg" alt="" />
          <div><strong>MULTILINGUAL DIGITAL LIBRARY</strong><span>Secure account recovery</span></div>
        </div>
        <h2>Reset password</h2>
        {verification.status === 'checking' && <div className="inline-info" role="status">Confirming your reset link and email...</div>}
        {verification.status === 'valid' && <div className="inline-note" role="status">Verified account: {verification.email}</div>}
        {error && <div className="inline-error" role="alert">{error}</div>}
        <form className="form-stack" onSubmit={handleSubmit}>
          <label>
            Confirmed account email
            <input type="email" value={form.email} readOnly aria-readonly="true" />
          </label>
          <label>
            New password
            <input type="password" autoComplete="new-password" minLength="10" value={form.password} onChange={(event) => setForm({ ...form, password: event.target.value })} required />
          </label>
          <div className="password-checks" aria-label="Password requirements">
            {checks.map((check) => <span className={check.passed ? 'passed' : ''} key={check.label}>{check.passed ? 'OK' : 'Required'}: {check.label}</span>)}
          </div>
          <label>
            Confirm new password
            <input type="password" autoComplete="new-password" minLength="10" value={form.password_confirmation} onChange={(event) => setForm({ ...form, password_confirmation: event.target.value })} required />
          </label>
          <Button type="submit" disabled={loading || verification.status !== 'valid' || !passwordIsStrong}>{loading ? 'Resetting...' : 'Set new password'}</Button>
        </form>
        <div className="auth-links"><Link to="/forgot-password">Request another link</Link><Link to="/login">Back to login</Link></div>
      </section>
    </main>
  );
}
