import { useLocalSearchParams, useRouter } from 'expo-router';
import { Alert, View } from 'react-native';

import { api } from '@/src/api/endpoints';
import { useAuth } from '@/src/auth/AuthContext';
import { canManagePlans } from '@/src/auth/permissions';
import { Badge, Button, Card, ErrorState, InfoRow, LoadingState, PageHeader, Screen, Section } from '@/src/components/ui';
import { useAction } from '@/src/hooks/useAction';
import { useResource } from '@/src/hooks/useResource';
import { formatBytes, formatDate, formatMoney } from '@/src/format';

export default function PlanDetailScreen() {
  const params = useLocalSearchParams<{ id: string }>();
  const id = String(params.id ?? '');
  const router = useRouter();
  const { user } = useAuth();
  const resource = useResource(() => api.plans.show(id), [id]);
  const plan = resource.data;

  const toggleActive = useAction(async () => {
    if (!plan) return null;
    return api.plans.update(id, { is_active: !plan.is_active });
  });

  const remove = useAction(async () => {
    await api.plans.destroy(id);
    return true;
  });

  async function confirmDelete(): Promise<void> {
    Alert.alert('Delete plan?', 'Plans with vouchers cannot be deleted. Deactivate instead.', [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Delete',
        style: 'destructive',
        onPress: async () => {
          const result = await remove.run();
          if (result) router.back();
          else Alert.alert('Unable to delete', remove.error?.message ?? 'Please try again.');
        },
      },
    ]);
  }

  return (
    <Screen>
      <PageHeader
        back
        title={plan?.name ?? 'Plan'}
        subtitle={plan?.code ?? 'HotSpot plan'}
        action={
          canManagePlans(user) ? (
            <Button label="Edit" compact variant="secondary" onPress={() => router.push(`/plans/${id}/edit`)} />
          ) : null
        }
      />

      {resource.loading && !plan ? <LoadingState label="Loading plan…" /> : null}
      {resource.error ? <ErrorState message={resource.error.message} onRetry={() => void resource.reload()} /> : null}

      {plan ? (
        <>
          <Card>
            <Badge label={plan.is_active ? 'active' : 'inactive'} tone={plan.is_active ? 'success' : 'danger'} />
            <View style={{ height: 12 }} />
            <InfoRow label="Router" value={plan.router_name ?? String(plan.router_id)} />
            <InfoRow label="HotSpot profile" value={plan.hotspot_profile ?? '—'} />
            <InfoRow label="Price" value={formatMoney(plan.price, plan.currency)} />
            <InfoRow label="Description" value={plan.description ?? '—'} />
            <InfoRow label="Data allowance" value={plan.is_unlimited ? 'Unlimited' : formatBytes(plan.data_allowance_bytes)} />
            <InfoRow label="Validity" value={plan.validity_label ?? '—'} />
            <InfoRow label="Connected time" value={plan.connected_time_label ?? `${plan.time_allowance_seconds}s`} />
            <InfoRow label="Upload" value={plan.upload_speed_kbps ? `${plan.upload_speed_kbps} kbps` : '—'} />
            <InfoRow label="Download" value={plan.download_speed_kbps ? `${plan.download_speed_kbps} kbps` : '—'} />
            <InfoRow label="Updated" value={formatDate(plan.updated_at)} />
          </Card>

          {canManagePlans(user) ? (
            <Section title="Actions">
              <Card>
                <Button
                  label={plan.is_active ? 'Deactivate' : 'Activate'}
                  variant="secondary"
                  loading={toggleActive.pending}
                  onPress={async () => {
                    const result = await toggleActive.run();
                    if (result) await resource.reload();
                  }}
                />
                {toggleActive.error ? <ErrorState message={toggleActive.error.message} /> : null}
                <View style={{ height: 10 }} />
                <Button label="Delete plan" variant="danger" loading={remove.pending} onPress={confirmDelete} />
              </Card>
            </Section>
          ) : null}
        </>
      ) : null}
    </Screen>
  );
}
