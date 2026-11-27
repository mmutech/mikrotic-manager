import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';

const TOKEN_KEY = 'mmutech.api.token';

export async function readToken(): Promise<string | null> {
  if (Platform.OS === 'web') {
    try {
      return typeof localStorage === 'undefined' ? null : localStorage.getItem(TOKEN_KEY);
    } catch {
      return null;
    }
  }

  try {
    return await SecureStore.getItemAsync(TOKEN_KEY);
  } catch {
    return null;
  }
}

export async function saveToken(token: string): Promise<void> {
  if (Platform.OS === 'web') {
    if (typeof localStorage === 'undefined') return;
    localStorage.setItem(TOKEN_KEY, token);
    return;
  }

  await SecureStore.setItemAsync(TOKEN_KEY, token);
}

export async function clearToken(): Promise<void> {
  if (Platform.OS === 'web') {
    try {
      if (typeof localStorage !== 'undefined') localStorage.removeItem(TOKEN_KEY);
    } catch {
      // Ignore storage failures; the in-memory session is cleared separately.
    }
    return;
  }

  try {
    await SecureStore.deleteItemAsync(TOKEN_KEY);
  } catch {
    // A failed delete should never prevent the in-memory session from clearing.
  }
}
