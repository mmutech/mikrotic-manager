import { useLocalSearchParams, useRouter } from 'expo-router';
import { Alert, View } from 'react-native';
import { useEffect, useState } from 'react';

import { api } from '@/src/api/endpoints';
import { useAuth } from '@/src/auth/AuthContext';
import { canApproveSubscriptions } from '@/src/auth/permissions';
import {
  Badge,
  Button,
  Card,
  ErrorState,
  InfoRow,
  ListRow,
  LoadingState,
  PageHeader,
  Screen,
  Section,
  SelectChips,
  TextField,
} from '@/src/components/ui';
import { useAction } from '@/src/hooks/useAction';
import { useResource } from '@/src/hooks/useResource';
import { formatDate, formatMoney, statusTone } from '@/src/format';

export default function SubscriptionDetailScreen() {
  const params = useLocalSearchParams<{ id: string }>();
  const id = String(params.id ?? '');
  const router = useRouter();
  const { user } = useAuth();
  const resource = useResource(() => api.subscription.show(id), [id]);
  const plans = useResource(() => api.subscription.plans(), []);

  const [type, setType] = useState('monthly');
  const [monthlyPrice, setMonthlyPrice] = useState('0');
  const [yearlyPrice, setYearlyPrice] = useState('0');
  const [priceNotice, setPriceNotice] = useState<string | null>(null);

  useEffect(() => {
    if (! plans.data) {
      return;
    }

    setMonthlyPrice(String(plans.data.find((option) => option.value === 'monthly')?.price ?? 0));
    setYearlyPrice(String(plans.data.find((option) => option.value === 'yearly')?.price ?? 0));
  }, [plans.data]);

  const planOptions = (plans.data ?? []).map((option) => ({
    label: `${option.label} · ${formatMoney(option.price, option.currency)} / ${option.days} days`,
    value: option.value,
  }));
  const selectedPlan = plans.data?.find((option) => option.value === type);

  const activate = useAction(async () => {
    const result = await api.subscription.activate(id, { type });
    await resource.reload();
    return result;
  });

  const savePrices = useAction(async () => {
    const result = await api.subscription.updatePrices({
      monthly: Number(monthlyPrice),
      yearly: Number(yearlyPrice),
    });

    setPriceNotice('Subscription type prices updated.');
    await plans.reload();
    return result;
  });

  const deactivate = useAction(async () => {
    const result = await api.subscription.deactivate(id);
    await resource.reload();
    return result;
  });

  const admin = resource.data;

  function confirmDeactivate(): void {
    Alert.alert('Deactivate subscription?', 'This immediately ends active subscriptions for this administrator.', [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Deactivate',
        style: 'destructive',
        onPress: async () => {
          const result = await deactivate.run();
          Alert.alert(result ? 'Deactivated' : 'Unable to deactivate', result ? `${result.deactivated} subscription(s) ended.` : deactivate.error?.message);
        },
      },
    ]);
  }

  return (
    <Screen>
      <PageHeader
        title={admin?.name ?? 'Subscription'}
        subtitle={admin?.email ?? 'Network administrator'}
        action={<Button label="Done" compact variant="secondary" onPress={() => router.back()} />}
      />

      {resource.loading && !admin ? <LoadingState label="Loading subscription…" /> : null}
      {resource.error ? <ErrorState message={resource.error.message} onRetry={() => void resource.reload()} /> : null}

      {admin ? (
        <>
          <Card>
            <Badge label={admin.status ?? 'active'} tone={statusTone(admin.status)} />
            <View style={{ height: 12 }} />
            <InfoRow label="Requires subscription" value={admin.requires_subscription ? 'Yes' : 'No'} />
            <InfoRow label="Has active" value={admin.has_active_subscription ? 'Yes' : 'No'} />
            <InfoRow label="Plan" value={admin.active_subscription?.type_label ?? '—'} />
            <InfoRow
              label="Price"
              value={
                admin.active_subscription
                  ? formatMoney(admin.active_subscription.price, admin.active_subscription.currency)
                  : '—'
              }
            />
            <InfoRow
              label="Active until"
              value={formatDate(admin.active_subscription?.expires_at)}
            />
            <InfoRow label="Roles" value={admin.roles.join(', ') || '—'} />
          </Card>

          <Section title="Activate">
            <Card>
              <SelectChips
                options={planOptions}
                value={type}
                onChange={(value) => setType(value ?? 'monthly')}
              />
              <View style={{ height: 10 }} />
              <InfoRow
                label="Price"
                value={
                  selectedPlan
                    ? formatMoney(selectedPlan.price, selectedPlan.currency)
                    : '—'
                }
              />
              <Button
                label="Activate subscription"
                loading={activate.pending}
                disabled={planOptions.length === 0}
                onPress={async () => {
                  const result = await activate.run();
                  Alert.alert(result ? 'Activated' : 'Unable to activate', result ? 'Subscription activated.' : activate.error?.message);
                }}
              />
            </Card>
          </Section>

          {canApproveSubscriptions(user) ? (
            <Section title="Type prices">
              <Card>
                <TextField
                  label="Monthly price"
                  value={monthlyPrice}
                  onChangeText={setMonthlyPrice}
                  keyboardType="decimal-pad"
                  error={savePrices.error?.errors.monthly?.[0] ?? null}
                />
                <TextField
                  label="Yearly price"
                  value={yearlyPrice}
                  onChangeText={setYearlyPrice}
                  keyboardType="decimal-pad"
                  error={savePrices.error?.errors.yearly?.[0] ?? null}
                />
                {priceNotice ? <InfoRow label="Result" value={priceNotice} /> : null}
                {plans.error ? <ErrorState message={plans.error.message} onRetry={() => void plans.reload()} /> : null}
                <Button
                  label="Save prices"
                  loading={savePrices.pending}
                  onPress={() => void savePrices.run()}
                />
              </Card>
            </Section>
          ) : null}

          <Section title="History">
            <Card>
              {admin.meta?.history?.length ? (
                admin.meta.history.map((item) => (
                  <ListRow
                    key={String(item.id)}
                    title={item.type_label}
                    subtitle={`${formatMoney(item.price, item.currency)} · ${formatDate(item.starts_at)} → ${formatDate(item.expires_at)}`}
                    trailing={<Badge label={item.status} tone={statusTone(item.status)} />}
                  />
                ))
              ) : (
                <ListRow title="No subscription history." subtitle="" />
              )}
            </Card>
          </Section>

          <Section title="Danger zone">
            <Card>
              <Button label="Deactivate" variant="danger" loading={deactivate.pending} onPress={confirmDeactivate} />
            </Card>
          </Section>
        </>
      ) : null}
    </Screen>
  );
}
