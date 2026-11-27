import { useLocalSearchParams, useRouter } from 'expo-router';

import { api } from '@/src/api/endpoints';
import { PlanForm } from '@/src/components/PlanForm';
import { ErrorState, LoadingState, Screen } from '@/src/components/ui';
import { useResource } from '@/src/hooks/useResource';
import type { Plan } from '@/src/types';

export default function EditPlanScreen() {
  const params = useLocalSearchParams<{ id: string }>();
  const id = String(params.id ?? '');
  const router = useRouter();
  const resource = useResource(() => api.plans.show(id), [id]);

  if (resource.loading && !resource.data) {
    return (
      <Screen>
        <LoadingState label="Loading plan…" />
      </Screen>
    );
  }

  if (resource.error || !resource.data) {
    return (
      <Screen>
        <ErrorState message={resource.error?.message ?? 'Plan not found.'} onRetry={() => void resource.reload()} />
      </Screen>
    );
  }

  function onSaved(_plan: Plan): void {
    router.back();
  }

  return <PlanForm mode="edit" planId={id} initialPlan={resource.data ?? undefined} onSaved={onSaved} />;
}
