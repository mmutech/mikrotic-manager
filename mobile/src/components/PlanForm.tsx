import { useRouter } from 'expo-router';
import { View } from 'react-native';
import { useEffect, useState } from 'react';

import { api, type PlanInput } from '@/src/api/endpoints';
import { useAuth } from '@/src/auth/AuthContext';
import { canManagePlans } from '@/src/auth/permissions';
import {
  Badge,
  Button,
  Card,
  ErrorState,
  LoadingState,
  PageHeader,
  Screen,
  Section,
  SelectChips,
  SelectField,
  SwitchRow,
  TextField,
} from '@/src/components/ui';
import { useAction } from '@/src/hooks/useAction';
import { useResource } from '@/src/hooks/useResource';
import { formatBytes } from '@/src/format';
import type { Plan } from '@/src/types';

const TIME_ALLOWANCE_OPTIONS = [
  { label: 'No maximum time limit (0)', value: '0' },
  { label: '30 minutes', value: '1800' },
  { label: '1 hour', value: '3600' },
  { label: '2 hours', value: '7200' },
  { label: '4 hours', value: '14400' },
  { label: '6 hours', value: '21600' },
  { label: '12 hours', value: '43200' },
  { label: '1 day', value: '86400' },
  { label: '2 days', value: '172800' },
  { label: '7 days', value: '604800' },
  { label: '30 days', value: '2592000' },
];

export function PlanForm({
  mode,
  planId,
  initialPlan,
  onSaved,
}: {
  mode: 'create' | 'edit';
  planId?: string;
  initialPlan?: Plan;
  onSaved: (plan: Plan) => void;
}) {
  const router = useRouter();
  const { user } = useAuth();
  const allowed = canManagePlans(user);

  useEffect(() => {
    if (!allowed) router.replace('/(tabs)/plans');
  }, [allowed, router]);

  const [routerId, setRouterId] = useState('');
  const [name, setName] = useState(initialPlan?.name ?? '');
  const [profile, setProfile] = useState(initialPlan?.hotspot_profile ?? '');
  const [description, setDescription] = useState(initialPlan?.description ?? '');
  const [price, setPrice] = useState(initialPlan ? String(initialPlan.price) : '');
  const [currency, setCurrency] = useState(initialPlan?.currency ?? 'NGN');
  const [isUnlimited, setIsUnlimited] = useState(initialPlan?.is_unlimited ?? false);
  const [dataMb, setDataMb] = useState(
    initialPlan && !initialPlan.is_unlimited
      ? String(Math.max(1, Math.round(Number(initialPlan.data_allowance_bytes ?? 0) / 1048576)))
      : '',
  );
  const [validityDays, setValidityDays] = useState(
    initialPlan?.validity_seconds ? String(Math.round(Number(initialPlan.validity_seconds) / 86400)) : '',
  );
  const [timeSeconds, setTimeSeconds] = useState(
    initialPlan ? String(initialPlan.time_allowance_seconds ?? 0) : '0',
  );
  const [isActive, setIsActive] = useState(initialPlan?.is_active ?? true);

  const routers = useResource(() => api.routers.list({ per_page: 10 }), []);
  const profiles = useResource(
    () => (routerId ? api.routers.hotspotProfiles(routerId).then((result) => result.profiles) : Promise.resolve([])),
    [routerId],
  );

  useEffect(() => {
    if (!initialPlan || !routers.data) return;

    const current = routers.data.items.find(
      (item) => String(item.id) === String(initialPlan.router_id),
    );

    if (current && String(routerId) === String(initialPlan.router_id)) {
      setRouterId(String(current.public_id ?? current.id));
    }
  }, [initialPlan, routers.data, routerId]);

  const selectedProfile = profiles.data?.find((item) => item.name === profile);
  const timeOptions = timeSeconds && !TIME_ALLOWANCE_OPTIONS.some((item) => item.value === timeSeconds)
    ? [{ label: `${timeSeconds} seconds`, value: timeSeconds }, ...TIME_ALLOWANCE_OPTIONS]
    : TIME_ALLOWANCE_OPTIONS;

  const save = useAction(async (): Promise<Plan> => {
    const payload: PlanInput = {
      router_id: routerId,
      name,
      hotspot_profile: profile,
      description: description || null,
      price: Number(price),
      currency,
      is_unlimited: isUnlimited,
      data_allowance_mb: isUnlimited ? undefined : Number(dataMb),
      validity_days: validityDays ? Number(validityDays) : null,
      time_allowance_seconds: Number(timeSeconds),
      is_active: isActive,
    };

    if (mode === 'create') return api.plans.store(payload);
    return api.plans.update(planId ?? '', payload);
  });

  async function submit(): Promise<void> {
    const result = await save.run();
    if (result) onSaved(result);
  }

  const fieldError = (field: string): string | null => save.error?.errors[field]?.[0] ?? null;

  if (!allowed) return null;

  return (
    <Screen
      header={
        <PageHeader
          back
          title={mode === 'create' ? 'New plan' : 'Edit plan'}
          subtitle="Map a package to an existing RouterOS HotSpot profile"
        />
      }>
      <Section title="Router">
        {routers.loading && !routers.data ? <LoadingState label="Loading routers…" /> : null}
        {routers.error ? <ErrorState message={routers.error.message} onRetry={() => void routers.reload()} /> : null}
        {fieldError('router_id') ? <ErrorState message={fieldError('router_id') ?? ''} /> : null}
        {routers.data ? (
          <SelectChips
            options={routers.data.items.map((item) => ({ label: item.name, value: String(item.public_id ?? item.id) }))}
            value={routerId || null}
            onChange={(value) => {
              setRouterId(value ?? '');
              setProfile('');
            }}
          />
        ) : null}
      </Section>

      <Section
        title="HotSpot profile"
        action={selectedProfile ? <Badge label={selectedProfile.shared_users || 'profile'} /> : null}>
        <TextField
          label="RouterOS profile name"
          value={profile}
          onChangeText={setProfile}
          placeholder="Read profiles after choosing a router"
          autoCapitalize="none"
          error={fieldError('hotspot_profile')}
          helper={
            selectedProfile?.rate_limit
              ? `Rate limit: ${selectedProfile.rate_limit}`
              : 'Profiles are read directly from RouterOS; no RouterJob is queued.'
          }
        />
        {profiles.loading ? <LoadingState label="Reading profiles…" /> : null}
        {profiles.error ? <ErrorState message={profiles.error.message} onRetry={() => void profiles.reload()} /> : null}
        {profiles.data?.length ? (
          <SelectChips
            options={profiles.data.map((item) => ({ label: item.name, value: item.name }))}
            value={profile || null}
            onChange={(value) => setProfile(value ?? '')}
          />
        ) : null}
      </Section>

      <Section title="Plan details">
        <Card>
          <TextField label="Name" value={name} onChangeText={setName} error={fieldError('name')} />
          <TextField
            label="Description"
            value={description}
            onChangeText={setDescription}
            multiline
            numberOfLines={3}
            error={fieldError('description')}
          />
          <TextField
            label="Price"
            value={price}
            onChangeText={setPrice}
            keyboardType="decimal-pad"
            error={fieldError('price')}
          />
          <TextField
            label="Currency"
            value={currency}
            onChangeText={(value) => setCurrency(value.toUpperCase())}
            maxLength={3}
            autoCapitalize="characters"
            error={fieldError('currency')}
          />
          <SwitchRow
            label="Unlimited data"
            description="Turn off to set a data allowance"
            value={isUnlimited}
            onValueChange={setIsUnlimited}
          />
          {!isUnlimited ? (
            <TextField
              label="Data allowance (MB)"
              value={dataMb}
              onChangeText={setDataMb}
              keyboardType="numeric"
              error={fieldError('data_allowance_mb')}
              helper={dataMb ? `About ${formatBytes(Number(dataMb) * 1048576)}` : undefined}
            />
          ) : null}
          <TextField
            label="Validity (days)"
            value={validityDays}
            onChangeText={setValidityDays}
            keyboardType="numeric"
            placeholder="Blank means no fixed validity"
            error={fieldError('validity_days')}
          />
          <SelectField
            label="Connected time allowance"
            value={timeSeconds}
            options={timeOptions}
            onChange={setTimeSeconds}
            error={fieldError('time_allowance_seconds')}
            helper="0 means No maximum time limit. RouterOS receives limit-uptime=0s."
          />
          <SwitchRow
            label="Active"
            description="Inactive plans cannot be used to generate vouchers"
            value={isActive}
            onValueChange={setIsActive}
          />
        </Card>
      </Section>

      {save.error && Object.keys(save.error.errors).length === 0 ? (
        <ErrorState message={save.error.message} />
      ) : null}

      <View style={{ height: 16 }} />
      <Button
        label={mode === 'create' ? 'Create plan' : 'Save changes'}
        onPress={submit}
        loading={save.pending}
        disabled={!routerId || !name || !profile || !price || !currency || (!isUnlimited && !dataMb)}
      />
    </Screen>
  );
}
