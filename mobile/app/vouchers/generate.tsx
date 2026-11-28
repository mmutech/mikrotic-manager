import { Alert, View } from 'react-native';
import { useState } from 'react';

import { api } from '@/src/api/endpoints';
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
import type { Voucher } from '@/src/types';

export default function GenerateVouchersScreen() {
  const [routerId, setRouterId] = useState('');
  const [planId, setPlanId] = useState('');
  const [quantity, setQuantity] = useState('1');
  const [prefix, setPrefix] = useState('');
  const [generated, setGenerated] = useState<Voucher[] | null>(null);

  const routers = useResource(() => api.routers.list({ per_page: 10 }), []);
  const plans = useResource(
    () => (routerId
      ? api.plans.list({ router: routerId, active: 1, per_page: 10 })
      : Promise.resolve(null)),
    [routerId],
  );

  const generate = useAction(async (): Promise<Voucher[]> => {
    const vouchers = await api.vouchers.generate({
      router_id: routerId,
      plan_id: planId,
      quantity: Number(quantity),
      prefix: prefix || undefined,
    });
    setGenerated(vouchers);
    return vouchers;
  });

  return (
    <Screen header={<PageHeader title="Generate vouchers" subtitle="Credentials are shown once, immediately after generation" back />}>
      <Section title="Router">
        {routers.loading && !routers.data ? <LoadingState label="Loading routers…" /> : null}
        {routers.error ? <ErrorState message={routers.error.message} onRetry={() => void routers.reload()} /> : null}
        {routers.data ? (
          <SelectChips
            options={routers.data.items.map((item) => ({ label: item.name, value: String(item.public_id ?? item.id) }))}
            value={routerId || null}
            onChange={(value) => {
              setRouterId(value ?? '');
              setPlanId('');
            }}
          />
        ) : null}
      </Section>

      <Section title="Plan">
        {routerId ? null : <InfoBanner text="Choose a router to load its active plans." />}
        {plans.loading && routerId ? <LoadingState label="Loading plans…" /> : null}
        {plans.error ? <ErrorState message={plans.error.message} onRetry={() => void plans.reload()} /> : null}
        {plans.data?.items.length ? (
          <SelectChips
            options={plans.data.items.map((plan) => ({ label: plan.name, value: String(plan.id) }))}
            value={planId || null}
            onChange={(value) => setPlanId(value ?? '')}
          />
        ) : null}
        {plans.data && plans.data.items.length === 0 ? <InfoBanner text="No active plans are mapped on this router." /> : null}
      </Section>

      <Section title="Batch">
        <Card>
          <TextField
            label="Quantity"
            value={quantity}
            onChangeText={setQuantity}
            keyboardType="numeric"
            helper="Maximum 100 vouchers per request"
            error={generate.error?.errors.quantity?.[0] ?? null}
          />
          <TextField
            label="Prefix"
            value={prefix}
            onChangeText={(value) => setPrefix(value.toUpperCase().replace(/[^A-Z0-9]/g, ''))}
            placeholder="Optional, e.g. HQ"
            autoCapitalize="characters"
            maxLength={10}
            error={generate.error?.errors.prefix?.[0] ?? null}
          />
          <TextField label="Username / password" value="Generated automatically from prefix + six characters" editable={false} />
        </Card>
      </Section>

      {generate.error && Object.keys(generate.error.errors).length === 0 ? (
        <ErrorState message={generate.error.message} />
      ) : null}

      <Button
        label="Generate vouchers"
        loading={generate.pending}
        disabled={!routerId || !planId || !quantity}
        onPress={async () => {
          const result = await generate.run();
          if (result) Alert.alert('Generated', `${result.length} voucher(s) created.`);
        }}
      />

      {generated ? (
        <Section title={`Generated credentials (${generated.length})`}>
          <Card>
            {generated.map((voucher) => (
              <ListRow
                key={String(voucher.id)}
                title={voucher.username}
                subtitle={`Password: ${voucher.password ?? voucher.username}`}
                trailing={<Badge label={voucher.provisioning_status ?? 'pending'} tone="warning" />}
              />
            ))}
          </Card>
        </Section>
      ) : null}

      <View style={{ height: 12 }} />
    </Screen>
  );
}

function InfoBanner({ text }: { text: string }) {
  return (
    <Card>
      <InfoRow label="Info" value={text} />
    </Card>
  );
}
