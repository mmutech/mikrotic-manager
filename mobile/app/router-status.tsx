import { useRouter } from 'expo-router';
import { View } from 'react-native';
import { useEffect } from 'react';

import { api } from '@/src/api/endpoints';
import { useAuth } from '@/src/auth/AuthContext';
import { canViewReports } from '@/src/auth/permissions';
import { Badge, Card, ErrorState, ListRow, LoadingState, PageHeader, Screen, Section, StatTile } from '@/src/components/ui';
import { useResource } from '@/src/hooks/useResource';
import { formatDate, statusTone } from '@/src/format';

export default function RouterStatusScreen() {
  const router = useRouter();
  const { user } = useAuth();
  const allowed = canViewReports(user);
  const resource = useResource(() => (allowed ? api.reports.routerStatus() : Promise.resolve(null)), [allowed]);
  const report = resource.data;

  useEffect(() => {
    if (!allowed) router.replace('/(tabs)/routers');
  }, [allowed, router]);

  if (!allowed) return null;

  return (
    <Screen>
      <PageHeader title="Router status" subtitle="Visibility-scoped monitoring summary" back />

      {resource.loading && !report ? <LoadingState label="Loading router status…" /> : null}
      {resource.error ? <ErrorState message={resource.error.message} onRetry={() => void resource.reload()} /> : null}

      {report ? (
        <>
          <Section title="Summary">
            <Card>
              <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 10 }}>
                <StatTile label="Total" value={report.total} />
                <StatTile label="Online" value={report.online} tone="success" />
                <StatTile label="Attention" value={report.needs_attention} tone="danger" />
                <StatTile label="Pending jobs" value={report.pending_jobs} tone="warning" />
              </View>
            </Card>
          </Section>

          <Section title="Routers">
            <Card>
              {report.routers?.length ? (
                report.routers.map((item) => (
                  <ListRow
                    key={String(item.id)}
                    title={item.name}
                    subtitle={`${item.identity ?? item.host ?? 'No identity'} · ${formatDate(item.last_seen_at ?? item.last_checked_at)}`}
                    trailing={<Badge label={item.status ?? 'unknown'} tone={statusTone(item.status)} />}
                    onPress={() => router.push(`/routers/${item.public_id ?? item.id}`)}
                  />
                ))
              ) : (
                <ListRow title="No routers are visible to this account." subtitle="" />
              )}
            </Card>
          </Section>

          <Section title="Recent checks">
            <Card>
              {report.recent_checks?.length ? (
                report.recent_checks.map((check) => (
                  <ListRow
                    key={String(check.id)}
                    title={check.status_label ?? check.status ?? 'Check'}
                    subtitle={`${formatDate(check.checked_at)} · ${check.identity ?? 'no identity'}`}
                    trailing={<Badge label={check.status ?? 'unknown'} tone={statusTone(check.status)} />}
                  />
                ))
              ) : (
                <ListRow title="No recent checks." subtitle="" />
              )}
            </Card>
          </Section>
        </>
      ) : null}
    </Screen>
  );
}
