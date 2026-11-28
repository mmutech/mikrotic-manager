import { useRouter } from 'expo-router';
import { Activity } from 'lucide-react-native';
import { useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';

import { api } from '@/src/api/endpoints';
import { canViewReports } from '@/src/auth/permissions';
import { useAuth } from '@/src/auth/AuthContext';
import { Badge, Button, Card, EmptyState, ListRow, PageHeader, Screen, Section, SelectChips, StatTile } from '@/src/components/ui';
import { PaginatedList } from '@/src/components/PaginatedList';
import { usePaginatedResource } from '@/src/hooks/usePaginatedResource';
import { formatDate, statusTone } from '@/src/format';
import { theme } from '@/src/theme';
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

  const list = usePaginatedResource(
    (params) => api.routers.list({ status: status || undefined, ...params }),
    [status],
    { perPage: 10, getKey: (item) => item.public_id ?? item.id },
  );

  const summary = list.meta.summary as RouterSummary | undefined;
  const locked = list.error?.code === 'subscription_inactive';

  return (
    <Screen
      scroll={false}
      contentStyle={{ flex: 1, paddingBottom: 0 }}
      header={
        <PageHeader
          back
          title="Routers"
          subtitle="Assigned RouterOS devices and their health"
          action={
            canViewReports(user) ? (
              <Button label="Status" icon={Activity} iconOnly variant="secondary" compact onPress={() => router.push('/router-status')} />
            ) : null
          }
        />
      }>
      <PaginatedList
        items={list.items}
        keyExtractor={(item) => String(item.public_id ?? item.id)}
        renderItem={({ item, index }) => {
          const last = index === list.items.length - 1;

          return (
            <ListRow
              title={item.name}
              subtitle={`${item.identity ?? item.host ?? 'No identity'} · Last seen ${formatDate(item.last_seen_at ?? item.last_checked_at)}`}
              trailing={<Badge label={item.status ?? (item.is_online ? 'online' : 'offline')} tone={statusTone(item.status ?? (item.is_online ? 'online' : 'offline'))} />}
              onPress={() => router.push(`/routers/${item.public_id ?? item.id}`)}
              style={[
                styles.row,
                index === 0 ? styles.firstRow : null,
                last ? styles.lastRow : null,
              ]}
            />
          );
        }}
        loading={list.loading}
        loadingMore={list.loadingMore}
        error={list.error}
        loadMoreError={list.loadMoreError}
        hasMore={list.hasMore}
        onLoadMore={list.loadMore}
        onReload={list.reload}
        loadingLabel="Loading routers…"
        ListEmptyComponent={
          locked ? (
            <EmptyState title="Subscription required" message="An active subscription is needed to view routers." />
          ) : (
            <EmptyState title="No routers found" message="Try a different status filter." />
          )
        }
        ListHeaderComponent={
          <>
            {locked ? <SubscriptionNotice /> : null}
            <SelectChips options={STATUS_OPTIONS} value={status} onChange={(value) => setStatus(value ?? '')} />
            {summary ? (
              <Section title="Summary">
                <Card>
                  <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 10 }}>
                    <StatTile label="Visible" value={summary.total} compact />
                    <StatTile label="Online" value={summary.online} tone="success" compact />
                    <StatTile label="Attention" value={summary.needs_attention} tone="danger" compact />
                    <StatTile label="Monitoring" value={summary.monitoring} tone="warning" compact />
                  </View>
                </Card>
              </Section>
            ) : null}
            <View style={styles.listHeading}>
              <Text style={styles.listHeadingText}>Devices</Text>
            </View>
          </>
        }
      />
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

const styles = StyleSheet.create({
  listHeading: {
    marginTop: theme.spacing.lg,
    marginBottom: theme.spacing.sm,
  },
  listHeadingText: {
    fontSize: 16,
    fontWeight: '700',
    color: theme.colors.text,
  },
  row: {
    backgroundColor: theme.colors.surface,
    paddingHorizontal: theme.spacing.md,
    borderLeftWidth: 1,
    borderRightWidth: 1,
    borderBottomWidth: 1,
    borderColor: theme.colors.border,
  },
  firstRow: {
    borderTopWidth: 1,
    borderTopLeftRadius: theme.radius.md,
    borderTopRightRadius: theme.radius.md,
  },
  lastRow: {
    borderBottomLeftRadius: theme.radius.md,
    borderBottomRightRadius: theme.radius.md,
  },
});
