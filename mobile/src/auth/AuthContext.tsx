import { Platform } from 'react-native';
import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';

import { api } from '@/src/api/endpoints';
import { apiClient } from '@/src/api/client';
import { clearToken, readToken, saveToken } from '@/src/auth/token';
import type { User } from '@/src/types';

interface AuthContextValue {
  user: User | null;
  loading: boolean;
  isAuthenticated: boolean;
  login: (email: string, password: string) => Promise<User>;
  logout: () => Promise<void>;
  deactivateAccount: () => Promise<void>;
  refresh: () => Promise<User>;
}

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);

  const clearSession = useCallback(async () => {
    await clearToken();
    apiClient.setToken(null);
    setUser(null);
  }, []);

  useEffect(() => {
    apiClient.setAuthFailureHandler(() => {
      void clearSession();
    });

    return () => apiClient.setAuthFailureHandler(null);
  }, [clearSession]);

  useEffect(() => {
    let active = true;

    async function restore(): Promise<void> {
      const stored = await readToken();

      if (!active) return;

      if (!stored) {
        setLoading(false);
        return;
      }

      apiClient.setToken(stored);

      try {
        const current = await api.auth.me();
        if (active) setUser(current);
      } catch {
        await clearSession();
      } finally {
        if (active) setLoading(false);
      }
    }

    void restore();

    return () => {
      active = false;
    };
  }, [clearSession]);

  const login = useCallback(async (email: string, password: string): Promise<User> => {
    const result = await api.auth.login({
      email: email.trim(),
      password,
      device_name: `mmutech-${Platform.OS}`,
    });

    await saveToken(result.token);
    apiClient.setToken(result.token);
    setUser(result.user);

    return result.user;
  }, []);

  const logout = useCallback(async (): Promise<void> => {
    try {
      await api.auth.logout();
    } finally {
      await clearSession();
    }
  }, [clearSession]);

  const deactivateAccount = useCallback(async (): Promise<void> => {
    // Only clear the local session after the server confirms the deactivation
    // so a failed request leaves the user signed in and able to retry.
    await api.auth.deactivateAccount();
    await clearSession();
  }, [clearSession]);

  const refresh = useCallback(async (): Promise<User> => {
    const current = await api.auth.me();
    setUser(current);
    return current;
  }, []);

  const value = useMemo<AuthContextValue>(
    () => ({
      user,
      loading,
      isAuthenticated: user !== null,
      login,
      logout,
      deactivateAccount,
      refresh,
    }),
    [login, loading, logout, deactivateAccount, refresh, user],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext);

  if (!context) {
    throw new Error('useAuth must be used inside AuthProvider.');
  }

  return context;
}
