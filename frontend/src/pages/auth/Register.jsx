import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import Button from '../../components/Button.jsx';
import { useAuth } from '../../context/AuthContext.jsx';

export default function Register() {
  const [form, setForm] = useState({ name: '', email: '', phone: '', password: '', confirmPassword: '' });
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const { register } = useAuth();
  const navigate = useNavigate();

  async function handleSubmit(event) {
    event.preventDefault();
    setError('');

    if (form.password.length < 8) {
      setError('Password must be at least 8 characters.');
      return;
    }
    if (form.password !== form.confirmPassword) {
      setError('Passwords do not match.');
      return;
    }

    setLoading(true);
    try {
      await register({
        ...form,
        name: form.name.trim(),
        email: form.email.trim().toLowerCase(),
        phone: form.phone.trim(),
      });
      navigate('/login', {
        replace: true,
        state: {
          email: form.email.trim().toLowerCase(),
          message: 'Account created successfully. Sign in with your new credentials.',
        },
      });
    } catch (err) {
      setError(err.message || 'Account registration failed.');
    } finally {
      setLoading(false);
    }
  }

  return (
    <main className="auth-page login-page register-page">
      <section className="login-showcase">
        <div className="login-showcase-copy">
          <img className="login-logo" src="/library-logo.svg" alt="Rwanda Library Network logo" />
          <p className="eyebrow">Rwanda Library Network</p>
          <h1>Welcome to MULTILINGUAL DIGITAL LIBRARY</h1>
          <p>Create your account to access curated books, course resources, personal reading, and intelligent library services across Rwanda.</p>
        </div>
      </section>
      <section className="auth-panel login-panel register-panel">
        <div className="auth-brand">
          <img src="/library-logo.svg" alt="" />
          <div>
            <strong>MULTILINGUAL DIGITAL LIBRARY</strong>
            <span>Knowledge, research, and intelligent access</span>
          </div>
        </div>
        <h2>Create your account</h2>
        <p>Register a student account. Lecturer and librarian roles are assigned by the library administrator.</p>
        {error && <div className="inline-error" role="alert">{error}</div>}
        <form onSubmit={handleSubmit} className="form-grid">
          <label>Full name<input autoComplete="name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required /></label>
          <label>Email<input type="email" autoComplete="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} required /></label>
          <label>Phone<input type="tel" autoComplete="tel" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} required /></label>
          <label>Password<input type="password" autoComplete="new-password" minLength={8} value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} required /></label>
          <label>Confirm password<input type="password" autoComplete="new-password" minLength={8} value={form.confirmPassword} onChange={(e) => setForm({ ...form, confirmPassword: e.target.value })} required /></label>
          <Button type="submit" className="span-2" disabled={loading}>{loading ? 'Creating account...' : 'Create library account'}</Button>
        </form>
        <div className="auth-links"><Link to="/login">Already have an account? Sign in</Link></div>
      </section>
    </main>
  );
}
