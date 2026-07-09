import { createContext, useContext, useEffect, useMemo, useState } from 'react';
import * as authApi from '../api/auth.js';
import { clearSession, loadSession, saveSession } from '../utils/storage.js';
import { getDashboardPath, ROLES } from '../utils/roles.js';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [session, setSession] = useState(() => loadSession());
  const [authError, setAuthError] = useState('');

  useEffect(() => {
    function clearExpiredSession() {
      localStorage.removeItem('mdl_token');
      clearSession();
      setSession(null);
      setAuthError('Your session expired. Please sign in again.');
    }
    window.addEventListener('mdl:unauthorized', clearExpiredSession);
    return () => window.removeEventListener('mdl:unauthorized', clearExpiredSession);
  }, []);

  async function login(email, password) {
    setAuthError('');
    const response = await authApi.login(email, password);
    const nextSession = response.data || response;
    localStorage.setItem('mdl_token', nextSession.token);
    saveSession(nextSession);
    setSession(nextSession);
    return nextSession;
  }

  async function register(payload) {
    setAuthError('');
    const response = await authApi.register(payload);
    return response.data || response;
  }

  async function logout() {
    try {
      if (session?.token) {
        await authApi.logout();
      }
    } finally {
      localStorage.removeItem('mdl_token');
      clearSession();
      setSession(null);
    }
  }

  const value = useMemo(() => {
    const user = session?.user || null;
    return {
      user,
      token: session?.token || null,
      authError,
      isAuthenticated: Boolean(user),
      login,
      register,
      logout,
      canAccess(roles) {
        if (!roles?.length) return true;
        return Boolean(user && roles.includes(user.role));
      },
      dashboardPath: getDashboardPath(user?.role || ROLES.STUDENT),
    };
  }, [session, authError]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const context = useContext(AuthContext);
  if (!context) throw new Error('useAuth must be used inside AuthProvider');
  return context;
}
