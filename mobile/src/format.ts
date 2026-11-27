export function humanize(value?: string | null): string {
  if (!value) return '—';

  return value
    .replace(/[_-]+/g, ' ')
    .replace(/\b\w/g, (character) => character.toUpperCase());
}

export function formatBytes(value?: number | null): string {
  if (value === null || value === undefined) return 'Unlimited';

  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  let size = Math.max(0, Number(value));
  let unit = 0;

  while (size >= 1024 && unit < units.length - 1) {
    size /= 1024;
    unit += 1;
  }

  const precision = size >= 100 || unit === 0 ? 0 : size >= 10 ? 1 : 2;
  return `${size.toFixed(precision)} ${units[unit]}`;
}

export function formatDuration(value?: number | string | null): string {
  if (value === null || value === undefined || value === '') return '—';

  const total = Math.max(0, Math.floor(Number(value) || 0));
  const days = Math.floor(total / 86400);
  const hours = Math.floor((total % 86400) / 3600);
  const minutes = Math.floor((total % 3600) / 60);
  const seconds = total % 60;

  if (days > 0) return `${days}d ${hours}h`;
  if (hours > 0) return `${hours}h ${minutes}m`;
  if (minutes > 0) return `${minutes}m ${seconds}s`;

  return `${seconds}s`;
}

export function formatMoney(amount: number | string, currency: string): string {
  const numeric = Number(amount) || 0;

  try {
    return new Intl.NumberFormat(undefined, {
      style: 'currency',
      currency: currency || 'NGN',
      maximumFractionDigits: 2,
    }).format(numeric);
  } catch {
    return `${currency} ${numeric.toFixed(2)}`;
  }
}

export function formatDate(value?: string | null): string {
  if (!value) return '—';

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;

  return date.toLocaleString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

export function statusTone(status?: string | null): 'success' | 'warning' | 'danger' | 'neutral' {
  const normalized = (status ?? '').toLowerCase();

  if (['active', 'online', 'provisioned', 'succeeded', 'paid', 'fulfilled'].includes(normalized)) {
    return 'success';
  }

  if (['pending', 'queued', 'running', 'retrying', 'expiring', 'disabling'].includes(normalized)) {
    return 'warning';
  }

  if (['offline', 'error', 'failed', 'revoked', 'disabled', 'inactive', 'expired'].includes(normalized)) {
    return 'danger';
  }

  return 'neutral';
}
