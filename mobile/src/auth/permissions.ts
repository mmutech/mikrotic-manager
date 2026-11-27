import type { User } from '@/src/types';

export const ROLE_SUPER_ADMIN = 'super-admin';
export const ROLE_NETWORK_ADMIN = 'network-admin';
export const ROLE_SALES_AGENT = 'sales-agent';

export const PERMISSION = {
  routersManage: 'routers.manage',
  routersTest: 'routers.test',
  plansManage: 'plans.manage',
  reportsView: 'reports.view',
} as const;

export function isAdmin(user: User | null | undefined): boolean {
  return user?.roles.includes(ROLE_SUPER_ADMIN) ?? false;
}

export function isNetworkAdmin(user: User | null | undefined): boolean {
  return user?.roles.includes(ROLE_NETWORK_ADMIN) ?? false;
}

export function isSalesAgent(user: User | null | undefined): boolean {
  return user?.roles.includes(ROLE_SALES_AGENT) ?? false;
}

export function hasPermission(user: User | null | undefined, permission: string): boolean {
  if (!user) return false;
  if (isAdmin(user)) return true;

  return user.permissions.includes(permission);
}

export const canManageRouters = (user: User | null | undefined): boolean =>
  hasPermission(user, PERMISSION.routersManage);

export const canTestRouters = (user: User | null | undefined): boolean =>
  hasPermission(user, PERMISSION.routersTest);

export const canManagePlans = (user: User | null | undefined): boolean =>
  hasPermission(user, PERMISSION.plansManage);

export const canViewReports = (user: User | null | undefined): boolean =>
  hasPermission(user, PERMISSION.reportsView);

export const canApproveSubscriptions = (user: User | null | undefined): boolean =>
  isAdmin(user);

export function roleLabel(user: User | null | undefined): string {
  if (isAdmin(user)) return 'Admin';
  if (isNetworkAdmin(user)) return 'Network Admin';
  if (isSalesAgent(user)) return 'Sales Agent';

  return 'Account';
}
