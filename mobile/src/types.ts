export type Identifier = number | string;

export interface User {
  id: Identifier;
  name: string;
  email: string;
  phone?: string | null;
  status?: string | null;
  status_label?: string | null;
  roles: string[];
  permissions: string[];
  requires_subscription: boolean;
  has_active_subscription: boolean;
  active_subscription: Subscription | null;
  must_change_password?: boolean;
  last_login_at?: string | null;
  created_at?: string | null;
}

export interface Subscription {
  id: Identifier;
  user_id: Identifier;
  router_id?: Identifier | null;
  plan: string;
  type: string;
  type_label: string;
  duration_days: number;
  price: number | string;
  status: string;
  starts_at?: string | null;
  expires_at?: string | null;
  days_remaining?: number | null;
  amount: number | string;
  currency: string;
  is_active: boolean;
  metadata?: Record<string, unknown> | null;
  created_at?: string | null;
}

export interface SubscriptionPlanOption {
  value: string;
  label: string;
  days: number;
  price: number | string;
  currency: string;
}

export interface SubscriptionOverview {
  requires_subscription: boolean;
  has_active_subscription: boolean;
  active: Subscription | null;
  pending: boolean;
  history: Subscription[];
}

export interface SubscriptionRequest {
  id: Identifier;
  name: string;
  email: string;
  status?: string | null;
  status_label?: string | null;
  roles: string[];
  has_active_subscription: boolean;
  subscription: Subscription;
}

export interface RouterRecord {
  id: Identifier;
  public_id?: string | null;
  name: string;
  identity?: string | null;
  host?: string | null;
  rest_port?: number | null;
  location?: string | null;
  site?: string | null;
  status?: string | null;
  status_label?: string | null;
  is_online: boolean;
  monitoring_enabled: boolean;
  last_checked_at?: string | null;
  last_seen_at?: string | null;
  latency_ms?: number | null;
  consecutive_failures?: number;
  last_error_category?: string | null;
  metadata?: Record<string, unknown> | null;
  created_at?: string | null;
}

export interface RouterHealthCheck {
  id: Identifier;
  status?: string | null;
  status_label?: string | null;
  latency_ms?: number | null;
  identity?: string | null;
  ros_version?: string | null;
  error_category?: string | null;
  details?: Record<string, unknown> | null;
  checked_at?: string | null;
}

export interface RouterJob {
  id: Identifier;
  uuid?: string | null;
  router_id: Identifier;
  type: string;
  status: string;
  status_label?: string | null;
  attempts?: number;
  max_attempts?: number;
  error_category?: string | null;
  error_message?: string | null;
  correlation_id?: string | null;
  created_at?: string | null;
  finished_at?: string | null;
}

export interface HotspotProfile {
  name: string;
  rate_limit?: string | null;
  shared_users?: string;
  upload_speed_kbps?: number | null;
  download_speed_kbps?: number | null;
}

export interface Plan {
  id: Identifier;
  public_id?: string | null;
  name: string;
  code?: string | null;
  description?: string | null;
  price: number | string;
  currency: string;
  router_id: Identifier;
  router_name?: string | null;
  hotspot_profile?: string | null;
  data_allowance_bytes?: number | null;
  quota_label?: string | null;
  is_unlimited: boolean;
  validity_seconds?: number | null;
  validity_label?: string | null;
  time_allowance_seconds: number | string;
  connected_time_label?: string | null;
  upload_speed_kbps?: number | null;
  download_speed_kbps?: number | null;
  is_active: boolean;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface LiveUsage {
  status: string;
  connected_time: string | null;
  consumption: string | null;
}

export interface Voucher {
  id: Identifier;
  public_id?: string | null;
  username: string;
  password?: string | null;
  router_id: Identifier;
  router_name?: string | null;
  plan_id?: Identifier | null;
  plan_name?: string | null;
  hotspot_profile?: string | null;
  lifecycle_status?: string | null;
  lifecycle_label?: string | null;
  provisioning_status?: string | null;
  provisioning_label?: string | null;
  data_allowance_bytes?: number | null;
  used_bytes?: number | null;
  remaining_bytes?: number | null;
  quota_label?: string | null;
  time_allowance_seconds?: number | string | null;
  time_used_seconds?: number | string | null;
  upload_speed_kbps?: number | null;
  download_speed_kbps?: number | null;
  validity_seconds?: number | null;
  activated_at?: string | null;
  first_used_at?: string | null;
  expires_at?: string | null;
  provisioned_at?: string | null;
  revoked_at?: string | null;
  provisioning_error?: string | null;
  notes?: string | null;
  is_usable: boolean;
  can_be_provisioned: boolean;
  actions?: {
    provision?: boolean;
    revoke?: boolean;
    print?: boolean;
  };
  connected_time?: string | null;
  consumption?: string | null;
  live_usage?: LiveUsage | null;
  created_at?: string | null;
}

export type VoucherStatsPeriod = 'today' | 'week' | 'month' | 'all';

export interface VoucherRevenue {
  period?: VoucherStatsPeriod;
  total_amount: number;
  voucher_count: number;
  by_currency: Array<{ currency: string; vouchers: number; amount: number }>;
}

export interface VoucherStats {
  period?: VoucherStatsPeriod;
  total: number;
  by_lifecycle: Record<string, number>;
  active: number;
  pending: number;
  revoked: number;
  provisioned: number;
  provisioning_failed: number;
  expiring_in_7_days: number;
  data_used_bytes: number;
  connected_seconds: number;
  by_plan: Array<{ plan_id: Identifier; plan_name: string | null; total: number }>;
  recent: Voucher[];
  revenue?: VoucherRevenue;
}

export interface Overview {
  plans: { total: number; active: number };
  vouchers: { total: number; active: number; provisioned: number };
  customers: { total: number };
  purchases: { total: number; fulfilled: number };
  transactions: { total: number; paid: number };
  revenue: RevenueReport;
  routers: RouterSummary;
  subscriptions: {
    total: number;
    active: number;
    expiring_in_7_days: number;
    requires_subscription: boolean;
    mine: { has_active: boolean; active_until: string | null };
  };
}

export interface RouterSummary {
  total: number;
  online: number;
  needs_attention: number;
  monitoring: number;
  pending_jobs: number;
  by_status?: Record<string, number>;
  routers?: RouterRecord[];
  recent_checks?: RouterHealthCheck[];
}

export interface RevenueReport {
  total_amount: number;
  transaction_count: number;
  by_currency: Array<{ currency: string; transactions: number; amount: number }>;
  monthly: Array<{
    period: string;
    transactions: number;
    amount: number;
    currencies: string[];
  }>;
  by_router: Array<{ router_id: Identifier; router_name: string | null; amount: number }>;
  by_plan: Array<{ plan_id: Identifier; plan_name: string | null; amount: number }>;
}

export interface SubscriptionReport {
  total: number;
  by_status: Record<string, number>;
  by_type: Record<string, number>;
  active: number;
  expiring_in_7_days: number;
  network_admins_without_active_subscription: number;
  subscribers_without_active_subscription: number;
  requires_subscription: boolean;
  mine: { has_active: boolean; active_until: string | null };
  recent: Subscription[];
}

export interface BulkResult {
  requested: number;
  queued: number;
  skipped: number;
}

export interface ApiMeta extends Record<string, unknown> {}

export interface PaginationMeta extends ApiMeta {
  current_page?: number;
  last_page?: number;
  per_page?: number;
  total?: number;
  from?: number | null;
  to?: number | null;
  links?: {
    first?: string | null;
    last?: string | null;
    prev?: string | null;
    next?: string | null;
  };
}

export interface ListResult<T> {
  items: T[];
  meta: PaginationMeta;
  links?: Record<string, unknown>;
}

export interface LoginResponse {
  token: string;
  token_type: string;
  abilities: string[];
  user: User;
}
