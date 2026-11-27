import { useRouter } from 'expo-router';
import { useState } from 'react';

import { api } from '@/src/api/endpoints';
import { useAuth } from '@/src/auth/AuthContext';
import { canManagePlans } from '@/src/auth/permissions';
import { Badge, Button, Card, EmptyState, ErrorState, ListRow, LoadingState, PageHeader, Screen, Section, SelectChips, TextField } from '@/src/components/ui';
import { useResource } from '@/src/hooks/useResource';
import { formatMoney } from '@/src/format';

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

  const routers = useResource(() => api.routers.list({ per_page: 100 }), []);

  const resource = useResource(
    () => api.plans.list({
      search: search || undefined,
      router: routerFilter || undefined,
      active: active || undefined,
      per_page: 50,
    }),
    [search, routerFilter, active],
  );

  return (
    <Screen>
      <PageHeader
        title="Plans"
        subtitle="HotSpot packages mapped to router profiles"
        action={
          canManagePlans(user) ? (
            <Button label="New" compact onPress={() => router.push('/plans/new')} />
          ) : null
        }
      />

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

      {resource.loading && !resource.data ? <LoadingState label="Loading plans…" /> : null}
      {resource.error ? <ErrorState message={resource.error.message} onRetry={() => void resource.reload()} /> : null}

      {resource.data ? (
        <Section title={`${resource.data.items.length} plan(s)`}>
          <Card>
            {resource.data.items.length === 0 ? (
              <EmptyState title="No plans found" message="Adjust the filters or create a new plan." />
            ) : (
              resource.data.items.map((plan) => (
                <ListRow
                  key={String(plan.id)}
                  title={plan.name}
                  subtitle={`${plan.router_name ?? `Router ${plan.router_id}`} · ${formatMoney(plan.price, plan.currency)} · ${plan.connected_time_label ?? `${plan.time_allowance_seconds}s`}`}
                  trailing={<Badge label={plan.is_active ? 'active' : 'inactive'} tone={plan.is_active ? 'success' : 'danger'} />}
                  onPress={() => router.push(`/plans/${plan.public_id ?? plan.id}`)}
                />
              ))
            )}
          </Card>
        </Section>
      ) : null}
    </Screen>
  );
}
