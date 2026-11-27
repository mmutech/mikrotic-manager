import { useRouter } from 'expo-router';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { useEffect, useMemo, useState } from 'react';

import { api } from '@/src/api/endpoints';
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
  StatTile,
  TextField,
} from '@/src/components/ui';
import { useAction } from '@/src/hooks/useAction';
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
  const [selected, setSelected] = useState<Identifier[]>([]);
  const [notice, setNotice] = useState<string | null>(null);
  const [confirmation, setConfirmation] = useState<{
    kind: 'provision' | 'revoke';
    ids: Identifier[];
    count: number;
    skipped: number;
  } | null>(null);

  const resource = useResource(
    () => api.vouchers.list({
      search: search || undefined,
      lifecycle: lifecycle || undefined,
      per_page: 50,
    }),
    [search, lifecycle],
  );
  const stats = useResource(() => api.vouchers.stats(), []);

  useEffect(() => {
    const timeout = setTimeout(() => setSearch(searchInput.trim()), 250);

    return () => clearTimeout(timeout);
  }, [searchInput]);

  useEffect(() => {
    setSelected([]);
    setNotice(null);
  }, [search, lifecycle]);

  const selectedSet = useMemo(
    () => new Set(selected.map((item) => String(item))),
    [selected],
  );
  const selectedVouchers = useMemo(
    () => (resource.data?.items ?? []).filter((voucher) => selectedSet.has(String(voucherIdentifier(voucher)))),
    [resource.data, selectedSet],
  );
  const printable = useMemo(
    () => selectedVouchers.filter((voucher) => voucher.actions?.print !== false),
    [selectedVouchers],
  );
  const provisionable = useMemo(() => selectedVouchers.filter(canProvision), [selectedVouchers]);
  const revocable = useMemo(() => selectedVouchers.filter(canRevoke), [selectedVouchers]);

  const bulkProvision = useAction(async (ids: Identifier[]) => {
    const result = await api.vouchers.provisionBulk(ids);
    await resource.reload();
    return result;
  });
  const bulkRevoke = useAction(async (ids: Identifier[]) => {
    const result = await api.vouchers.revokeBulk(ids);
    await resource.reload();
    return result;
  });
  const print = useAction(async (items: Voucher[]) => {
    await printVouchers(items);
  });

  function printSelected(): void {
    if (printable.length === 0) {
      setNotice('Selected vouchers are not eligible for printing.');
      return;
    }

    void print.run(printable).then((result) => {
      if (result === null) {
        setNotice('The voucher sheet could not be generated.');
      }
    });
  }

  function toggle(id: Identifier): void {
    setSelected((current) =>
      current.includes(id) ? current.filter((item) => item !== id) : [...current, id],
    );
  }

  function confirmBulk(kind: 'provision' | 'revoke'): void {
    const eligible = kind === 'provision' ? provisionable : revocable;
    const skipped = selected.length - eligible.length;

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
      setSelected([]);
      setNotice(`${result.queued} queued, ${result.skipped} skipped.`);
    } else {
      setNotice(`The ${kind} request could not be completed.`);
    }
  }

  return (
    <Screen>
      <PageHeader
        title="Vouchers"
        subtitle="Generate, provision, monitor, and revoke HotSpot vouchers"
        action={<Button label="Generate" compact onPress={() => router.push('/vouchers/generate')} />}
      />

      {notice ? (
        <Card>
          <InfoRow label="Result" value={notice} />
        </Card>
      ) : null}

      {stats.data ? (
        <Section title="Voucher stats">
          <Card>
            <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 10 }}>
              <StatTile label="Total" value={stats.data.total} />
              <StatTile label="Active" value={stats.data.active} tone="success" />
              <StatTile label="Pending" value={stats.data.pending} tone="warning" />
              <StatTile label="Failed" value={stats.data.provisioning_failed} tone="danger" />
              <StatTile label="Data used" value={formatBytes(stats.data.data_used_bytes)} />
            </View>
          </Card>
        </Section>
      ) : null}

      <TextField label="Search username" value={searchInput} onChangeText={setSearchInput} autoCapitalize="none" />
      <SelectChips options={LIFECYCLE_OPTIONS} value={lifecycle} onChange={(value) => setLifecycle(value ?? '')} />

      {selected.length > 0 ? (
        <Section title={`${selected.length} selected`}>
          <Card>
            <View style={{ flexDirection: 'row', gap: 10, flexWrap: 'wrap' }}>
              <Button label="Provision" compact loading={bulkProvision.pending} disabled={provisionable.length === 0} onPress={() => confirmBulk('provision')} />
              <Button label="Revoke" compact variant="danger" loading={bulkRevoke.pending} disabled={revocable.length === 0} onPress={() => confirmBulk('revoke')} />
              <Button label="Print" compact variant="secondary" loading={print.pending} disabled={printable.length === 0} onPress={printSelected} />
            </View>
          </Card>
        </Section>
      ) : null}

      {resource.loading && !resource.data ? <LoadingState label="Loading vouchers…" /> : null}
      {resource.error ? <ErrorState message={resource.error.message} onRetry={() => void resource.reload()} /> : null}

      {resource.data ? (
        <Section title="Vouchers">
          <Card>
            {resource.data.items.length === 0 ? (
              <EmptyState title="No vouchers found" message="Generate vouchers or adjust the filters." />
            ) : (
              resource.data.items.map((voucher) => (
                <VoucherRow
                  key={String(voucher.id)}
                  voucher={voucher}
                  checked={selectedSet.has(String(voucherIdentifier(voucher)))}
                  onToggle={() => toggle(voucherIdentifier(voucher))}
                  onOpen={() => router.push(`/vouchers/${voucher.public_id ?? voucher.id}`)}
                />
              ))
            )}
          </Card>
        </Section>
      ) : null}

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
}: {
  voucher: Voucher;
  checked: boolean;
  onToggle: () => void;
  onOpen: () => void;
}) {
  return (
    <View style={styles.row}>
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
        </View>
        <Text style={styles.rowSubtitle}>
          {voucher.plan_name ?? voucher.hotspot_profile ?? 'No plan'} · {formatBytes(voucher.used_bytes ?? 0)} used
        </Text>
        <Text style={styles.rowUsage}>
          Connected {voucher.connected_time ?? '—'} · Consumption {voucher.consumption ?? '—'}
        </Text>
      </Pressable>
    </View>
  );
}

const styles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: theme.spacing.sm,
    paddingVertical: theme.spacing.sm,
    borderBottomWidth: 1,
    borderBottomColor: theme.colors.border,
  },
  checkbox: {
    width: 22,
    height: 22,
    marginTop: 2,
    borderRadius: theme.radius.sm,
    borderWidth: 1,
    borderColor: theme.colors.border,
    backgroundColor: theme.colors.surface,
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
    gap: 4,
  },
  rowHeading: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    justifyContent: 'space-between',
    gap: theme.spacing.sm,
  },
  rowTitle: {
    flex: 1,
    fontSize: 15,
    fontWeight: '700',
    color: theme.colors.text,
  },
  rowBadges: {
    alignItems: 'flex-end',
    gap: 4,
  },
  rowSubtitle: {
    fontSize: 13,
    color: theme.colors.muted,
  },
  rowUsage: {
    fontSize: 12,
    color: theme.colors.primary,
  },
});
