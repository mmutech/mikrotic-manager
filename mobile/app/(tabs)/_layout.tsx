import { Tabs } from 'expo-router';
import { House, Package, Router, Ticket, UserRound, type LucideIcon } from 'lucide-react-native';

import { useAuth } from '@/src/auth/AuthContext';
import { roleLabel } from '@/src/auth/permissions';
import { theme } from '@/src/theme';

const ICONS: Record<string, LucideIcon> = {
  index: House,
  routers: Router,
  vouchers: Ticket,
  plans: Package,
  account: UserRound,
};

type TabIconProps = {
  focused?: boolean;
  color?: unknown;
  size: number;
};

function tabIcon(name: string) {
  return ({ color, size }: TabIconProps) => {
    const Icon = ICONS[name];

    return <Icon size={size} color={typeof color === 'string' ? color : theme.colors.muted} />;
  };
}

export default function TabLayout() {
  const { user } = useAuth();

  return (
    <Tabs
      screenOptions={{
        headerShown: false,
        tabBarActiveTintColor: theme.colors.primary,
        tabBarInactiveTintColor: theme.colors.muted,
        tabBarStyle: { backgroundColor: '#FFFFFF', borderTopColor: theme.colors.border },
      }}>
      <Tabs.Screen
        name="index"
        options={{
          title: 'Home',
          tabBarIcon: tabIcon('index'),
        }}
      />
      <Tabs.Screen
        name="routers"
        options={{
          title: 'Routers',
          tabBarIcon: tabIcon('routers'),
        }}
      />
      <Tabs.Screen
        name="vouchers"
        options={{
          title: 'Vouchers',
          tabBarIcon: tabIcon('vouchers'),
        }}
      />
      <Tabs.Screen
        name="plans"
        options={{
          title: 'Plans',
          tabBarIcon: tabIcon('plans'),
        }}
      />
      <Tabs.Screen
        name="account"
        options={{
          title: roleLabel(user),
          tabBarIcon: tabIcon('account'),
        }}
      />
    </Tabs>
  );
}
