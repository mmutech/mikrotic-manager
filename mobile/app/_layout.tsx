import { Stack } from 'expo-router';
import * as SplashScreen from 'expo-splash-screen';
import { StatusBar } from 'expo-status-bar';
import { useEffect } from 'react';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import 'react-native-reanimated';

import { AuthGuard } from '@/src/components/AuthGuard';
import { AuthProvider, useAuth } from '@/src/auth/AuthContext';
import { RefreshProvider } from '@/src/hooks/useRefresh';
import { theme } from '@/src/theme';

export { ErrorBoundary } from 'expo-router';

void SplashScreen.preventAutoHideAsync().catch(() => {});

function SplashScreenController() {
  const { loading } = useAuth();

  useEffect(() => {
    const fallback = setTimeout(() => {
      void SplashScreen.hideAsync().catch(() => {});
    }, 4000);

    return () => clearTimeout(fallback);
  }, []);

  useEffect(() => {
    if (!loading) {
      void SplashScreen.hideAsync().catch(() => {});
    }
  }, [loading]);

  return null;
}

export default function RootLayout() {
  return (
    <SafeAreaProvider>
      <AuthProvider>
        <SplashScreenController />
        <RefreshProvider>
          <AuthGuard>
            <Stack
              screenOptions={{
                headerShown: false,
                contentStyle: { backgroundColor: theme.colors.background },
              }}>
              <Stack.Screen name="(auth)" />
              <Stack.Screen name="(tabs)" />
              <Stack.Screen name="index" />
            </Stack>
          </AuthGuard>
        </RefreshProvider>
        <StatusBar style="dark" />
      </AuthProvider>
    </SafeAreaProvider>
  );
}
