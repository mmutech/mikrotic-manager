import * as Print from 'expo-print';
import * as Sharing from 'expo-sharing';
import { Platform } from 'react-native';

import type { Voucher } from '@/src/types';

import { voucherPrintHtml } from './voucherHtml';

/**
 * Print one or more vouchers. On web this opens a hidden print frame; on native
 * it creates a PDF and hands it to the system share sheet, where it can be
 * printed or saved.
 */
export async function printVouchers(vouchers: Voucher[]): Promise<void> {
  if (vouchers.length === 0) return;

  const html = voucherPrintHtml(vouchers);

  if (Platform.OS !== 'web') {
    const { uri } = await Print.printToFileAsync({ html });

    if (await Sharing.isAvailableAsync()) {
      await Sharing.shareAsync(uri, {
        mimeType: 'application/pdf',
        dialogTitle: 'Wi-Fi vouchers',
      });
    }

    return;
  }

  if (typeof document === 'undefined') return;

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

  document.body.appendChild(frame);
  frame.srcdoc = html;

  await new Promise<void>((resolve) => {
    frame.addEventListener(
      'load',
      () => {
        const printWindow = frame.contentWindow;

        if (printWindow !== null) {
          printWindow.addEventListener('afterprint', () => frame.remove(), { once: true });
          printWindow.focus();
          printWindow.print();
        } else {
          frame.remove();
        }

        resolve();
      },
      { once: true },
    );
  });
}
