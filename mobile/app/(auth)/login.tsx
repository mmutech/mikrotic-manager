import { useRouter } from 'expo-router';
import { useState } from 'react';
import { Text, View } from 'react-native';

import { API_BASE_URL } from '@/src/api/client';
import { useAuth } from '@/src/auth/AuthContext';
import { Button, Card, Screen, TextField } from '@/src/components/ui';
import { useAction } from '@/src/hooks/useAction';
import { theme } from '@/src/theme';

export default function LoginScreen() {
  const router = useRouter();
  const { login } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const action = useAction(login);

  async function submit(): Promise<void> {
    const user = await action.run(email, password);
    if (user) router.replace('/(tabs)');
  }

  return (
    <Screen>
      <View style={{ paddingTop: 56 }}>
        <Text style={{ fontSize: 30, fontWeight: '800', color: theme.colors.text }}>MMU Tech</Text>
        <Text style={{ fontSize: 14, color: theme.colors.muted, marginTop: 6 }}>
          Sign in to manage routers, plans, and vouchers.
        </Text>
      </View>

      <Card style={{ marginTop: 32 }}>
        <TextField
          label="Email address"
          value={email}
          onChangeText={setEmail}
          autoCapitalize="none"
          autoCorrect={false}
          keyboardType="email-address"
          placeholder="admin@example.com"
          error={action.error?.errors.email?.[0] ?? null}
        />
        <TextField
          label="Password"
          value={password}
          onChangeText={setPassword}
          secureTextEntry
          autoCapitalize="none"
          placeholder="••••••••"
          error={action.error?.errors.password?.[0] ?? null}
        />

        {action.error && !action.error.errors.email && !action.error.errors.password ? (
          <Text style={{ color: theme.colors.danger, fontSize: 13, marginBottom: 12 }}>
            {action.error.message}
          </Text>
        ) : null}

        <Button label="Sign in" onPress={submit} loading={action.pending} disabled={!email || !password} />
      </Card>

      <Text style={{ fontSize: 11, color: theme.colors.muted, marginTop: 20, textAlign: 'center' }}>
        API: {API_BASE_URL}
      </Text>
    </Screen>
  );
}
