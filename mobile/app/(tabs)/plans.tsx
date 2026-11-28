import { useRouter } from 'expo-router';
import { Plus } from 'lucide-react-native';
import { useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';

import { api } from '@/src/api/endpoints';
import { useAuth } from '@/src/auth/AuthContext';
import { canManagePlans } from '@/src/auth/permissions';
import {
  Badge,
  Button,
  EmptyState,
  ErrorState,
  ListRow,
  LoadingState,
  PageHeader,
  Screen,
  Section,
  SelectChips,
  TextField,
} from '@/src/components/ui';
import { PaginatedList } from '@/src/components/PaginatedList';
import { usePaginatedResource } from '@/src/hooks/usePaginatedResource';
import { useResource } from '@/src/hooks/useResource';
import { formatMoney } from '@/src/format';
import { theme } from '@/src/theme';

const ACTIVE_OPTIONS = [
  { label: 'All', value: '' },
  { label: 'Active', value: 'true' },
  { label: 'Inactive', value: 'false' },
];

export default function PlansScreen() {
  const router = useRouter();
  const { user } = useAuth();
  const [search, setSearch] = useState('');
  const [routerFilter, setRouterFilter] = useState('');
  const [active, setActive] = useState('');

  const routers = useResource(() => api.routers.list({ per_page: 10 }), []);

  const list = usePaginatedResource(
    (params) => api.plans.list({
      search: search || undefined,
      router: routerFilter || undefined,
      active: active || undefined,
      ...params,
    }),
    [search, routerFilter, active],
    { perPage: 10, getKey: (item) => item.public_id ?? item.id },
  );

  return (
    <Screen
      scroll={false}
      contentStyle={{ flex: 1, paddingBottom: 0 }}
      header={
        <PageHeader
          back
          title="Plans"
          subtitle="HotSpot packages mapped to router profiles"
          action={
            canManagePlans(user) ? (
              <Button label="New" icon={Plus} iconOnly compact onPress={() => router.push('/plans/new')} />
            ) : null
          }
        />
      }>
      <PaginatedList
        items={list.items}
        keyExtractor={(item) => String(item.public_id ?? item.id)}
        renderItem={({ item, index }) => (
          <ListRow
            title={item.name}
            subtitle={`${item.router_name ?? `Router ${item.router_id}`} · ${formatMoney(item.price, item.currency)} · ${item.connected_time_label ?? `${item.time_allowance_seconds}s`}`}
            trailing={<Badge label={item.is_active ? 'active' : 'inactive'} tone={item.is_active ? 'success' : 'danger'} />}
            onPress={() => router.push(`/plans/${item.public_id ?? item.id}`)}
            style={[
              styles.row,
              index === 0 ? styles.firstRow : null,
              index === list.items.length - 1 ? styles.lastRow : null,
            ]}
          />
        )}
        loading={list.loading}
        loadingMore={list.loadingMore}
        error={list.error}
        loadMoreError={list.loadMoreError}
        hasMore={list.hasMore}
        onLoadMore={list.loadMore}
        onReload={list.reload}
        loadingLabel="Loading plans…"
        ListEmptyComponent={<EmptyState title="No plans found" message="Adjust the filters or create a new plan." />}
        ListHeaderComponent={
          <>
            <TextField label="Search plans" value={search} onChangeText={setSearch} placeholder="Name or code" autoCapitalize="none" />

            <Section title="Router">
              {routers.loading && !routers.data ? <LoadingState label="Loading routers…" /> : null}
              {routers.error ? <ErrorState message={routers.error.message} onRetry={() => void routers.reload()} /> : null}
              {routers.data ? (
                <SelectChips
                  options={routers.data.items.map((item) => ({ label: item.name, value: String(item.public_id ?? item.id) }))}
                  value={routerFilter || null}
                  onChange={(value) => setRouterFilter(value ?? '')}
                />
              ) : null}
            </Section>

            <SelectChips options={ACTIVE_OPTIONS} value={active} onChange={(value) => setActive(value ?? '')} />

            <View style={styles.listHeading}>
              <Text style={styles.listHeadingText}>{list.meta.total ?? list.items.length} plan(s)</Text>
            </View>
          </>
        }
      />
    </Screen>
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
