import type {
  ConnectionChangedEvent,
  PrinterDevice,
} from '@ricka7x/expo-thermal-printer';
import { Platform } from 'react-native';

type PrinterLibrary = typeof import('@ricka7x/expo-thermal-printer');

export interface PrinterStatus {
  supported: boolean;
  bluetoothEnabled: boolean;
  connected: boolean;
  address: string | null;
}

export type ConnectionSubscription = { remove: () => void };

export function isBluetoothPrintingSupported(): boolean {
  return Platform.OS === 'android' || Platform.OS === 'ios';
}

async function printerLibrary(): Promise<PrinterLibrary | null> {
  if (!isBluetoothPrintingSupported()) return null;

  return import('@ricka7x/expo-thermal-printer');
}

export async function readPrinterStatus(): Promise<PrinterStatus> {
  const supported = isBluetoothPrintingSupported();
  const fallback: PrinterStatus = {
    supported,
    bluetoothEnabled: false,
    connected: false,
    address: null,
  };

  const library = await printerLibrary();

  if (!library) return fallback;

  try {
    return {
      supported,
      bluetoothEnabled: library.isBluetoothEnabled(),
      connected: library.isConnected(),
      address: library.connectedAddress(),
    };
  } catch {
    return fallback;
  }
}

export async function ensureBluetoothPermission(): Promise<boolean> {
  const library = await printerLibrary();

  if (!library) return false;

  try {
    return await library.ensureBluetoothPermission();
  } catch {
    return false;
  }
}

export async function findAvailablePrinters(): Promise<PrinterDevice[]> {
  const library = await printerLibrary();

  if (!library) return [];

  return library.findPrinters({ scanTimeoutMs: 5000 });
}

export async function connectPrinter(address: string): Promise<void> {
  const library = await printerLibrary();

  if (!library) {
    throw { code: 'unsupported', message: 'Bluetooth printing requires an Android or iOS device.' };
  }

  await library.connectToPrinter(address);
}

export async function disconnectPrinter(): Promise<void> {
  const library = await printerLibrary();

  if (!library) return;

  await library.disconnectPrinter();
}

export async function printEscPosBytes(bytes: Uint8Array): Promise<void> {
  const library = await printerLibrary();

  if (!library) {
    throw { code: 'unsupported', message: 'Bluetooth printing requires an Android or iOS device.' };
  }

  await library.printBytes(bytes);
}

export async function subscribeToConnectionChanged(
  listener: (event: ConnectionChangedEvent) => void,
): Promise<ConnectionSubscription> {
  const library = await printerLibrary();

  if (!library) return { remove: () => {} };

  try {
    return library.onConnectionChanged(listener);
  } catch {
    return { remove: () => {} };
  }
}

export function printerErrorText(error: unknown): string {
  const code = typeof error === 'object' && error !== null && 'code' in error
    ? String((error as { code?: unknown }).code ?? '')
    : '';

  switch (code) {
    case 'bluetooth_off':
      return 'Turn on Bluetooth, then try again.';
    case 'permission_denied':
      return 'Bluetooth permission is required to use a printer.';
    case 'not_connected':
      return 'The printer is not connected. Select it and try again.';
    case 'write_failed':
      return 'The printer could not receive the voucher. Check that it is powered on and within range.';
    case 'unsupported':
      return 'Bluetooth printing requires an Android or iOS development build.';
    default:
      return error instanceof Error && error.message
        ? error.message
        : 'The printer request could not be completed.';
  }
}
