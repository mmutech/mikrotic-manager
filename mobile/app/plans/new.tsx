import { useRouter } from 'expo-router';

import { PlanForm } from '@/src/components/PlanForm';
import type { Plan } from '@/src/types';

export default function NewPlanScreen() {
  const router = useRouter();

  function onSaved(plan: Plan): void {
    router.replace(`/plans/${plan.public_id ?? plan.id}`);
  }

  return <PlanForm mode="create" onSaved={onSaved} />;
}
