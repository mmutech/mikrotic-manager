import { Stack, useRouter } from 'expo-router';
import { Text, View } from 'react-native';

import { Button, Screen } from '@/src/components/ui';
import { theme } from '@/src/theme';

export default function NotFoundScreen() {
  const router = useRouter();

  return (
    <>
      <Stack.Screen options={{ title: 'Not found' }} />
      <Screen>
        <View style={{ paddingTop: 64 }}>
          <Text style={{ fontSize: 20, fontWeight: '800', color: theme.colors.text }}>
            This screen doesn’t exist.
          </Text>
          <View style={{ height: 16 }} />
          <Button label="Go to home" onPress={() => router.replace('/')} />
        </View>
      </Screen>
    </>
  );
}
