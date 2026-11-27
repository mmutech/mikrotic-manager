import { useRouter } from 'expo-router';
import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';
import { useEffect } from 'react';

import { useAuth } from '@/src/auth/AuthContext';
import { theme } from '@/src/theme';

export default function Index() {
  const router = useRouter();
  const { loading, isAuthenticated } = useAuth();

  useEffect(() => {
    if (loading) return;
    router.replace(isAuthenticated ? '/(tabs)' : '/login');
  }, [isAuthenticated, loading, router]);

  return (
    <View style={styles.container}>
      <ActivityIndicator color={theme.colors.primary} size="large" />
      <Text style={styles.text}>Opening MMU Tech…</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 14, backgroundColor: theme.colors.background },
  text: { color: theme.colors.muted, fontSize: 13 },
});
