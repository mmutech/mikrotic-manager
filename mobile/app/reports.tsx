import { useRouter } from 'expo-router';
import { View } from 'react-native';
import { useEffect, useState } from 'react';

import { api } from '@/src/api/endpoints';
import { useAuth } from '@/src/auth/AuthContext';
import { canViewReports } from '@/src/auth/permissions';
import {
  Badge,
  Card,
  ErrorState,
  InfoRow,
  ListRow,
  LoadingState,
  PageHeader,
  Screen,
  Section,
  SelectChips,
  StatTile,
} from '@/src/components/ui';
import { useResource } from '@/src/hooks/useResource';
import { formatBytes, formatDate, formatDuration, formatMoney, statusTone } from '@/src/format';
import type { RevenueReport, RouterSummary, SubscriptionReport, VoucherStats } from '@/src/types';

type ReportTab = 'vouchers' | 'revenue' | 'routers' | 'subscriptions';

type ReportData = {
  vouchers: VoucherStats;
  revenue: RevenueReport;
  routers: RouterSummary;
  subscriptions: SubscriptionReport;
};

const TABS: Array<{ label: string; value: ReportTab }> = [
  { label: 'Vouchers', value: 'vouchers' },
  { label: 'Revenue', value: 'revenue' },
  { label: 'Routers', value: 'routers' },
  { label: 'Subscriptions', value: 'subscriptions' },
];

export default function ReportsScreen() {
  const router = useRouter();
  const { user } = useAuth();
  const allowed = canViewReports(user);
  const [tab, setTab] = useState<ReportTab>('vouchers');

  useEffect(() => {
    if (!allowed) router.replace('/(tabs)');
  }, [allowed, router]);

  const resource = useResource(async () => {
    if (tab === 'vouchers') return { tab, data: await api.reports.vouchers() } as const;
    if (tab === 'revenue') return { tab, data: await api.reports.revenue() } as const;
    if (tab === 'routers') return { tab, data: await api.reports.routers() } as const;
    return { tab, data: await api.reports.subscriptions() } as const;
  }, [tab]);

  const result = resource.data;

  if (!allowed) return null;

  return (
    <Screen>
      <PageHeader title="Reports" subtitle="Permission-scoped operational reporting" back />
      <SelectChips options={TABS} value={tab} onChange={(value) => value && setTab(value)} />

      {resource.loading && !result ? <LoadingState label="Loading report…" /> : null}
      {resource.error ? <ErrorState message={resource.error.message} onRetry={() => void resource.reload()} /> : null}

      {result?.tab === 'vouchers' ? <VoucherReport data={result.data} /> : null}
      {result?.tab === 'revenue' ? <RevenueReportView data={result.data} /> : null}
      {result?.tab === 'routers' ? <RouterReport data={result.data} /> : null}
      {result?.tab === 'subscriptions' ? <SubscriptionReportView data={result.data} /> : null}
    </Screen>
  );
}

function VoucherReport({ data }: { data: ReportData['vouchers'] }) {
  return (
    <>
      <Section title="Voucher summary">
        <Card>
          <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 10 }}>
            <StatTile label="Total" value={data.total} />
            <StatTile label="Active" value={data.active} tone="success" />
            <StatTile label="Provisioned" value={data.provisioned} />
            <StatTile label="Failed" value={data.provisioning_failed} tone="danger" />
            <StatTile label="Expiring 7d" value={data.expiring_in_7_days} tone="warning" />
          </View>
        </Card>
      </Section>
      <Section title="Usage">
        <Card>
          <InfoRow label="Data used" value={formatBytes(data.data_used_bytes)} />
          <InfoRow label="Connected time" value={formatDuration(data.connected_seconds)} />
        </Card>
      </Section>
      <Section title="By plan">
        <Card>
          {data.by_plan.map((row) => (
            <ListRow key={String(row.plan_id)} title={row.plan_name ?? 'Plan'} subtitle={`${row.total} voucher(s)`} />
          ))}
        </Card>
      </Section>
    </>
  );
}

function RevenueReportView({ data }: { data: ReportData['revenue'] }) {
  return (
    <>
      <Section title="Revenue">
        <Card>
          <InfoRow label="Total paid" value={formatMoney(data.total_amount, data.by_currency[0]?.currency ?? 'NGN')} />
          <InfoRow label="Transactions" value={String(data.transaction_count)} />
        </Card>
      </Section>
      <Section title="By currency">
        <Card>
          {data.by_currency.map((row) => (
            <ListRow key={row.currency} title={row.currency} subtitle={`${row.transactions} transaction(s)`} trailing={<Badge label={formatMoney(row.amount, row.currency)} tone="success" />} />
          ))}
        </Card>
      </Section>
      <Section title="Monthly">
        <Card>
          {data.monthly.map((row) => (
            <ListRow key={row.period} title={row.period} subtitle={`${row.transactions} transaction(s)`} trailing={<Badge label={formatMoney(row.amount, row.currencies[0] ?? 'NGN')} />} />
          ))}
        </Card>
      </Section>
    </>
  );
}

function RouterReport({ data }: { data: ReportData['routers'] }) {
  return (
    <>
      <Section title="Router summary">
        <Card>
          <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 10 }}>
            <StatTile label="Total" value={data.total} />
            <StatTile label="Online" value={data.online} tone="success" />
            <StatTile label="Attention" value={data.needs_attention} tone="danger" />
            <StatTile label="Pending jobs" value={data.pending_jobs} tone="warning" />
          </View>
        </Card>
      </Section>
      <Section title="By status">
        <Card>
          {Object.entries(data.by_status ?? {}).map(([status, total]) => (
            <ListRow key={status} title={status} subtitle={`${total} router(s)`} trailing={<Badge label={status} tone={statusTone(status)} />} />
          ))}
        </Card>
      </Section>
    </>
  );
}

function SubscriptionReportView({ data }: { data: ReportData['subscriptions'] }) {
  return (
    <>
      <Section title="Subscription summary">
        <Card>
          <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 10 }}>
            <StatTile label="Total" value={data.total} />
            <StatTile label="Monthly" value={data.by_type.monthly ?? 0} />
            <StatTile label="Yearly" value={data.by_type.yearly ?? 0} />
            <StatTile label="Active" value={data.active} tone="success" />
            <StatTile label="Expiring 7d" value={data.expiring_in_7_days} tone="warning" />
            <StatTile label="No subscription" value={data.subscribers_without_active_subscription} tone="danger" />
          </View>
        </Card>
      </Section>
      <Section title="Recent">
        <Card>
          {data.recent.map((item) => (
            <ListRow
              key={String(item.id)}
              title={item.type_label}
              subtitle={`${formatMoney(item.price, item.currency)} · ${formatDate(item.starts_at)} → ${formatDate(item.expires_at)}`}
              trailing={<Badge label={item.status} tone={statusTone(item.status)} />}
            />
          ))}
        </Card>
      </Section>
    </>
  );
}
