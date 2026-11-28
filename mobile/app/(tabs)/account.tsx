import { useRouter } from 'expo-router';
import { View } from 'react-native';
import { useState } from 'react';

import { api } from '@/src/api/endpoints';
import { useAuth } from '@/src/auth/AuthContext';
import { canApproveSubscriptions, roleLabel } from '@/src/auth/permissions';
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

export default function AccountScreen() {
  const router = useRouter();
  const { user, logout, deactivateAccount } = useAuth();
  const isAdmin = canApproveSubscriptions(user);
  const [confirmation, setConfirmation] = useState<'logout' | 'deactivate' | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const subscription = useResource(() => api.subscription.mine(), []);
  const admins = useResource(
    async () => (isAdmin ? api.subscription.index({ per_page: 10 }) : null),
    [isAdmin],
  );

  const signOut = useAction(logout);
  const deactivateAccountAction = useAction(deactivateAccount);

  async function runConfirmed(): Promise<void> {
    if (!confirmation) return;

    const kind = confirmation;

    if (kind === 'logout') {
      const result = await signOut.run();
      setConfirmation(null);

      if (result !== null) {
        router.replace('/login');
      } else {
        setNotice('Sign out failed. Please try again.');
      }
      return;
    }

    const result = await deactivateAccountAction.run();
    setConfirmation(null);

    if (result !== null) {
      router.replace('/login');
    } else {
      setNotice('The account could not be deactivated. Please try again.');
    }
  }

  return (
    <Screen header={<PageHeader back title={roleLabel(user)} subtitle={user?.email ?? 'Signed in'} />}>
      {notice ? (
        <Card>
          <InfoRow label="Result" value={notice} />
        </Card>
      ) : null}

      <Section title="Profile">
        <Card>
          <InfoRow label="Name" value={user?.name ?? '—'} />
          <InfoRow label="Email" value={user?.email ?? '—'} />
          <InfoRow label="Phone" value={user?.phone ?? '—'} />
          <InfoRow label="Status" value={<Badge label={user?.status ?? 'active'} tone={statusTone(user?.status)} />} />
          <InfoRow label="Roles" value={user?.roles.join(', ') || '—'} />
        </Card>
      </Section>

      <Section title="Printing">
        <Card>
          <ListRow
            title="Bluetooth printer"
            subtitle="Select a printer and paper width for voucher printing"
            onPress={() => router.push('/printer-settings')}
          />
        </Card>
      </Section>

      <Section title="Subscription">
        <Card>
          {subscription.loading && !subscription.data ? <LoadingState /> : null}
          {subscription.error ? <ErrorState message={subscription.error.message} onRetry={() => void subscription.reload()} /> : null}
          {subscription.data ? (
            <>
              <ListRow
                title="Manage subscription"
                subtitle="Request renewal and view history"
                onPress={() => router.push('/subscription')}
              />
              <View style={{ height: 12 }} />
              <Badge
                label={subscription.data.has_active_subscription ? 'Active' : subscription.data.pending ? 'Pending' : 'Needs renewal'}
                tone={statusTone(subscription.data.has_active_subscription ? 'active' : subscription.data.pending ? 'pending' : 'expired')}
              />
              <View style={{ height: 12 }} />
              <InfoRow label="Requires subscription" value={subscription.data.requires_subscription ? 'Yes' : 'No'} />
              <InfoRow label="Type" value={subscription.data.active?.type_label ?? '—'} />
              <InfoRow label="Active until" value={formatDate(subscription.data.active?.expires_at)} />
              <InfoRow label="History entries" value={String(subscription.data.history.length)} />
              {subscription.data.history.slice(0, 5).map((item) => (
                <ListRow
                  key={String(item.id)}
                  title={item.plan}
                  subtitle={`${item.status} · ${formatDate(item.expires_at)}`}
                  trailing={<Badge label={item.status} tone={statusTone(item.status)} />}
                />
              ))}
            </>
          ) : null}
        </Card>
      </Section>

      {isAdmin ? (
        <Section title="Subscribers">
          <Card>
            <ListRow
              title="Subscription requests"
              subtitle="Review pending, approved, and rejected requests"
              onPress={() => router.push('/subscription-requests')}
            />
            {admins.loading && !admins.data ? <LoadingState label="Loading subscribers…" /> : null}
            {admins.error ? <ErrorState message={admins.error.message} onRetry={() => void admins.reload()} /> : null}
            {admins.data?.items.length ? (
              admins.data.items.map((admin) => (
                <ListRow
                  key={String(admin.id)}
                  title={admin.name}
                  subtitle={`${admin.email} · ${admin.has_active_subscription ? 'active subscription' : 'needs subscription'}`}
                  trailing={<Badge label={admin.status ?? 'active'} tone={statusTone(admin.status)} />}
                  onPress={() => router.push(`/subscriptions/${admin.id}`)}
                />
              ))
            ) : admins.data ? (
              <ListRow title="No subscribers found." subtitle="" />
            ) : null}
          </Card>
        </Section>
      ) : null}

      <Section title="Session">
        <Card>
          <Button
            label="Sign out"
            variant="secondary"
            loading={signOut.pending}
            disabled={confirmation !== null}
            onPress={() => setConfirmation('logout')}
          />
          {signOut.error ? <ErrorState message={signOut.error.message} /> : null}
          <View style={{ height: 12 }} />
          <Button
            label="Delete Account"
            variant="danger"
            loading={deactivateAccountAction.pending}
            disabled={confirmation !== null}
            onPress={() => setConfirmation('deactivate')}
          />
          {deactivateAccountAction.error ? <ErrorState message={deactivateAccountAction.error.message} /> : null}
          <InfoRow label="Effect" value="Deactivates your account, destroys all tokens, and signs you out of every device." />
        </Card>
      </Section>

      <ConfirmModal
        visible={confirmation !== null}
        title={confirmation === 'deactivate' ? 'Delete account?' : 'Sign out?'}
        message={
          confirmation === 'deactivate'
            ? 'Your account will be deactivated, all of your API tokens will be destroyed, and you will be signed out. An administrator can reactivate it.'
            : 'This destroys your API token and returns you to the sign-in screen.'
        }
        confirmLabel={confirmation === 'deactivate' ? 'Delete account' : 'Sign out'}
        destructive={confirmation === 'deactivate'}
        loading={confirmation === 'deactivate' ? deactivateAccountAction.pending : signOut.pending}
        onCancel={() => setConfirmation(null)}
        onConfirm={() => void runConfirmed()}
      />
    </Screen>
  );
}
