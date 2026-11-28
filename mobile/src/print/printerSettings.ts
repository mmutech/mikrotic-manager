import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';

export type PaperWidth = '58mm' | '80mm';

export interface PrinterSettings {
  address: string | null;
  name: string | null;
  paperWidth: PaperWidth;
}

const ADDRESS_KEY = 'mmutech.printer.address';
const NAME_KEY = 'mmutech.printer.name';
const PAPER_WIDTH_KEY = 'mmutech.printer.paperWidth';

export const DEFAULT_PRINTER_SETTINGS: PrinterSettings = {
  address: null,
  name: null,
  paperWidth: '58mm',
};

async function readValue(key: string): Promise<string | null> {
  if (Platform.OS === 'web') {
    try {
      return typeof localStorage === 'undefined' ? null : localStorage.getItem(key);
    } catch {
      return null;
    }
  }

  try {
    return await SecureStore.getItemAsync(key);
  } catch {
    return null;
  }
}

async function writeValue(key: string, value: string | null): Promise<void> {
  if (Platform.OS === 'web') {
    if (typeof localStorage === 'undefined') return;

    if (value === null) {
      localStorage.removeItem(key);
    } else {
      localStorage.setItem(key, value);
    }

    return;
  }

  if (value === null) {
    await SecureStore.deleteItemAsync(key);
  } else {
    await SecureStore.setItemAsync(key, value);
  }
}

export async function readPrinterSettings(): Promise<PrinterSettings> {
  const [address, name, paperWidth] = await Promise.all([
    readValue(ADDRESS_KEY),
    readValue(NAME_KEY),
    readValue(PAPER_WIDTH_KEY),
  ]);

  return {
    address: address || null,
    name: name || null,
    paperWidth: paperWidth === '80mm' ? '80mm' : '58mm',
  };
}

export async function savePrinterSelection(address: string, name: string | null): Promise<void> {
  await Promise.all([
    writeValue(ADDRESS_KEY, address),
    writeValue(NAME_KEY, name),
  ]);
}

export async function savePaperWidth(paperWidth: PaperWidth): Promise<void> {
  await writeValue(PAPER_WIDTH_KEY, paperWidth);
}

export async function clearPrinterSelection(): Promise<void> {
  await Promise.all([
    writeValue(ADDRESS_KEY, null),
    writeValue(NAME_KEY, null),
  ]);
}
