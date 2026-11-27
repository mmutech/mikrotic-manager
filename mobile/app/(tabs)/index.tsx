import { useRouter } from 'expo-router';
import { RefreshControl, ScrollView, Text, View } from 'react-native';

import { api } from '@/src/api/endpoints';
import { useAuth } from '@/src/auth/AuthContext';
import { canViewReports, isSalesAgent } from '@/src/auth/permissions';
import {
  Badge,
  Button,
  Card,
  ErrorState,
  ListRow,
  LoadingState,
  PageHeader,
  Screen,
  Section,
  StatTile,
} from '@/src/components/ui';
import { useResource } from '@/src/hooks/useResource';
import { formatDate, statusTone } from '@/src/format';
import { theme } from '@/src/theme';

export default function HomeScreen() {
  const router = useRouter();
  const { user } = useAuth();
  const reportsAllowed = canViewReports(user);
  const salesAgent = isSalesAgent(user);
  const overview = useResource(
    () => (reportsAllowed ? api.reports.overview() : Promise.resolve(null)),
    [reportsAllowed],
  );
  const subscription = useResource(() => api.subscription.mine(), []);

  const subscriptionLocked = reportsAllowed && overview.error?.code === 'subscription_inactive';
  const data = overview.data;

  return (
    <Screen
      refreshControl={
        <RefreshControl
          refreshing={overview.loading}
          onRefresh={() => {
            void overview.reload();
            void subscription.reload();
          }}
        />
      }>
      <PageHeader
        title={`Hello, ${user?.name?.split(' ')[0] ?? 'there'}`}
        subtitle={salesAgent ? 'Voucher workflow' : 'Network operations at a glance'}
      />

      {subscriptionLocked ? (
        <Card style={{ backgroundColor: theme.colors.warningSoft }}>
          <Badge label="Subscription required" tone="warning" />
          <View style={{ height: 10 }} />
          <Text style={{ fontSize: 13, color: theme.colors.text }}>
            Operational screens are locked until your subscription is renewed.
          </Text>
          <View style={{ height: 12 }} />
          <Button label="Open subscription" compact onPress={() => router.push('/(tabs)/account')} />
        </Card>
      ) : null}

      {salesAgent ? (
        <Section title="Voucher workspace">
          <Card>
            <ListRow title="Vouchers" subtitle="List, print, and review assigned vouchers" onPress={() => router.push('/(tabs)/vouchers')} />
            <ListRow title="Generate vouchers" subtitle="Create vouchers for an assigned router and plan" onPress={() => router.push('/vouchers/generate')} />
            <ListRow title="Assigned routers" subtitle="Read-only router details" onPress={() => router.push('/(tabs)/routers')} />
            <ListRow title="Plans" subtitle="Read-only HotSpot packages" onPress={() => router.push('/(tabs)/plans')} />
          </Card>
        </Section>
      ) : null}

      {overview.loading && !data ? <LoadingState label="Loading dashboard…" /> : null}
      {overview.error && !subscriptionLocked ? (
        <ErrorState message={overview.error.message} onRetry={() => void overview.reload()} />
      ) : null}

      {data ? (
        <>
          <Section title="Overview">
            <ScrollView horizontal showsHorizontalScrollIndicator={false}>
              <StatTile label="Routers online" value={`${data.routers.online}/${data.routers.total}`} tone="success" />
              <StatTile label="Active vouchers" value={data.vouchers.active} />
              <StatTile label="Active plans" value={data.plans.active} tone="primary" />
              <StatTile label="Revenue" value={data.revenue.total_amount} tone="warning" />
            </ScrollView>
          </Section>

          <Section title="Operations">
            <Card>
              <ListRow title="Generate vouchers" subtitle="Create vouchers from an active plan" onPress={() => router.push('/vouchers/generate')} />
              <ListRow title="Router status" subtitle="Health checks and queued jobs" onPress={() => router.push('/router-status')} />
              <ListRow title="Reports" subtitle="Voucher, revenue, router, and subscription reports" onPress={() => router.push('/reports')} />
            </Card>
          </Section>

          <Section title="Network health">
            <Card>
              <InfoLine label="Needs attention" value={String(data.routers.needs_attention)} />
              <InfoLine label="Monitoring enabled" value={String(data.routers.monitoring)} />
              <InfoLine label="Pending jobs" value={String(data.routers.pending_jobs)} />
              <InfoLine label="Paid transactions" value={String(data.transactions.paid)} />
            </Card>
          </Section>
        </>
      ) : null}

      <Section title="My subscription">
        <Card>
          {subscription.loading && !subscription.data ? <LoadingState /> : null}
          {subscription.error ? <ErrorState message={subscription.error.message} onRetry={() => void subscription.reload()} /> : null}
          {subscription.data ? (
            <>
              <Badge
                label={subscription.data.has_active_subscription ? 'Active' : subscription.data.pending ? 'Pending' : 'Needs renewal'}
                tone={statusTone(subscription.data.has_active_subscription ? 'active' : subscription.data.pending ? 'pending' : 'expired')}
              />
              <View style={{ height: 10 }} />
              <InfoLine label="Requires subscription" value={subscription.data.requires_subscription ? 'Yes' : 'No'} />
              <InfoLine label="Type" value={subscription.data.active?.type_label ?? '—'} />
              <InfoLine
                label="Expires"
                value={subscription.data.active?.expires_at ? formatDate(subscription.data.active.expires_at) : '—'}
              />
              <InfoLine label="History entries" value={String(subscription.data.history.length)} />
              {!subscription.data.requires_subscription || subscription.data.has_active_subscription ? null : (
                <View style={{ marginTop: 12 }}>
                  <Button
                    label={subscription.data.pending ? 'Request already pending' : 'Request renewal'}
                    variant="secondary"
                    disabled={subscription.data.pending}
                    onPress={() => router.push('/subscription')}
                  />
                </View>
              )}
            </>
          ) : null}
        </Card>
      </Section>

      {salesAgent ? null : (
        <Section title="Shortcuts">
          <Card>
            <ListRow title="Browse plans" subtitle="Review router plans and profiles" onPress={() => router.push('/plans')} />
            <ListRow title="Router list" subtitle="View assigned routers" onPress={() => router.push('/(tabs)/routers')} />
            <ListRow title="Subscription" subtitle="Request renewal and review history" onPress={() => router.push('/subscription')} />
          </Card>
        </Section>
      )}
    </Screen>
  );
}

function InfoLine({ label, value }: { label: string; value: string }) {
  return <ListRow title={label} subtitle={value} />;
}
