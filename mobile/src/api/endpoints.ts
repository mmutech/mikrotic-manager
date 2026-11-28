import { apiClient, type QueryParams } from '@/src/api/client';
import type {
  BulkResult,
  HotspotProfile,
  Identifier,
  ListResult,
  LoginResponse,
  Overview,
  Plan,
  RevenueReport,
  RouterHealthCheck,
  RouterJob,
  RouterRecord,
  RouterSummary,
  Subscription,
  SubscriptionOverview,
  SubscriptionPlanOption,
  SubscriptionRequest,
  SubscriptionReport,
  User,
  Voucher,
  VoucherStats,
  VoucherStatsPeriod,
} from '@/src/types';

const segment = (value: Identifier): string => encodeURIComponent(String(value));

export interface PlanInput {
  router_id?: Identifier;
  name: string;
  hotspot_profile: string;
  description?: string | null;
  price: number | string;
  currency: string;
  is_unlimited: boolean;
  data_allowance_mb?: number | null;
  validity_days?: number | null;
  time_allowance_seconds: number | string;
  is_active: boolean;
}

export interface GenerateVouchersInput {
  router_id: Identifier;
  plan_id: Identifier;
  quantity: number;
  prefix?: string;
}

export interface ActivateSubscriptionInput {
  type: string;
}

export interface RequestSubscriptionInput {
  type?: string;
}

export interface UpdateSubscriptionPricesInput {
  monthly: number | string;
  yearly: number | string;
}

export interface RouterDetail extends RouterRecord {
  meta?: {
    can_test?: boolean;
    recent_health_checks?: RouterHealthCheck[];
    pending_jobs?: RouterJob[];
  };
}

export interface SubscriptionUserDetail extends User {
  meta?: {
    history?: Subscription[];
  };
}

export const api = {
  auth: {
    login(input: {
      email: string;
      password: string;
      device_name?: string;
    }): Promise<LoginResponse> {
      return apiClient.data<LoginResponse>('/login', { method: 'POST', body: input });
    },
    me(): Promise<User> {
      return apiClient.data<User>('/me');
    },
    logout(): Promise<void> {
      return apiClient.data<void>('/logout', { method: 'POST' });
    },
    deactivateAccount(): Promise<void> {
      return apiClient.data<void>('/me', { method: 'DELETE' });
    },
  },

  subscription: {
    mine(): Promise<SubscriptionOverview> {
      return apiClient.data<SubscriptionOverview>('/subscription');
    },
    plans(): Promise<SubscriptionPlanOption[]> {
      return apiClient.data<SubscriptionPlanOption[]>('/subscription/plans');
    },
    requestRenewal(input: RequestSubscriptionInput = {}): Promise<{ subscription: Subscription; pending: boolean }> {
      return apiClient.data<{ subscription: Subscription; pending: boolean }>('/subscription/request', {
        method: 'POST',
        body: input,
      });
    },
    index(params?: QueryParams): Promise<ListResult<User>> {
      return apiClient.list<User>('/subscriptions', params);
    },
    requests(params?: QueryParams): Promise<ListResult<SubscriptionRequest>> {
      return apiClient.list<SubscriptionRequest>('/subscriptions/requests', params);
    },
    updatePrices(input: UpdateSubscriptionPricesInput): Promise<SubscriptionPlanOption[]> {
      return apiClient.data<SubscriptionPlanOption[]>('/subscriptions/prices', {
        method: 'PUT',
        body: input,
      });
    },
    approve(id: Identifier): Promise<{ subscription: Subscription }> {
      return apiClient.data<{ subscription: Subscription }>(`/subscriptions/${segment(id)}/approve`, {
        method: 'POST',
      });
    },
    reject(id: Identifier): Promise<{ rejected: number }> {
      return apiClient.data<{ rejected: number }>(`/subscriptions/${segment(id)}/reject`, {
        method: 'POST',
      });
    },
    async show(id: Identifier): Promise<SubscriptionUserDetail> {
      const response = await apiClient.request<User>(`/subscriptions/${segment(id)}`);

      return {
        ...response.data,
        meta: response.meta as SubscriptionUserDetail['meta'],
      };
    },
    activate(
      id: Identifier,
      input: ActivateSubscriptionInput,
    ): Promise<{ subscription: Subscription }> {
      return apiClient.data<{ subscription: Subscription }>(`/subscriptions/${segment(id)}/activate`, {
        method: 'POST',
        body: input,
      });
    },
    deactivate(id: Identifier): Promise<{ deactivated: number }> {
      return apiClient.data<{ deactivated: number }>(`/subscriptions/${segment(id)}/deactivate`, {
        method: 'POST',
      });
    },
  },

  routers: {
    list(params?: QueryParams): Promise<ListResult<RouterRecord>> {
      return apiClient.list<RouterRecord>('/routers', params);
    },
    async show(id: Identifier): Promise<RouterDetail> {
      const response = await apiClient.request<RouterRecord>(`/routers/${segment(id)}`);

      return {
        ...response.data,
        meta: response.meta as RouterDetail['meta'],
      };
    },
    hotspotProfiles(id: Identifier): Promise<{
      router_id: Identifier;
      router_public_id?: string | null;
      profiles: HotspotProfile[];
    }> {
      return apiClient.data(`/routers/${segment(id)}/hotspot-profiles`);
    },
    test(id: Identifier): Promise<RouterJob> {
      return apiClient.data<RouterJob>(`/routers/${segment(id)}/test`, { method: 'POST' });
    },
  },

  plans: {
    list(params?: QueryParams): Promise<ListResult<Plan>> {
      return apiClient.list<Plan>('/plans', params);
    },
    show(id: Identifier): Promise<Plan> {
      return apiClient.data<Plan>(`/plans/${segment(id)}`);
    },
    store(input: PlanInput): Promise<Plan> {
      return apiClient.data<Plan>('/plans', { method: 'POST', body: input });
    },
    update(id: Identifier, input: Partial<PlanInput>): Promise<Plan> {
      return apiClient.data<Plan>(`/plans/${segment(id)}`, { method: 'PATCH', body: input });
    },
    destroy(id: Identifier): Promise<void> {
      return apiClient.data<void>(`/plans/${segment(id)}`, { method: 'DELETE' });
    },
  },

  vouchers: {
    list(params?: QueryParams): Promise<ListResult<Voucher>> {
      return apiClient.list<Voucher>('/vouchers', params);
    },
    stats(period: VoucherStatsPeriod = 'today'): Promise<VoucherStats> {
      return apiClient.data<VoucherStats>('/vouchers/stats', { params: { period } });
    },
    show(id: Identifier): Promise<Voucher> {
      return apiClient.data<Voucher>(`/vouchers/${segment(id)}`);
    },
    generate(input: GenerateVouchersInput): Promise<Voucher[]> {
      return apiClient.data<Voucher[]>('/vouchers/generate', { method: 'POST', body: input });
    },
    update(id: Identifier, notes: string | null): Promise<Voucher> {
      return apiClient.data<Voucher>(`/vouchers/${segment(id)}`, {
        method: 'PATCH',
        body: { notes },
      });
    },
    provision(id: Identifier): Promise<Voucher> {
      return apiClient.data<Voucher>(`/vouchers/${segment(id)}/provision`, { method: 'POST' });
    },
    revoke(id: Identifier): Promise<Voucher> {
      return apiClient.data<Voucher>(`/vouchers/${segment(id)}/revoke`, { method: 'POST' });
    },
    provisionBulk(voucherIds: Identifier[]): Promise<BulkResult> {
      return apiClient.data<BulkResult>('/vouchers/provision', {
        method: 'POST',
        body: { voucher_ids: voucherIds },
      });
    },
    revokeBulk(voucherIds: Identifier[]): Promise<BulkResult> {
      return apiClient.data<BulkResult>('/vouchers/revoke', {
        method: 'POST',
        body: { voucher_ids: voucherIds },
      });
    },
  },

  reports: {
    overview(): Promise<Overview> {
      return apiClient.data<Overview>('/reports');
    },
    vouchers(): Promise<VoucherStats> {
      return apiClient.data<VoucherStats>('/reports/vouchers');
    },
    revenue(): Promise<RevenueReport> {
      return apiClient.data<RevenueReport>('/reports/revenue');
    },
    routers(): Promise<RouterSummary> {
      return apiClient.data<RouterSummary>('/reports/routers');
    },
    routerStatus(): Promise<RouterSummary> {
      return apiClient.data<RouterSummary>('/router-status');
    },
    subscriptions(): Promise<SubscriptionReport> {
      return apiClient.data<SubscriptionReport>('/reports/subscriptions');
    },
  },
};
