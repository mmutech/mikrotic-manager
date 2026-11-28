import {
  CODEPAGE,
  EscPosBuilder,
  encodeCodepage850,
  twoColumns,
  wrap,
} from '@ricka7x/expo-thermal-printer/escpos';

import type { Voucher } from '@/src/types';

import type { PaperWidth } from './printerSettings';

const PAPER_COLUMNS: Record<PaperWidth, number> = {
  '58mm': 32,
  '80mm': 48,
};

function display(value: unknown, fallback = '—'): string {
  const text = String(value ?? '').trim();
  return text || fallback;
}

function centerInColumns(value: string, columns: number): string {
  const text = value.length > columns ? value.slice(0, columns) : value;
  const padding = columns - text.length;
  const left = Math.floor(padding / 2);

  return `${' '.repeat(left)}${text}${' '.repeat(padding - left)}`;
}

function writeVoucher(write: EscPosBuilder, voucher: Voucher, columns: number): void {
  write
    .align('center')
    .bold(true)
    .size(2, 1)
    .line('MMU TECH', encodeCodepage850)
    .size(1, 1)
    .bold(false)
    .line('WI-FI VOUCHER', encodeCodepage850)
    .dashedRule(columns);

  write
    .wrapped(display(voucher.router_name, 'Router'), { columns }, encodeCodepage850)
    .wrapped(
      display(voucher.plan_name ?? voucher.hotspot_profile, 'Voucher'),
      { columns },
      encodeCodepage850,
    )
    .dashedRule(columns);

  write.align('center').line('USERNAME', encodeCodepage850);

  const largeColumns = Math.max(8, Math.floor(columns / 2));
  write.bold(true).size(2, 1);

  wrap(display(voucher.username).toUpperCase(), { columns: largeColumns }).forEach((line) => {
    write.line(centerInColumns(line, largeColumns), encodeCodepage850);
  });

  write.size(1, 1).bold(false).dashedRule(columns);

  write
    .align('left')
    .line(twoColumns('Plan', display(voucher.plan_name ?? voucher.hotspot_profile), columns), encodeCodepage850)
    .line(twoColumns('Router', display(voucher.router_name), columns), encodeCodepage850);

  if (voucher.hotspot_profile) {
    write.line(twoColumns('Profile', voucher.hotspot_profile, columns), encodeCodepage850);
  }

  write.align('center').line('Connect with this username', encodeCodepage850).feed(1);
}

/**
 * Builds one ESC/POS job containing every selected voucher. The layout is pure
 * bytes, so it can be tested and previewed without a printer.
 */
export function buildVoucherEscPos(vouchers: Voucher[], paperWidth: PaperWidth = '58mm'): Uint8Array {
  const columns = PAPER_COLUMNS[paperWidth];
  const write = new EscPosBuilder();

  write.init().codepage(CODEPAGE.CP850);

  vouchers.forEach((voucher, index) => {
    writeVoucher(write, voucher, columns);

    if (index < vouchers.length - 1) {
      write
        .align('center')
        .dashedRule(columns)
        .line(`Voucher ${index + 1} of ${vouchers.length}`, encodeCodepage850)
        .feed(1);
    }
  });

  return write.align('center').line('Thank you', encodeCodepage850).feedToTear().build();
}
