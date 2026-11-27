import { Stack } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import 'react-native-reanimated';

import { AuthGuard } from '@/src/components/AuthGuard';
import { AuthProvider } from '@/src/auth/AuthContext';

export { ErrorBoundary } from 'expo-router';

export default function RootLayout() {
  return (
    <SafeAreaProvider>
      <AuthProvider>
        <AuthGuard>
          <Stack
            screenOptions={{
              headerShown: false,
              contentStyle: { backgroundColor: '#F4F6FB' },
            }}>
            <Stack.Screen name="(auth)" />
            <Stack.Screen name="(tabs)" />
            <Stack.Screen name="index" />
          </Stack>
        </AuthGuard>
        <StatusBar style="dark" />
      </AuthProvider>
    </SafeAreaProvider>
  );
}
