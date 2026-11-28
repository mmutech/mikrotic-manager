import { useRouter } from 'expo-router';
import { Plus, Printer } from 'lucide-react-native';
import { Pressable, StyleSheet, Text, View, type StyleProp, type ViewStyle } from 'react-native';
import { useEffect, useMemo, useState } from 'react';

import { api } from '@/src/api/endpoints';
import {
  Badge,
  Button,
  Card,
  ConfirmModal,
  EmptyState,
  InfoRow,
  PageHeader,
  Screen,
  Section,
  SelectChips,
  StatTile,
  TextField,
} from '@/src/components/ui';
import { PaginatedList } from '@/src/components/PaginatedList';
import { useAction } from '@/src/hooks/useAction';
import { usePaginatedResource } from '@/src/hooks/usePaginatedResource';
import { useResource } from '@/src/hooks/useResource';
import { formatBytes, statusTone } from '@/src/format';
import { printVouchers } from '@/src/print/vouchers';
import { theme } from '@/src/theme';
import type { Identifier, Voucher } from '@/src/types';

const LIFECYCLE_OPTIONS = [
  { label: 'All', value: '' },
  { label: 'Pending', value: 'pending' },
  { label: 'Active', value: 'active' },
  { label: 'Exhausted', value: 'exhausted' },
  { label: 'Expired', value: 'expired' },
  { label: 'Revoked', value: 'revoked' },
];

function voucherIdentifier(voucher: Voucher): Identifier {
  return voucher.public_id ?? String(voucher.id);
}

function canProvision(voucher: Voucher): boolean {
  return (
    voucher.actions?.provision !== false &&
    voucher.can_be_provisioned &&
    ['pending', 'failed'].includes(voucher.provisioning_status ?? '')
  );
}

function canRevoke(voucher: Voucher): boolean {
  return (
    voucher.actions?.revoke !== false &&
    voucher.lifecycle_status !== 'revoked' &&
    !['disabled', 'removed', 'disabling'].includes(voucher.provisioning_status ?? '')
  );
}

export default function VouchersScreen() {
  const router = useRouter();
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [lifecycle, setLifecycle] = useState('');
  const [selectedItems, setSelectedItems] = useState<Voucher[]>([]);
  const [notice, setNotice] = useState<string | null>(null);
  const [confirmation, setConfirmation] = useState<{
    kind: 'provision' | 'revoke';
    ids: Identifier[];
    count: number;
    skipped: number;
  } | null>(null);

  const list = usePaginatedResource(
    (params) => api.vouchers.list({
      search: search || undefined,
      lifecycle: lifecycle || undefined,
      ...params,
    }),
    [search, lifecycle],
    { perPage: 10, getKey: voucherIdentifier },
  );
  const stats = useResource(() => api.vouchers.stats(), []);

  useEffect(() => {
    const timeout = setTimeout(() => setSearch(searchInput.trim()), 250);

    return () => clearTimeout(timeout);
  }, [searchInput]);

  useEffect(() => {
    setSelectedItems([]);
    setNotice(null);
  }, [search, lifecycle]);

  const selectedSet = useMemo(
    () => new Set(selectedItems.map((item) => String(voucherIdentifier(item)))),
    [selectedItems],
  );
  const printable = useMemo(
    () => selectedItems.filter((voucher) => voucher.actions?.print !== false),
    [selectedItems],
  );
  const provisionable = useMemo(() => selectedItems.filter(canProvision), [selectedItems]);
  const revocable = useMemo(() => selectedItems.filter(canRevoke), [selectedItems]);

  const reloadList = useAction(async () => {
    await list.reload();
    await stats.reload();
  });
  const bulkProvision = useAction(async (ids: Identifier[]) => {
    const result = await api.vouchers.provisionBulk(ids);
    await reloadList.run();
    return result;
  });
  const bulkRevoke = useAction(async (ids: Identifier[]) => {
    const result = await api.vouchers.revokeBulk(ids);
    await reloadList.run();
    return result;
  });
  const print = useAction(async (items: Voucher[]) => {
    return await printVouchers(items);
  });

  function printSelected(): void {
    if (printable.length === 0) {
      setNotice('Selected vouchers are not eligible for printing.');
      return;
    }

    void print.run(printable).then((result) => {
      setNotice(result ? result.note ?? `${result.count} voucher(s) printed.` : 'The voucher sheet could not be generated.');
    });
  }

  function toggle(voucher: Voucher): void {
    const id = voucherIdentifier(voucher);

    setSelectedItems((current) =>
      current.some((item) => String(voucherIdentifier(item)) === String(id))
        ? current.filter((item) => String(voucherIdentifier(item)) !== String(id))
        : [...current, voucher],
    );
  }

  function confirmBulk(kind: 'provision' | 'revoke'): void {
    const eligible = kind === 'provision' ? provisionable : revocable;
    const skipped = selectedItems.length - eligible.length;

    if (eligible.length === 0) {
      setNotice(`No selected vouchers are eligible for ${kind === 'provision' ? 'provisioning' : 'revocation'}.`);
      return;
    }

    setConfirmation({
      kind,
      ids: eligible.map(voucherIdentifier),
      count: eligible.length,
      skipped,
    });
  }

  async function runConfirmed(): Promise<void> {
    if (!confirmation) return;

    const { kind, ids } = confirmation;
    const action = kind === 'provision' ? bulkProvision : bulkRevoke;
    const result = await action.run(ids);
    setConfirmation(null);

    if (result) {
      setSelectedItems([]);
      setNotice(`${result.queued} queued, ${result.skipped} skipped.`);
    } else {
      setNotice(`The ${kind} request could not be completed.`);
    }
  }

  return (
    <Screen
      scroll={false}
      contentStyle={{ flex: 1, paddingBottom: 0 }}
      header={
        <PageHeader
          back
          title="Vouchers"
          subtitle="Generate, provision, monitor, and revoke HotSpot vouchers"
          action={
            <View style={{ flexDirection: 'row', gap: 6 }}>
              {/* <Button
                label="Printer settings"
                icon={Printer}
                iconOnly
                compact
                onPress={() => router.push('/printer-settings')}
              /> */}
              <Button
                label="Generate"
                icon={Plus}
                iconOnly
                compact
                onPress={() => router.push('/vouchers/generate')}
              />
            </View>
          }
        />
      }>
      <PaginatedList
        items={list.items}
        keyExtractor={(item) => String(voucherIdentifier(item))}
        renderItem={({ item, index }) => (
          <VoucherRow
            voucher={item}
            checked={selectedSet.has(String(voucherIdentifier(item)))}
            onToggle={() => toggle(item)}
            onOpen={() => router.push(`/vouchers/${item.public_id ?? item.id}`)}
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
        loadingLabel="Loading vouchers…"
        ListEmptyComponent={<EmptyState title="No vouchers found" message="Generate vouchers or adjust the filters." />}
        ListHeaderComponent={
          <>
            {notice ? (
              <Card>
                <InfoRow label="Result" value={notice} />
              </Card>
            ) : null}

            {stats.data ? (
              <Section title="Voucher stats">
                <Card>
                  <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 10 }}>
                    <StatTile label="Total" value={stats.data.total} compact />
                    <StatTile label="Active" value={stats.data.active} tone="success" compact />
                    <StatTile label="Pending" value={stats.data.pending} tone="warning" compact />
                    <StatTile label="Failed" value={stats.data.provisioning_failed} tone="danger" compact />
                    <StatTile label="Data used" value={formatBytes(stats.data.data_used_bytes)} compact />
                  </View>
                </Card>
              </Section>
            ) : null}

            <TextField label="Search username" value={searchInput} onChangeText={setSearchInput} autoCapitalize="none" />
            <SelectChips options={LIFECYCLE_OPTIONS} value={lifecycle} onChange={(value) => setLifecycle(value ?? '')} />

            {selectedItems.length > 0 ? (
              <Section title={`${selectedItems.length} selected`}>
                <Card>
                  <View style={{ flexDirection: 'row', gap: 10, flexWrap: 'wrap' }}>
                    <Button label="Provision" compact loading={bulkProvision.pending} disabled={provisionable.length === 0} onPress={() => confirmBulk('provision')} />
                    <Button label="Revoke" compact variant="danger" loading={bulkRevoke.pending} disabled={revocable.length === 0} onPress={() => confirmBulk('revoke')} />
                    <Button label="Print" compact variant="secondary" loading={print.pending} disabled={printable.length === 0} onPress={printSelected} />
                  </View>
                </Card>
              </Section>
            ) : null}

            <View style={styles.listHeading}>
              <Text style={styles.listHeadingText}>Vouchers</Text>
            </View>
          </>
        }
      />

      <ConfirmModal
        visible={confirmation !== null}
        title={confirmation?.kind === 'provision' ? 'Provision selected vouchers?' : 'Revoke selected vouchers?'}
        message={
          confirmation
            ? `${confirmation.count} voucher(s) will be queued for a tracked RouterOS job with read-back verification.${confirmation.skipped > 0 ? ` ${confirmation.skipped} ineligible voucher(s) will be skipped.` : ''}`
            : ''
        }
        confirmLabel={confirmation?.kind === 'provision' ? 'Provision' : 'Revoke'}
        destructive={confirmation?.kind === 'revoke'}
        loading={bulkProvision.pending || bulkRevoke.pending}
        onCancel={() => setConfirmation(null)}
        onConfirm={() => void runConfirmed()}
      />
    </Screen>
  );
}

function VoucherRow({
  voucher,
  checked,
  onToggle,
  onOpen,
  style,
}: {
  voucher: Voucher;
  checked: boolean;
  onToggle: () => void;
  onOpen: () => void;
  style?: StyleProp<ViewStyle>;
}) {
  return (
    <View style={[styles.row, style]}>
      <Pressable
        onPress={onToggle}
        accessibilityRole="checkbox"
        accessibilityState={{ checked }}
        style={[styles.checkbox, checked ? styles.checkboxChecked : null]}>
        {checked ? <Text style={styles.checkboxMark}>✓</Text> : null}
      </Pressable>

      <Pressable onPress={onOpen} style={styles.rowBody}>
        <View style={styles.rowHeading}>
          <Text style={styles.rowTitle}>{voucher.username}</Text>
          <Text style={styles.rowSubtitle}>
            {voucher.plan_name ?? voucher.hotspot_profile ?? 'No plan'} · {formatBytes(voucher.used_bytes ?? 0)} used
          </Text>
        </View>
        <Text style={styles.rowUsage}>
          Connected {voucher.connected_time ?? '—'} · Consumption {voucher.consumption ?? '—'}
        </Text>
        <View style={styles.rowBadges}>
          <Badge
            label={voucher.lifecycle_label ?? voucher.lifecycle_status ?? '—'}
            tone={statusTone(voucher.lifecycle_status)}
          />
          <Badge
            label={voucher.provisioning_label ?? voucher.provisioning_status ?? '—'}
            tone={statusTone(voucher.provisioning_status)}
          />
        </View>
      </Pressable>
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
    borderBottomWidth: 0,
    borderBottomLeftRadius: theme.radius.md,
    borderBottomRightRadius: theme.radius.md,
  },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: theme.spacing.sm,
    paddingVertical: theme.spacing.sm,
    paddingHorizontal: theme.spacing.md,
    borderBottomWidth: 1,
    borderBottomColor: theme.colors.border,
  },
  checkbox: {
    width: 20,
    height: 20,
    borderRadius: theme.radius.sm,
    borderWidth: 1,
    borderColor: theme.colors.border,
    backgroundColor: theme.colors.white,
    alignItems: 'center',
    justifyContent: 'center',
  },
  checkboxChecked: {
    backgroundColor: theme.colors.primary,
    borderColor: theme.colors.primary,
  },
  checkboxMark: {
    color: theme.colors.white,
    fontSize: 13,
    fontWeight: '800',
  },
  rowBody: {
    flex: 1,
    minWidth: 0,
    gap: 4,
  },
  rowHeading: {
    flexDirection: 'row',
    flexWrap: 'nowrap',
    alignItems: 'center',
    gap: 6,
  },
  rowTitle: {
    flex: 1,
    minWidth: 0,
    fontSize: 14,
    fontWeight: '700',
    color: theme.colors.text,
  },
  rowBadges: {
    flexDirection: 'row',
    alignItems: 'center',
    flexWrap: 'wrap',
    gap: 4,
    marginTop: 3,
  },
  rowSubtitle: {
    fontSize: 12,
    color: theme.colors.muted,
  },
  rowUsage: {
    fontSize: 11,
    color: theme.colors.primary,
  },
});
