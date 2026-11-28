import { usePathname, useRouter } from 'expo-router';
import { Wifi } from 'lucide-react-native';
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
        <View style={styles.logoMark}>
          <Wifi size={38} color={theme.colors.white} />
        </View>
        <Text style={styles.brand}>MMU Tech</Text>
        <Text style={styles.tagline}>Network operations suite</Text>

        <View style={styles.loadingRow}>
          <ActivityIndicator color={theme.colors.primary} size="small" />
          <Text style={styles.gateText}>Restoring your session…</Text>
        </View>
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
    paddingHorizontal: theme.spacing.xl,
  },
  logoMark: {
    width: 76,
    height: 76,
    borderRadius: 24,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: theme.colors.primary,
    shadowColor: theme.colors.primary,
    shadowOpacity: 0.24,
    shadowRadius: 18,
    shadowOffset: { width: 0, height: 8 },
    elevation: 5,
  },
  brand: {
    marginTop: theme.spacing.lg,
    fontSize: 24,
    fontWeight: '800',
    color: theme.colors.primary,
  },
  tagline: {
    marginTop: 4,
    fontSize: 12,
    color: theme.colors.muted,
  },
  loadingRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: theme.spacing.sm,
    marginTop: theme.spacing.xl,
    paddingVertical: 10,
    paddingHorizontal: theme.spacing.md,
    borderRadius: 999,
    backgroundColor: theme.colors.surface,
    borderWidth: 1,
    borderColor: theme.colors.border,
  },
  gateText: {
    color: theme.colors.muted,
    fontSize: 13,
  },
});
