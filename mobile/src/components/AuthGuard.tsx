import { usePathname, useRouter } from 'expo-router';
import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';
import { useEffect, type ReactNode } from 'react';

import { useAuth } from '@/src/auth/AuthContext';
import { theme } from '@/src/theme';

const PUBLIC_PATHS = ['/login'];

export function AuthGuard({ children }: { children: ReactNode }) {
  const { loading, isAuthenticated } = useAuth();
  const pathname = usePathname();
  const router = useRouter();

  useEffect(() => {
    if (loading) return;

    if (!isAuthenticated && !PUBLIC_PATHS.includes(pathname)) {
      router.replace('/login');
      return;
    }

    if (isAuthenticated && PUBLIC_PATHS.includes(pathname)) {
      router.replace('/(tabs)');
    }
  }, [isAuthenticated, loading, pathname, router]);

  if (loading) {
    return (
      <View style={styles.gate}>
        <ActivityIndicator color={theme.colors.primary} size="large" />
        <Text style={styles.gateText}>Restoring your session…</Text>
      </View>
    );
  }

  if (!isAuthenticated && !PUBLIC_PATHS.includes(pathname)) {
    return null;
  }

  return <>{children}</>;
}

const styles = StyleSheet.create({
  gate: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: theme.colors.background,
    gap: theme.spacing.md,
  },
  gateText: {
    color: theme.colors.muted,
    fontSize: 13,
  },
});
