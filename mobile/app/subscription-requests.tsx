import { useRouter } from 'expo-router';
import { StyleSheet, Text, View, type StyleProp, type ViewStyle } from 'react-native';
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
  InfoRow,
  PageHeader,
  Screen,
  SelectChips,
} from '@/src/components/ui';
import { PaginatedList } from '@/src/components/PaginatedList';
import { useAction } from '@/src/hooks/useAction';
import { usePaginatedResource } from '@/src/hooks/usePaginatedResource';
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

  const list = usePaginatedResource(
    (params) => api.subscription.requests({ status, ...params }),
    [status],
    { perPage: 10, getKey: (item) => item.id },
  );

  const approve = useAction(async (id: Identifier) => {
    const result = await api.subscription.approve(id);
    await list.reload();
    return result;
  });

  const reject = useAction(async (id: Identifier) => {
    const result = await api.subscription.reject(id);
    await list.reload();
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
    <Screen
      scroll={false}
      contentStyle={{ flex: 1, paddingBottom: 0 }}
      header={
        <PageHeader
          title="Subscription requests"
          subtitle="Review, approve, or reject access requests"
          back
        />
      }>
      <PaginatedList
        items={list.items}
        keyExtractor={(item) => String(item.id)}
        renderItem={({ item, index }) => (
          <RequestRow
            item={item}
            busy={busy}
            showActions={status === 'pending'}
            onApprove={() => setConfirmation({ kind: 'approve', item })}
            onReject={() => setConfirmation({ kind: 'reject', item })}
            style={[
              styles.cardRow,
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
        loadingLabel="Loading requests…"
        ListEmptyComponent={
          <EmptyState
            title={`No ${status} requests`}
            message="Requests submitted by network administrators or sales agents will appear here."
          />
        }
        ListHeaderComponent={
          <>
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

            <View style={styles.listHeading}>
              <Text style={styles.listHeadingText}>{`${status[0].toUpperCase()}${status.slice(1)} requests`}</Text>
            </View>
          </>
        }
      />

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
  style,
}: {
  item: SubscriptionRequest;
  busy: boolean;
  showActions: boolean;
  onApprove: () => void;
  onReject: () => void;
  style?: StyleProp<ViewStyle>;
}) {
  return (
    <View style={[styles.row, style]}>
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
  listHeading: {
    marginTop: theme.spacing.lg,
    marginBottom: theme.spacing.sm,
  },
  listHeadingText: {
    fontSize: 16,
    fontWeight: '700',
    color: theme.colors.text,
  },
  cardRow: {
    backgroundColor: theme.colors.surface,
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
  row: {
    paddingVertical: theme.spacing.sm,
    paddingHorizontal: theme.spacing.md,
    borderBottomWidth: 1,
    borderBottomColor: theme.colors.border,
  },
  rowHeading: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    alignItems: 'flex-start',
    justifyContent: 'space-between',
    gap: theme.spacing.sm,
    marginBottom: theme.spacing.sm,
  },
  rowText: {
    flex: 1,
    minWidth: 0,
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
    flexWrap: 'wrap',
    gap: theme.spacing.sm,
    marginTop: theme.spacing.sm,
  },
});
