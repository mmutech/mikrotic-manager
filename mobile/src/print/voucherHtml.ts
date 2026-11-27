import type { Voucher } from '@/src/types';

function escapeHtml(value: unknown): string {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function voucherTicket(voucher: Voucher): string {
  const router = voucher.router_name ?? 'Router';
  const plan = voucher.plan_name ?? voucher.hotspot_profile ?? 'Voucher';

  return `
    <section class="voucher-print-ticket">
      <h2>${escapeHtml(router)}</h2>
      <p class="label">Wi-Fi Voucher</p>
      <p>Enter this code to access the internet</p>
      <p class="voucher-code">${escapeHtml(voucher.username)}</p>
      <p class="meta">${escapeHtml(plan)} · ${escapeHtml(router)}</p>
    </section>`;
}

export function voucherPrintHtml(vouchers: Voucher[]): string {
  return `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Wi-Fi Vouchers</title>
<style>
  @page { margin: 10mm; }
  * { box-sizing: border-box; }
  body { margin: 0; color: #0f172a; font-family: Arial, sans-serif; }
  .voucher-print-sheet {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    align-content: start;
    gap: 6mm;
  }
  .voucher-print-ticket {
    break-inside: avoid;
    padding: 6mm;
    border: 1px solid #cbd5e1;
  }
  .voucher-print-ticket h2 { margin: 0; font-size: 16pt; }
  .voucher-print-ticket p { margin: 3mm 0 0; font-size: 9pt; }
  .voucher-print-ticket .label { font-weight: 600; }
  .voucher-print-ticket .voucher-code {
    margin-top: 5mm;
    padding: 4mm;
    border: 1px solid #cbd5e1;
    text-align: center;
    font: bold 22pt monospace;
    letter-spacing: 0.05em;
  }
  .voucher-print-ticket .meta { font-size: 8pt; }
</style>
</head>
<body>
<main class="voucher-print-sheet">${vouchers.map(voucherTicket).join('')}</main>
</body>
</html>`;
}
