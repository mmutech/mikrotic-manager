import { useRouter } from 'expo-router';
import {
  ArrowRight,
  CircleCheck,
  Eye,
  EyeOff,
  LockKeyhole,
  Mail,
  ShieldCheck,
  Ticket,
  Wifi,
  Router,
  type LucideIcon,
} from 'lucide-react-native';
import { useState, type ReactNode } from 'react';
import {
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  View,
  type TextInputProps,
} from 'react-native';

import { useAuth } from '@/src/auth/AuthContext';
import { Button, Screen } from '@/src/components/ui';
import { useAction } from '@/src/hooks/useAction';
import { theme } from '@/src/theme';

const FEATURES = [
  { icon: Router, label: 'Routers' },
  { icon: Ticket, label: 'Vouchers' },
  { icon: ShieldCheck, label: 'Access' },
];

export default function LoginScreen() {
  const router = useRouter();
  const { login } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const action = useAction(login);

  const emailError = action.error?.errors.email?.[0] ?? null;
  const passwordError = action.error?.errors.password?.[0] ?? null;
  const generalError =
    action.error && !emailError && !passwordError ? action.error.message : null;

  async function submit(): Promise<void> {
    const user = await action.run(email, password);
    if (user) router.replace('/(tabs)');
  }

  return (
    <Screen contentStyle={styles.screenContent}>
      <View style={styles.hero}>
        <View style={styles.heroOrbLarge} pointerEvents="none" />
        <View style={styles.heroOrbSmall} pointerEvents="none" />

        <View style={styles.brandRow}>
          <View style={styles.logoMark}>
            <Router size={24} color={theme.colors.primary} />
          </View>
          <View style={styles.brandText}>
            <Text style={styles.brandName}>MMU Tech</Text>
            <Text style={styles.brandTagline}>Network operations suite</Text>
          </View>
        </View>

        <Text style={styles.heroTitle}>Welcome back</Text>
        <Text style={styles.heroCopy}>
          Sign in to monitor routers, issue vouchers, and manage subscriber plans.
        </Text>

        <View style={styles.featureRow}>
          {FEATURES.map(({ icon: Icon, label }) => (
            <View key={label} style={styles.featurePill}>
              <Icon size={14} color={theme.colors.white} />
              <Text style={styles.featureLabel}>{label}</Text>
            </View>
          ))}
        </View>
      </View>

      <View style={styles.formArea}>
        <View style={styles.card}>
          <Text style={styles.cardTitle}>Sign in to your account</Text>
          <Text style={styles.cardSubtitle}>Use your work email and password to continue.</Text>

          <LoginField
            label="Email address"
            icon={Mail}
            value={email}
            onChangeText={setEmail}
            autoCapitalize="none"
            autoCorrect={false}
            keyboardType="email-address"
            placeholder="admin@example.com"
            error={emailError}
          />

          <LoginField
            label="Password"
            icon={LockKeyhole}
            value={password}
            onChangeText={setPassword}
            secureTextEntry={!showPassword}
            autoCapitalize="none"
            placeholder="••••••••"
            error={passwordError}
            trailing={
              <Pressable
                onPress={() => setShowPassword((visible) => !visible)}
                accessibilityRole="button"
                accessibilityLabel={showPassword ? 'Hide password' : 'Show password'}
                hitSlop={8}
                style={styles.eyeButton}>
                {showPassword ? (
                  <EyeOff size={18} color={theme.colors.muted} />
                ) : (
                  <Eye size={18} color={theme.colors.muted} />
                )}
              </Pressable>
            }
          />

          {generalError ? <Text style={styles.generalError}>{generalError}</Text> : null}

          <Button
            label="Sign in"
            icon={ArrowRight}
            onPress={submit}
            loading={action.pending}
            disabled={!email || !password}
            style={styles.submitButton}
          />
        </View>

        <View style={styles.assuranceRow}>
          <CircleCheck size={16} color={theme.colors.success} />
          <Text style={styles.assuranceText}>Role-based access · Subscription-aware workspace</Text>
        </View>

        <Text style={styles.version}>MMU Tech Mobile · v1.0.0</Text>
      </View>
    </Screen>
  );
}

function LoginField({
  label,
  icon: Icon,
  error,
  trailing,
  ...inputProps
}: TextInputProps & {
  label: string;
  icon: LucideIcon;
  error?: string | null;
  trailing?: ReactNode;
}) {
  return (
    <View style={styles.field}>
      <Text style={styles.fieldLabel}>{label}</Text>
      <View style={[styles.inputShell, error ? styles.inputError : null]}>
        <Icon size={17} color={theme.colors.muted} style={styles.fieldIcon} />
        <TextInput
          {...inputProps}
          placeholderTextColor={theme.colors.muted}
          style={styles.input}
        />
        {trailing}
      </View>
      {error ? <Text style={styles.fieldError}>{error}</Text> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  screenContent: {
    paddingHorizontal: 0,
    paddingTop: 0,
    paddingBottom: 0,
  },
  hero: {
    width: '100%',
    overflow: 'hidden',
    paddingHorizontal: theme.spacing.xl,
    paddingTop: 44,
    paddingBottom: 76,
    backgroundColor: theme.colors.primary,
    borderBottomLeftRadius: 32,
    borderBottomRightRadius: 32,
  },
  heroOrbLarge: {
    position: 'absolute',
    width: 220,
    height: 220,
    borderRadius: 110,
    backgroundColor: 'rgba(255,255,255,0.08)',
    top: -110,
    right: -70,
  },
  heroOrbSmall: {
    position: 'absolute',
    width: 120,
    height: 120,
    borderRadius: 60,
    backgroundColor: 'rgba(255,255,255,0.06)',
    bottom: -50,
    left: -40,
  },
  brandRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: theme.spacing.sm,
  },
  logoMark: {
    width: 44,
    height: 44,
    borderRadius: 14,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: theme.colors.white,
  },
  brandText: {
    flex: 1,
    minWidth: 0,
  },
  brandName: {
    fontSize: 17,
    fontWeight: '800',
    color: theme.colors.white,
  },
  brandTagline: {
    marginTop: 2,
    fontSize: 11,
    color: 'rgba(255,255,255,0.78)',
  },
  heroTitle: {
    marginTop: 36,
    fontSize: 30,
    fontWeight: '800',
    color: theme.colors.white,
  },
  heroCopy: {
    marginTop: 8,
    maxWidth: 360,
    fontSize: 14,
    lineHeight: 21,
    color: 'rgba(255,255,255,0.82)',
  },
  featureRow: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: theme.spacing.sm,
    marginTop: 22,
  },
  featurePill: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    paddingVertical: 7,
    paddingHorizontal: 11,
    borderRadius: 999,
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,0.28)',
    backgroundColor: 'rgba(255,255,255,0.12)',
  },
  featureLabel: {
    fontSize: 12,
    fontWeight: '700',
    color: theme.colors.white,
  },
  formArea: {
    width: '100%',
    maxWidth: 460,
    alignSelf: 'center',
    paddingHorizontal: theme.spacing.xl,
    paddingBottom: theme.spacing.xl,
  },
  card: {
    marginTop: -40,
    padding: theme.spacing.lg,
    borderRadius: theme.radius.lg,
    borderWidth: 1,
    borderColor: theme.colors.border,
    backgroundColor: theme.colors.surface,
    shadowColor: '#0F172A',
    shadowOpacity: 0.08,
    shadowRadius: 16,
    shadowOffset: { width: 0, height: 8 },
    elevation: 4,
  },
  cardTitle: {
    fontSize: 18,
    fontWeight: '800',
    color: theme.colors.text,
  },
  cardSubtitle: {
    marginTop: 4,
    marginBottom: theme.spacing.lg,
    fontSize: 13,
    color: theme.colors.muted,
  },
  field: {
    marginBottom: theme.spacing.md,
  },
  fieldLabel: {
    marginBottom: 6,
    fontSize: 13,
    fontWeight: '700',
    color: theme.colors.text,
  },
  inputShell: {
    minHeight: 48,
    flexDirection: 'row',
    alignItems: 'center',
    gap: theme.spacing.sm,
    paddingHorizontal: theme.spacing.md,
    borderRadius: theme.radius.sm,
    borderWidth: 1,
    borderColor: theme.colors.border,
    backgroundColor: theme.colors.surfaceMuted,
  },
  inputError: {
    borderColor: theme.colors.danger,
  },
  fieldIcon: {
    flexShrink: 0,
  },
  input: {
    flex: 1,
    minWidth: 0,
    fontSize: 15,
    color: theme.colors.text,
    paddingVertical: 0,
  },
  eyeButton: {
    padding: 4,
  },
  fieldError: {
    marginTop: 5,
    fontSize: 12,
    color: theme.colors.danger,
  },
  generalError: {
    marginBottom: theme.spacing.md,
    paddingVertical: 10,
    paddingHorizontal: theme.spacing.sm,
    borderRadius: theme.radius.sm,
    backgroundColor: theme.colors.dangerSoft,
    color: theme.colors.danger,
    fontSize: 13,
  },
  submitButton: {
    minHeight: 50,
    borderRadius: theme.radius.sm,
    marginTop: 4,
  },
  assuranceRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 6,
    marginTop: theme.spacing.lg,
  },
  assuranceText: {
    fontSize: 11,
    color: theme.colors.muted,
  },
  version: {
    marginTop: theme.spacing.md,
    textAlign: 'center',
    fontSize: 11,
    color: theme.colors.muted,
  },
});
