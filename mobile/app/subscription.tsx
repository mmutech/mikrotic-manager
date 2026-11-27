import { View } from 'react-native';
import { useState } from 'react';

import { api } from '@/src/api/endpoints';
import {
  Badge,
  Button,
  Card,
  EmptyState,
  ErrorState,
  InfoRow,
  ListRow,
  LoadingState,
  PageHeader,
  Screen,
  Section,
  SelectChips,
} from '@/src/components/ui';
import { useAction } from '@/src/hooks/useAction';
import { useResource } from '@/src/hooks/useResource';
import { formatDate, formatMoney, humanize, statusTone } from '@/src/format';

export default function SubscriptionScreen() {
  const [notice, setNotice] = useState<string | null>(null);
  const [type, setType] = useState('monthly');
  const resource = useResource(() => api.subscription.mine(), []);
  const plans = useResource(() => api.subscription.plans(), []);
  const request = useAction(async () => {
    const result = await api.subscription.requestRenewal({ type });
    await resource.reload();
    return result;
  });

  const data = resource.data;
  const planOptions = (plans.data ?? []).map((option) => ({
    label: `${option.label} · ${formatMoney(option.price, option.currency)} / ${option.days} days`,
    value: option.value,
  }));

  async function submitRequest(): Promise<void> {
    const result = await request.run();

    setNotice(
      result
        ? 'Subscription request submitted. An administrator will activate it.'
        : 'The subscription request could not be submitted.',
    );
  }

  const statusLabel = !data?.requires_subscription
    ? 'Not required'
    : data.has_active_subscription
      ? 'Active'
      : data.pending
        ? 'Pending'
        : 'Needs renewal';

  return (
    <Screen>
      <PageHeader title="Subscription" subtitle="Request access and review request history" back />

      {notice ? (
        <Card>
          <InfoRow label="Result" value={notice} />
        </Card>
      ) : null}

      {resource.loading && !data ? <LoadingState label="Loading subscription…" /> : null}
      {resource.error ? (
        <ErrorState message={resource.error.message} onRetry={() => void resource.reload()} />
      ) : null}

      {data ? (
        <>
          <Section title="Current status">
            <Card>
              <Badge
                label={statusLabel}
                tone={statusTone(data.has_active_subscription ? 'active' : data.pending ? 'pending' : 'expired')}
              />
              <View style={{ height: 12 }} />
              <InfoRow label="Requires subscription" value={data.requires_subscription ? 'Yes' : 'No'} />
              <InfoRow label="Plan" value={data.active?.type_label ?? '—'} />
              <InfoRow label="Price" value={data.active ? formatMoney(data.active.price, data.active.currency) : '—'} />
              <InfoRow label="Active until" value={formatDate(data.active?.expires_at)} />
              <InfoRow
                label="Days remaining"
                value={
                  data.active?.days_remaining === null || data.active?.days_remaining === undefined
                    ? '—'
                    : String(data.active.days_remaining)
                }
              />
              <InfoRow label="Pending request" value={data.pending ? 'Yes' : 'No'} />
              {!data.requires_subscription ? (
                <InfoRow label="Note" value="Your role does not require a subscription." />
              ) : null}
            </Card>
          </Section>

          <Section title="Request subscription">
            <Card>
              {!data.requires_subscription ? (
                <InfoRow label="Status" value="No subscription request is needed for your role." />
              ) : data.has_active_subscription ? (
                <InfoRow label="Status" value="Your subscription is active. You can request renewal when it expires." />
              ) : (
                <>
                  <InfoRow
                    label="Status"
                    value={data.pending ? 'A renewal request is already pending.' : 'Submit a request for administrator approval.'}
                  />
                  <View style={{ height: 12 }} />
                  <SelectChips
                    options={planOptions}
                    value={type}
                    onChange={(value) => setType(value ?? 'monthly')}
                  />
                  <View style={{ height: 12 }} />
                  <Button
                    label={data.pending ? 'Request already pending' : 'Request subscription'}
                    disabled={data.pending || plans.loading || planOptions.length === 0}
                    loading={request.pending}
                    onPress={() => void submitRequest()}
                  />
                  {request.error ? <ErrorState message={request.error.message} /> : null}
                </>
              )}
            </Card>
          </Section>

          <Section title="Request history">
            <Card>
              {data.history.length ? (
                data.history.map((item) => (
                  <ListRow
                    key={String(item.id)}
                    title={item.type_label}
                    subtitle={`${formatMoney(item.price, item.currency)} · ${formatDate(item.starts_at ?? item.created_at)} → ${formatDate(item.expires_at)}`}
                    trailing={<Badge label={humanize(item.status)} tone={statusTone(item.status)} />}
                  />
                ))
              ) : (
                <EmptyState title="No subscription requests yet" message="Requests you submit will appear here." />
              )}
            </Card>
          </Section>
        </>
      ) : null}
    </Screen>
  );
}
