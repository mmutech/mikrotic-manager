import { useRouter } from 'expo-router';
import { StyleSheet, Text, View } from 'react-native';
import { useEffect, useState } from 'react';

import { api } from '@/src/api/endpoints';
import { useAuth } from '@/src/auth/AuthContext';
import { canApproveSubscriptions } from '@/src/auth/permissions';
import {
  Badge,
  Button,
  Card,
  ConfirmModal,
  EmptyState,
  ErrorState,
  InfoRow,
  LoadingState,
  PageHeader,
  Screen,
  Section,
  SelectChips,
} from '@/src/components/ui';
import { useAction } from '@/src/hooks/useAction';
import { useResource } from '@/src/hooks/useResource';
import { formatDate, formatMoney, statusTone } from '@/src/format';
import { theme } from '@/src/theme';
import type { Identifier, SubscriptionRequest } from '@/src/types';

const STATUS_OPTIONS = [
  { label: 'Pending', value: 'pending' },
  { label: 'Approved', value: 'approved' },
  { label: 'Rejected', value: 'rejected' },
];

export default function SubscriptionRequestsScreen() {
  const router = useRouter();
  const { user } = useAuth();
  const allowed = canApproveSubscriptions(user);
  const [status, setStatus] = useState('pending');
  const [notice, setNotice] = useState<string | null>(null);
  const [confirmation, setConfirmation] = useState<{
    kind: 'approve' | 'reject';
    item: SubscriptionRequest;
  } | null>(null);

  useEffect(() => {
    if (!allowed) router.replace('/(tabs)/account');
  }, [allowed, router]);

  const resource = useResource(() => api.subscription.requests({ status, per_page: 50 }), [status]);

  const approve = useAction(async (id: Identifier) => {
    const result = await api.subscription.approve(id);
    await resource.reload();
    return result;
  });

  const reject = useAction(async (id: Identifier) => {
    const result = await api.subscription.reject(id);
    await resource.reload();
    return result;
  });

  async function runConfirmed(): Promise<void> {
    if (!confirmation) return;

    const { kind, item } = confirmation;
    const result = kind === 'approve'
      ? await approve.run(item.id)
      : await reject.run(item.id);

    setConfirmation(null);
    setNotice(
      result
        ? `${item.name}'s request was ${kind === 'approve' ? 'approved' : 'rejected'}.`
        : `The ${kind} action could not be completed.`,
    );
  }

  if (!allowed) return null;

  const busy = approve.pending || reject.pending;

  return (
    <Screen>
      <PageHeader
        title="Subscription requests"
        subtitle="Review, approve, or reject access requests"
        back
      />

      {notice ? (
        <Card>
          <InfoRow label="Result" value={notice} />
        </Card>
      ) : null}

      <SelectChips
        options={STATUS_OPTIONS}
        value={status}
        onChange={(value) => setStatus(value ?? 'pending')}
      />

      {resource.loading && !resource.data ? <LoadingState label="Loading requests…" /> : null}
      {resource.error ? (
        <ErrorState message={resource.error.message} onRetry={() => void resource.reload()} />
      ) : null}

      <Section title={`${status[0].toUpperCase()}${status.slice(1)} requests`}>
        <Card>
          {resource.data?.items.length ? (
            resource.data.items.map((item) => (
              <RequestRow
                key={String(item.id)}
                item={item}
                busy={busy}
                showActions={status === 'pending'}
                onApprove={() => setConfirmation({ kind: 'approve', item })}
                onReject={() => setConfirmation({ kind: 'reject', item })}
              />
            ))
          ) : resource.data ? (
            <EmptyState
              title={`No ${status} requests`}
              message="Requests submitted by network administrators or sales agents will appear here."
            />
          ) : null}
        </Card>
      </Section>

      <ConfirmModal
        visible={confirmation !== null}
        title={confirmation?.kind === 'reject' ? 'Reject request?' : 'Approve request?'}
        message={
          confirmation?.kind === 'reject'
            ? `The pending request from ${confirmation.item.name} will be marked rejected. They can submit a new request afterward.`
            : `This approves ${confirmation?.item.name ?? 'the request'} with a ${confirmation?.item.subscription.type_label ?? 'monthly'} subscription.`
        }
        confirmLabel={confirmation?.kind === 'reject' ? 'Reject' : 'Approve'}
        destructive={confirmation?.kind === 'reject'}
        loading={busy}
        onCancel={() => setConfirmation(null)}
        onConfirm={() => void runConfirmed()}
      />
    </Screen>
  );
}

function RequestRow({
  item,
  busy,
  showActions,
  onApprove,
  onReject,
}: {
  item: SubscriptionRequest;
  busy: boolean;
  showActions: boolean;
  onApprove: () => void;
  onReject: () => void;
}) {
  return (
    <View style={styles.row}>
      <View style={styles.rowHeading}>
        <View style={styles.rowText}>
          <Text style={styles.rowTitle}>{item.name}</Text>
          <Text style={styles.rowSubtitle}>{item.email}</Text>
        </View>
        <Badge label={item.subscription.status} tone={statusTone(item.subscription.status)} />
      </View>
      <InfoRow label="Requested" value={formatDate(item.subscription.created_at)} />
      <InfoRow label="Plan" value={item.subscription.type_label} />
      <InfoRow label="Duration" value={`${item.subscription.duration_days} days`} />
      <InfoRow label="Price" value={formatMoney(item.subscription.price, item.subscription.currency)} />
      {showActions ? (
        <View style={styles.actions}>
          <Button label="Approve" compact loading={busy} disabled={busy} onPress={onApprove} />
          <Button
            label="Reject"
            compact
            variant="danger"
            loading={busy}
            disabled={busy}
            onPress={onReject}
          />
        </View>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  row: {
    paddingVertical: theme.spacing.sm,
    borderBottomWidth: 1,
    borderBottomColor: theme.colors.border,
  },
  rowHeading: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    justifyContent: 'space-between',
    gap: theme.spacing.sm,
    marginBottom: theme.spacing.sm,
  },
  rowText: {
    flex: 1,
    gap: 2,
  },
  rowTitle: {
    fontSize: 15,
    fontWeight: '700',
    color: theme.colors.text,
  },
  rowSubtitle: {
    fontSize: 12,
    color: theme.colors.muted,
  },
  actions: {
    flexDirection: 'row',
    gap: theme.spacing.sm,
    marginTop: theme.spacing.sm,
  },
});
