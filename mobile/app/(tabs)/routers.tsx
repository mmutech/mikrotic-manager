import { useRouter } from 'expo-router';
import { useState } from 'react';
import { View } from 'react-native';

import { api } from '@/src/api/endpoints';
import { canViewReports } from '@/src/auth/permissions';
import { useAuth } from '@/src/auth/AuthContext';
import { Badge, Button, Card, EmptyState, ErrorState, ListRow, LoadingState, PageHeader, Screen, Section, SelectChips, StatTile } from '@/src/components/ui';
import { useResource } from '@/src/hooks/useResource';
import { formatDate, statusTone } from '@/src/format';
import type { RouterSummary } from '@/src/types';

const STATUS_OPTIONS = [
  { label: 'All', value: '' },
  { label: 'Online', value: 'online' },
  { label: 'Offline', value: 'offline' },
  { label: 'Error', value: 'error' },
];

export default function RoutersScreen() {
  const router = useRouter();
  const { user } = useAuth();
  const [status, setStatus] = useState('');

  const resource = useResource(
    () => api.routers.list({ status: status || undefined, per_page: 50 }),
    [status],
  );

  const summary = resource.data?.meta.summary as RouterSummary | undefined;
  const locked = resource.error?.code === 'subscription_inactive';

  return (
    <Screen>
      <PageHeader
        title="Routers"
        subtitle="Assigned RouterOS devices and their health"
        action={
          canViewReports(user) ? (
            <Button label="Status" variant="secondary" compact onPress={() => router.push('/router-status')} />
          ) : null
        }
      />

      {locked ? <SubscriptionNotice /> : null}

      <SelectChips options={STATUS_OPTIONS} value={status} onChange={(value) => setStatus(value ?? '')} />

      {resource.loading && !resource.data ? <LoadingState label="Loading routers…" /> : null}
      {resource.error && !locked ? <ErrorState message={resource.error.message} onRetry={() => void resource.reload()} /> : null}

      {summary ? (
        <Section title="Summary">
          <Card>
            <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 10 }}>
              <StatTile label="Visible" value={summary.total} />
              <StatTile label="Online" value={summary.online} tone="success" />
              <StatTile label="Attention" value={summary.needs_attention} tone="danger" />
              <StatTile label="Monitoring" value={summary.monitoring} tone="warning" />
            </View>
          </Card>
        </Section>
      ) : null}

      {resource.data ? (
        <Section title="Devices">
          <Card>
            {resource.data.items.length === 0 ? (
              <EmptyState title="No routers found" message="Try a different status filter." />
            ) : (
              resource.data.items.map((item, index) => (
                <ListRow
                  key={String(item.id)}
                  title={item.name}
                  subtitle={`${item.identity ?? item.host ?? 'No identity'} · Last seen ${formatDate(item.last_seen_at ?? item.last_checked_at)}`}
                  trailing={<Badge label={item.status ?? (item.is_online ? 'online' : 'offline')} tone={statusTone(item.status ?? (item.is_online ? 'online' : 'offline'))} />}
                  onPress={() => router.push(`/routers/${item.public_id ?? item.id}`)}
                />
              ))
            )}
          </Card>
        </Section>
      ) : null}
    </Screen>
  );
}

function SubscriptionNotice() {
  return (
    <Card style={{ marginBottom: 12 }}>
      <Badge label="Subscription required" tone="warning" />
    </Card>
  );
}
