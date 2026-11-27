import { useLocalSearchParams } from 'expo-router';
import { View } from 'react-native';
import { useEffect, useState } from 'react';

import { api } from '@/src/api/endpoints';
import {
  Badge,
  Button,
  Card,
  ConfirmModal,
  ErrorState,
  InfoRow,
  LoadingState,
  PageHeader,
  Screen,
  Section,
  TextField,
} from '@/src/components/ui';
import { useAction } from '@/src/hooks/useAction';
import { useResource } from '@/src/hooks/useResource';
import { formatBytes, formatDate, formatDuration, statusTone } from '@/src/format';
import { printVouchers } from '@/src/print/vouchers';

export default function VoucherDetailScreen() {
  const params = useLocalSearchParams<{ id: string }>();
  const id = String(params.id ?? '');
  const resource = useResource(() => api.vouchers.show(id), [id]);
  const voucher = resource.data;
  const [notes, setNotes] = useState('');
  const [notice, setNotice] = useState<string | null>(null);
  const [confirmRevokeOpen, setConfirmRevokeOpen] = useState(false);

  useEffect(() => {
    setNotes(voucher?.notes ?? '');
  }, [voucher?.notes]);

  const saveNotes = useAction(async () => {
    const updated = await api.vouchers.update(id, notes || null);
    await resource.reload();
    return updated;
  });

  const provision = useAction(async () => {
    const updated = await api.vouchers.provision(id);
    await resource.reload();
    return updated;
  });

  const revoke = useAction(async () => {
    const updated = await api.vouchers.revoke(id);
    await resource.reload();
    return updated;
  });

  const print = useAction(async () => {
    if (voucher) await printVouchers([voucher]);
  });

  async function runRevoke(): Promise<void> {
    const result = await revoke.run();
    setConfirmRevokeOpen(false);
    setNotice(result ? 'Revocation queued.' : 'The voucher could not be queued for revocation.');
  }

  return (
    <Screen>
      <PageHeader
        back
        title={voucher?.username ?? 'Voucher'}
        subtitle={voucher?.public_id ?? 'HotSpot voucher'}
      />

      {notice ? (
        <Card>
          <InfoRow label="Result" value={notice} />
        </Card>
      ) : null}

      {resource.loading && !voucher ? <LoadingState label="Loading voucher…" /> : null}
      {resource.error ? <ErrorState message={resource.error.message} onRetry={() => void resource.reload()} /> : null}

      {voucher ? (
        <>
          <Card>
            <View style={{ flexDirection: 'row', gap: 8 }}>
              <Badge label={voucher.lifecycle_label ?? voucher.lifecycle_status ?? 'unknown'} tone={statusTone(voucher.lifecycle_status)} />
              <Badge label={voucher.provisioning_label ?? voucher.provisioning_status ?? 'unknown'} tone={statusTone(voucher.provisioning_status)} />
            </View>
            <View style={{ height: 12 }} />
            <InfoRow label="Password" value={voucher.password ?? 'Not shown (view generated credentials)'} />
            <InfoRow label="Router" value={voucher.router_name ?? String(voucher.router_id)} />
            <InfoRow label="Plan" value={voucher.plan_name ?? '—'} />
            <InfoRow label="Profile" value={voucher.hotspot_profile ?? '—'} />
            <InfoRow label="Quota" value={voucher.quota_label ?? formatBytes(voucher.data_allowance_bytes)} />
            <InfoRow label="Used" value={formatBytes(voucher.used_bytes)} />
            <InfoRow label="Remaining" value={formatBytes(voucher.remaining_bytes)} />
            <InfoRow label="Time allowance" value={formatDuration(voucher.time_allowance_seconds)} />
            <InfoRow label="Time used" value={formatDuration(voucher.time_used_seconds)} />
            <InfoRow label="Activated" value={formatDate(voucher.activated_at)} />
            <InfoRow label="Expires" value={formatDate(voucher.expires_at)} />
            <InfoRow label="Provisioned" value={formatDate(voucher.provisioned_at)} />
            <InfoRow label="Revoked" value={formatDate(voucher.revoked_at)} />
          </Card>

          <Section title="Live RouterOS usage">
            <Card>
              <InfoRow label="Connected time" value={voucher.connected_time ?? voucher.live_usage?.connected_time ?? '—'} />
              <InfoRow label="Consumption" value={voucher.consumption ?? voucher.live_usage?.consumption ?? '—'} />
              <InfoRow label="Read status" value={voucher.live_usage?.status ?? 'unavailable'} />
            </Card>
          </Section>

          {voucher.provisioning_error ? (
            <Section title="Provisioning error">
              <Card>
                <ErrorState message={voucher.provisioning_error} />
              </Card>
            </Section>
          ) : null}

          <Section title="Notes">
            <Card>
              <TextField
                label="Internal notes"
                value={notes}
                onChangeText={setNotes}
                multiline
                numberOfLines={3}
                placeholder="Optional notes"
              />
              <Button label="Save notes" variant="secondary" compact loading={saveNotes.pending} onPress={() => void saveNotes.run()} />
            </Card>
          </Section>

          <Section title="Actions">
            <Card>
              <Button
                label="Provision voucher"
                loading={provision.pending}
                disabled={!voucher.can_be_provisioned}
                onPress={async () => {
                  const result = await provision.run();
                  setNotice(result ? 'Provisioning queued.' : 'The voucher could not be queued for provisioning.');
                }}
              />
              <View style={{ height: 10 }} />
              <Button
                label="Revoke voucher"
                variant="danger"
                loading={revoke.pending}
                disabled={voucher.provisioning_status === 'disabled' || voucher.provisioning_status === 'removed' || voucher.lifecycle_status === 'revoked'}
                onPress={() => setConfirmRevokeOpen(true)}
              />
              {voucher.actions?.print !== false ? (
                <>
                  <View style={{ height: 10 }} />
                  <Button
                    label="Print voucher"
                    variant="secondary"
                    loading={print.pending}
                    onPress={async () => {
                      const result = await print.run();
                      if (result === null) setNotice('The voucher sheet could not be generated.');
                    }}
                  />
                </>
              ) : null}
            </Card>
          </Section>
        </>
      ) : null}

      <ConfirmModal
        visible={confirmRevokeOpen}
        title="Revoke voucher?"
        message="This queues Disable + disconnect with RouterOS read-back verification."
        confirmLabel="Revoke"
        destructive
        loading={revoke.pending}
        onCancel={() => setConfirmRevokeOpen(false)}
        onConfirm={() => void runRevoke()}
      />
    </Screen>
  );
}
