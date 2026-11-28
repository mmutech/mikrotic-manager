import * as Print from 'expo-print';
import * as Sharing from 'expo-sharing';
import { Platform } from 'react-native';

import type { Voucher } from '@/src/types';

import {
  connectPrinter,
  isBluetoothPrintingSupported,
  printerErrorText,
  printEscPosBytes,
  readPrinterStatus,
  type PrinterStatus,
} from './printer';
import {
  readPrinterSettings,
  savePrinterSelection,
  type PrinterSettings,
} from './printerSettings';
import { voucherPrintHtml } from './voucherHtml';
import { buildVoucherEscPos } from './voucherEscPos';

export interface PrintResult {
  method: 'bluetooth' | 'pdf' | 'browser' | 'error';
  count: number;
  note?: string;
}

function printErrorText(error: unknown): string {
  const printerMessage = printerErrorText(error);

  if (error instanceof Error && error.message && printerMessage !== error.message) {
    return error.message;
  }

  if (typeof error === 'string' && error.trim()) return error.trim();

  if (typeof error === 'object' && error !== null && 'message' in error) {
    const message = String((error as { message?: unknown }).message ?? '').trim();
    if (message) return message;
  }

  return printerMessage;
}

async function shareVoucherPdf(vouchers: Voucher[]): Promise<PrintResult> {
  const html = voucherPrintHtml(vouchers);
  let shareFailure: string | null = null;

  try {
    const generated = await Print.printToFileAsync({ html });
    const uri = generated?.uri;

    if (!uri) {
      throw new Error('The voucher PDF could not be created.');
    }

    if (await Sharing.isAvailableAsync()) {
      await Sharing.shareAsync(uri, {
        mimeType: 'application/pdf',
        dialogTitle: 'Wi-Fi vouchers',
      });
    }

    return {
      method: 'pdf',
      count: vouchers.length,
      note: 'Voucher PDF shared. Choose a Bluetooth printer in Printer settings to print directly.',
    };
  } catch (error) {
    shareFailure = printErrorText(error);
  }

  // PDF generation or sharing can fail on devices without a PDF handler. The
  // native print dialog is a final fallback before reporting an error.
  try {
    await Print.printAsync({ html });

    return {
      method: 'pdf',
      count: vouchers.length,
      note: shareFailure
        ? `${shareFailure} Opened the device print dialog instead.`
        : 'Voucher sheet opened in the device print dialog.',
    };
  } catch (error) {
    const printFailure = printErrorText(error);

    throw new Error(
      shareFailure
        ? `${shareFailure} The device print dialog also failed: ${printFailure}`
        : printFailure,
    );
  }
}

function printInBrowser(vouchers: Voucher[]): Promise<PrintResult> {
  if (typeof document === 'undefined') {
    return Promise.resolve({
      method: 'browser',
      count: vouchers.length,
      note: 'Voucher sheet opened in the browser print dialog.',
    });
  }

  const frame = document.createElement('iframe');
  frame.title = 'Voucher print document';
  frame.setAttribute('aria-hidden', 'true');
  frame.style.position = 'fixed';
  frame.style.top = '0';
  frame.style.left = '0';
  frame.style.width = '100vw';
  frame.style.height = '100vh';
  frame.style.opacity = '0';
  frame.style.pointerEvents = 'none';
  frame.style.zIndex = '-1';
  frame.style.border = '0';

  if (!document.body) {
    return Promise.reject(new Error('The voucher sheet has no page to print from.'));
  }

  document.body.appendChild(frame);

  return new Promise<PrintResult>((resolve, reject) => {
    const timeout = setTimeout(() => {
      cleanup();
      reject(new Error('The voucher sheet did not finish loading in time.'));
    }, 10000);

    function cleanup(): void {
      clearTimeout(timeout);
      frame.remove();
    }

    frame.onerror = () => {
      cleanup();
      reject(new Error('The voucher sheet could not be opened in the browser.'));
    };

    frame.onload = () => {
      try {
        const printWindow = frame.contentWindow;

        if (printWindow === null) {
          cleanup();
          reject(new Error('The voucher sheet could not be opened in the browser.'));
          return;
        }

        printWindow.addEventListener('afterprint', () => frame.remove(), { once: true });
        printWindow.focus();
        printWindow.print();
        clearTimeout(timeout);
        resolve({
          method: 'browser',
          count: vouchers.length,
          note: 'Voucher sheet opened in the browser print dialog.',
        });
      } catch (error) {
        cleanup();
        reject(error instanceof Error ? error : new Error(printErrorText(error)));
      }
    };

    frame.srcdoc = voucherPrintHtml(vouchers);
  });
}

/**
 * Print one or more vouchers. Native devices prefer a selected/connected
 * Bluetooth ESC/POS printer and fall back to the existing PDF/share flow when
 * no printer is available. Web keeps browser printing.
 */
export async function printVouchers(vouchers: Voucher[]): Promise<PrintResult> {
  if (vouchers.length === 0) {
    return { method: 'error', count: 0, note: 'No vouchers were selected for printing.' };
  }

  try {
    if (Platform.OS === 'web') {
      return await printInBrowser(vouchers);
    }

    if (!isBluetoothPrintingSupported()) {
      return await shareVoucherPdf(vouchers);
    }

    let settings: PrinterSettings;
    let status: PrinterStatus;

    try {
      [settings, status] = await Promise.all([
        readPrinterSettings(),
        readPrinterStatus(),
      ]);
    } catch {
      return await shareVoucherPdf(vouchers);
    }

    let address = settings.address;

    if (status.connected && status.address) {
      address = settings.address && settings.address !== status.address
        ? settings.address
        : status.address;

      if (!settings.address) {
        await savePrinterSelection(status.address, null).catch(() => {});
      }
    }

    if (address) {
      try {
        if (!status.connected || status.address !== address) {
          await connectPrinter(address);
        }
      } catch (error) {
        const message = printerErrorText(error);

        return {
          ...(await shareVoucherPdf(vouchers)),
          note: `${message} Shared the voucher PDF instead.`,
        };
      }

      // Once bytes are handed to the transport, do not silently create a second
      // PDF copy: the receipt may already be printing.
      await printEscPosBytes(buildVoucherEscPos(vouchers, settings.paperWidth));

      return {
        method: 'bluetooth',
        count: vouchers.length,
        note: `${vouchers.length} voucher(s) sent to the Bluetooth printer.`,
      };
    }

    return await shareVoucherPdf(vouchers);
  } catch (error) {
    return {
      method: 'error',
      count: vouchers.length,
      note: `Printing failed: ${printErrorText(error)}`,
    };
  }
}
