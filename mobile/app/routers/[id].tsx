import { useLocalSearchParams } from 'expo-router';
import { Zap } from 'lucide-react-native';
import { useEffect, useState } from 'react';
import { View } from 'react-native';

import { api } from '@/src/api/endpoints';
import { useAuth } from '@/src/auth/AuthContext';
import { canTestRouters } from '@/src/auth/permissions';
import {
  Badge,
  Button,
  Card,
  ConfirmModal,
  ErrorState,
  InfoRow,
  ListRow,
  LoadingState,
  PageHeader,
  Screen,
  Section,
} from '@/src/components/ui';
import { useAction } from '@/src/hooks/useAction';
import { useResource } from '@/src/hooks/useResource';
import { formatDate, statusTone } from '@/src/format';
import type { HotspotProfile } from '@/src/types';

export default function RouterDetailScreen() {
  const params = useLocalSearchParams<{ id: string }>();
  const id = String(params.id ?? '');
  const { user } = useAuth();
  const resource = useResource(() => api.routers.show(id), [id]);
  const test = useAction(api.routers.test);
  const profiles = useResource(
    () => api.routers.hotspotProfiles(id).then((result) => result.profiles),
    [id],
  );
  const router = resource.data;
  const meta = router?.meta;
  const [notice, setNotice] = useState<string | null>(null);
  const [confirmTestOpen, setConfirmTestOpen] = useState(false);

  useEffect(() => {
    setNotice(null);
    setConfirmTestOpen(false);
  }, [id]);

  useEffect(() => {
    if (test.error) {
      setConfirmTestOpen(false);
      setNotice(test.error.message);
    }
  }, [test.error]);

  async function runTest(): Promise<void> {
    const job = await test.run(id);

    if (job) {
      setConfirmTestOpen(false);
      setNotice(`RouterJob #${job.id} is ${job.status_label ?? job.status}.`);
      await resource.reload();
    }
  }

  return (
    <Screen
      header={
        <PageHeader
          back
          title={router?.name ?? 'Router'}
          subtitle={router?.identity ?? 'RouterOS device'}
          action={
            meta?.can_test && canTestRouters(user) ? (
              <Button label="Test" icon={Zap} iconOnly compact loading={test.pending} onPress={() => setConfirmTestOpen(true)} />
            ) : null
          }
        />
      }>
      {notice ? (
        <Card>
          <InfoRow label="Result" value={notice} />
        </Card>
      ) : null}

      {resource.loading && !router ? <LoadingState label="Loading router…" /> : null}
      {resource.error ? <ErrorState message={resource.error.message} onRetry={() => void resource.reload()} /> : null}

      {router ? (
        <>
          <Card>
            <Badge label={router.status ?? (router.is_online ? 'online' : 'offline')} tone={statusTone(router.status ?? (router.is_online ? 'online' : 'offline'))} />
            <View style={{ height: 12 }} />
            <InfoRow label="Public ID" value={router.public_id ?? '—'} />
            <InfoRow label="Host" value={`${router.host ?? '—'}:${router.rest_port ?? '—'}`} />
            <InfoRow label="Location" value={router.location ?? router.site ?? '—'} />
            <InfoRow label="Latency" value={router.latency_ms === null || router.latency_ms === undefined ? '—' : `${router.latency_ms} ms`} />
            <InfoRow label="Last seen" value={formatDate(router.last_seen_at)} />
            <InfoRow label="Monitoring" value={router.monitoring_enabled ? 'Enabled' : 'Disabled'} />
            <InfoRow label="Failures" value={String(router.consecutive_failures ?? 0)} />
          </Card>

          <Section title="Recent health checks">
            <Card>
              {meta?.recent_health_checks?.length ? (
                meta.recent_health_checks.map((check, index) => (
                  <ListRow
                    key={String(check.id)}
                    title={check.status_label ?? check.status ?? 'Check'}
                    subtitle={`${formatDate(check.checked_at)} · ${check.identity ?? 'no identity'}`}
                    trailing={<Badge label={check.status ?? 'unknown'} tone={statusTone(check.status)} />}
                  />
                ))
              ) : (
                <NoRows text="No health checks recorded yet." />
              )}
            </Card>
          </Section>

          <Section title="Pending jobs">
            <Card>
              {meta?.pending_jobs?.length ? (
                meta.pending_jobs.map((job) => (
                  <ListRow
                    key={String(job.id)}
                    title={job.type.replaceAll('_', ' ')}
                    subtitle={`Attempt ${job.attempts ?? 0}/${job.max_attempts ?? 0} · ${formatDate(job.created_at)}`}
                    trailing={<Badge label={job.status} tone={statusTone(job.status)} />}
                  />
                ))
              ) : (
                <NoRows text="No pending RouterOS jobs." />
              )}
            </Card>
          </Section>

          <Section
            title="HotSpot profiles"
            action={
              <Button
                label="Read profiles"
                compact
                variant="secondary"
                loading={profiles.loading}
                onPress={() => void profiles.reload()}
              />
            }>
            <Card>
              {profiles.error ? <ErrorState message={profiles.error.message} onRetry={() => void profiles.reload()} /> : null}
              {profiles.data?.length ? (
                profiles.data.map((profile: HotspotProfile) => (
                  <ListRow
                    key={profile.name}
                    title={profile.name}
                    subtitle={profile.rate_limit ?? 'No rate limit'}
                    trailing={<Badge label={`${profile.upload_speed_kbps ?? '—'}/${profile.download_speed_kbps ?? '—'} kb`} />}
                  />
                ))
              ) : profiles.data ? (
                <NoRows text="No HotSpot profiles found on this router." />
              ) : null}
            </Card>
          </Section>
        </>
      ) : null}

      <ConfirmModal
        visible={confirmTestOpen}
        title="Queue connection test?"
        message="This creates a tracked RouterJob and performs read-only RouterOS checks."
        confirmLabel="Queue"
        loading={test.pending}
        onCancel={() => setConfirmTestOpen(false)}
        onConfirm={() => void runTest()}
      />
    </Screen>
  );
}

function NoRows({ text }: { text: string }) {
  return <ListRow title={text} subtitle="" />;
}
