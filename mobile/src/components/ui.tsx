import { useRouter } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import {
  ActivityIndicator,
  Modal,
  Pressable,
  ScrollView,
  StyleSheet,
  Switch,
  Text,
  TextInput,
  View,
  type RefreshControlProps,
  type StyleProp,
  type TextInputProps,
  type ViewStyle,
} from 'react-native';
import { useState, type ReactElement, type ReactNode } from 'react';

import { theme } from '@/src/theme';
import { humanize } from '@/src/format';

type Tone = 'success' | 'warning' | 'danger' | 'neutral';

export function Screen({
  children,
  scroll = true,
  refreshControl,
  contentStyle,
}: {
  children: ReactNode;
  scroll?: boolean;
  refreshControl?: ReactElement<RefreshControlProps>;
  contentStyle?: StyleProp<ViewStyle>;
}) {
  const content = <View style={[styles.screenContent, contentStyle]}>{children}</View>;

  return (
    <SafeAreaView style={styles.screen} edges={['top', 'left', 'right']}>
      {scroll ? (
        <ScrollView
          refreshControl={refreshControl}
          contentContainerStyle={styles.scrollContent}
          keyboardShouldPersistTaps="handled">
          {content}
        </ScrollView>
      ) : (
        content
      )}
    </SafeAreaView>
  );
}

export function PageHeader({
  title,
  subtitle,
  action,
  back = false,
}: {
  title: string;
  subtitle?: string;
  action?: ReactNode;
  back?: boolean;
}) {
  const router = useRouter();

  return (
    <View style={styles.pageHeader}>
      {back ? (
        <Pressable
          onPress={() => {
            if (router.canGoBack()) {
              router.back();
            } else {
              router.replace('/');
            }
          }}
          accessibilityRole="button"
          accessibilityLabel="Go back"
          style={styles.backButton}>
          <Text style={styles.backButtonText}>‹ Back</Text>
        </Pressable>
      ) : null}
      <View style={styles.pageHeaderText}>
        <Text style={styles.pageTitle}>{title}</Text>
        {subtitle ? <Text style={styles.pageSubtitle}>{subtitle}</Text> : null}
      </View>
      {action}
    </View>
  );
}

export function Card({ children, style }: { children: ReactNode; style?: StyleProp<ViewStyle> }) {
  return <View style={[styles.card, style]}>{children}</View>;
}

export function Section({
  title,
  action,
  children,
}: {
  title: string;
  action?: ReactNode;
  children: ReactNode;
}) {
  return (
    <View style={styles.section}>
      <View style={styles.sectionHeader}>
        <Text style={styles.sectionTitle}>{title}</Text>
        {action}
      </View>
      {children}
    </View>
  );
}

export function Button({
  label,
  onPress,
  variant = 'primary',
  loading = false,
  disabled = false,
  compact = false,
  style,
}: {
  label: string;
  onPress: () => void;
  variant?: 'primary' | 'secondary' | 'danger' | 'ghost';
  loading?: boolean;
  disabled?: boolean;
  compact?: boolean;
  style?: StyleProp<ViewStyle>;
}) {
  const variantStyle = {
    primary: styles.buttonPrimary,
    secondary: styles.buttonSecondary,
    danger: styles.buttonDanger,
    ghost: styles.buttonGhost,
  }[variant];

  const labelStyle = variant === 'primary' || variant === 'danger' ? styles.buttonLabelLight : styles.buttonLabelDark;

  return (
    <Pressable
      onPress={onPress}
      disabled={disabled || loading}
      style={({ pressed }) => [
        styles.button,
        compact && styles.buttonCompact,
        variantStyle,
        pressed && !disabled && !loading ? styles.buttonPressed : null,
        disabled || loading ? styles.buttonDisabled : null,
        style,
      ]}>
      {loading ? (
        <ActivityIndicator color={variant === 'primary' || variant === 'danger' ? theme.colors.white : theme.colors.primary} size="small" />
      ) : (
        <Text style={labelStyle}>{label}</Text>
      )}
    </Pressable>
  );
}

export function ConfirmModal({
  visible,
  title,
  message,
  confirmLabel = 'Confirm',
  destructive = false,
  loading = false,
  onConfirm,
  onCancel,
}: {
  visible: boolean;
  title: string;
  message: string;
  confirmLabel?: string;
  destructive?: boolean;
  loading?: boolean;
  onConfirm: () => void;
  onCancel: () => void;
}) {
  if (!visible) return null;

  return (
    <Modal transparent animationType="fade" onRequestClose={onCancel} visible={visible}>
      <View style={styles.modalBackdrop}>
        <View style={styles.modalCard}>
          <Text style={styles.modalTitle}>{title}</Text>
          <Text style={styles.modalMessage}>{message}</Text>
          <View style={styles.modalActions}>
            <Button label="Cancel" variant="secondary" compact disabled={loading} onPress={onCancel} />
            <Button
              label={confirmLabel}
              variant={destructive ? 'danger' : 'primary'}
              compact
              loading={loading}
              onPress={onConfirm}
            />
          </View>
        </View>
      </View>
    </Modal>
  );
}

export function SelectField({
  label,
  value,
  options,
  onChange,
  helper,
  error,
}: {
  label: string;
  value: string;
  options: Array<{ label: string; value: string }>;
  onChange: (value: string) => void;
  helper?: string;
  error?: string | null;
}) {
  const [open, setOpen] = useState(false);
  const selected = options.find((option) => option.value === value);

  return (
    <View style={styles.field}>
      <Text style={styles.fieldLabel}>{label}</Text>
      <Pressable
        onPress={() => setOpen(true)}
        accessibilityRole="button"
        accessibilityLabel={label}
        style={[styles.input, styles.selectTrigger, error ? styles.inputError : null]}>
        <Text style={styles.selectValue}>{selected?.label ?? 'Select…'}</Text>
        <Text style={styles.selectChevron}>▾</Text>
      </Pressable>
      {error ? <Text style={styles.fieldError}>{error}</Text> : null}
      {!error && helper ? <Text style={styles.fieldHelper}>{helper}</Text> : null}

      <Modal transparent animationType="fade" visible={open} onRequestClose={() => setOpen(false)}>
        <View style={styles.modalBackdrop}>
          <View style={styles.modalCard}>
            <Text style={styles.modalTitle}>{label}</Text>
            {options.map((option) => {
              const active = option.value === value;

              return (
                <Pressable
                  key={option.value}
                  onPress={() => {
                    onChange(option.value);
                    setOpen(false);
                  }}
                  style={[styles.selectOption, active ? styles.selectOptionSelected : null]}>
                  <Text style={[styles.selectOptionText, active ? styles.selectOptionTextSelected : null]}>
                    {option.label}
                  </Text>
                </Pressable>
              );
            })}
            <View style={styles.modalActions}>
              <Button label="Close" variant="secondary" compact onPress={() => setOpen(false)} />
            </View>
          </View>
        </View>
      </Modal>
    </View>
  );
}

export function TextField({
  label,
  helper,
  error,
  style,
  ...inputProps
}: TextInputProps & { label: string; helper?: string; error?: string | null }) {
  return (
    <View style={styles.field}>
      <Text style={styles.fieldLabel}>{label}</Text>
      <TextInput
        {...inputProps}
        placeholderTextColor={theme.colors.muted}
        style={[styles.input, error ? styles.inputError : null, style]}
      />
      {error ? <Text style={styles.fieldError}>{error}</Text> : null}
      {!error && helper ? <Text style={styles.fieldHelper}>{helper}</Text> : null}
    </View>
  );
}

export function SwitchRow({
  label,
  description,
  value,
  onValueChange,
}: {
  label: string;
  description?: string;
  value: boolean;
  onValueChange: (value: boolean) => void;
}) {
  return (
    <View style={styles.switchRow}>
      <View style={styles.switchText}>
        <Text style={styles.switchLabel}>{label}</Text>
        {description ? <Text style={styles.switchDescription}>{description}</Text> : null}
      </View>
      <Switch value={value} onValueChange={onValueChange} />
    </View>
  );
}

export function Badge({ label, tone }: { label?: string | null; tone?: Tone }) {
  const resolved = tone ?? 'neutral';
  const toneStyle = {
    success: styles.badgeSuccess,
    warning: styles.badgeWarning,
    danger: styles.badgeDanger,
    neutral: styles.badgeNeutral,
  }[resolved];

  return (
    <View style={[styles.badge, toneStyle]}>
      <Text style={[styles.badgeText, toneStyle]}>{label ? humanize(label) : '—'}</Text>
    </View>
  );
}

export function InfoRow({ label, value }: { label: string; value: ReactNode }) {
  return (
    <View style={styles.infoRow}>
      <Text style={styles.infoLabel}>{label}</Text>
      <View style={styles.infoValue}>{typeof value === 'string' ? <Text style={styles.infoValueText}>{value}</Text> : value}</View>
    </View>
  );
}

export function StatTile({
  label,
  value,
  caption,
  tone = 'primary',
}: {
  label: string;
  value: ReactNode;
  caption?: string;
  tone?: 'primary' | 'success' | 'warning' | 'danger';
}) {
  const toneStyle = {
    primary: styles.statPrimary,
    success: styles.statSuccess,
    warning: styles.statWarning,
    danger: styles.statDanger,
  }[tone];

  return (
    <View style={[styles.statTile, toneStyle]}>
      <Text style={styles.statLabel}>{label}</Text>
      <Text style={styles.statValue}>{value}</Text>
      {caption ? <Text style={styles.statCaption}>{caption}</Text> : null}
    </View>
  );
}

export function ListRow({
  title,
  subtitle,
  trailing,
  onPress,
}: {
  title: string;
  subtitle?: string;
  trailing?: ReactNode;
  onPress?: () => void;
}) {
  const content = (
    <>
      <View style={styles.listRowText}>
        <Text style={styles.listRowTitle}>{title}</Text>
        {subtitle ? <Text style={styles.listRowSubtitle}>{subtitle}</Text> : null}
      </View>
      {trailing ?? <Text style={styles.chevron}>›</Text>}
    </>
  );

  if (!onPress) {
    return <View style={styles.listRow}>{content}</View>;
  }

  return (
    <Pressable
      onPress={onPress}
      style={({ pressed }) => [styles.listRow, pressed ? styles.listRowPressed : null]}>
      {content}
    </Pressable>
  );
}

export function LoadingState({ label = 'Loading…' }: { label?: string }) {
  return (
    <View style={styles.state}>
      <ActivityIndicator color={theme.colors.primary} />
      <Text style={styles.stateText}>{label}</Text>
    </View>
  );
}

export function ErrorState({
  message,
  onRetry,
}: {
  message: string;
  onRetry?: () => void;
}) {
  return (
    <Card style={styles.stateCard}>
      <Text style={styles.stateTitle}>Something went wrong</Text>
      <Text style={styles.stateText}>{message}</Text>
      {onRetry ? <Button label="Try again" variant="secondary" compact onPress={onRetry} /> : null}
    </Card>
  );
}

export function EmptyState({ title, message }: { title: string; message?: string }) {
  return (
    <Card style={styles.stateCard}>
      <Text style={styles.stateTitle}>{title}</Text>
      {message ? <Text style={styles.stateText}>{message}</Text> : null}
    </Card>
  );
}

export function SelectChips<T extends string | number>({
  options,
  value,
  onChange,
}: {
  options: Array<{ label: string; value: T }>;
  value: T | null;
  onChange: (value: T | null) => void;
}) {
  return (
    <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.chips}>
      {options.map((option) => {
        const selected = option.value === value;

        return (
          <Pressable
            key={String(option.value)}
            onPress={() => onChange(selected ? null : option.value)}
            style={[styles.chip, selected ? styles.chipSelected : null]}>
            <Text style={[styles.chipText, selected ? styles.chipTextSelected : null]}>{option.label}</Text>
          </Pressable>
        );
      })}
    </ScrollView>
  );
}

export function CheckboxRow({
  label,
  checked,
  onToggle,
  subtitle,
}: {
  label: string;
  checked: boolean;
  onToggle: (checked: boolean) => void;
  subtitle?: string;
}) {
  return (
    <Pressable onPress={() => onToggle(!checked)} style={styles.checkboxRow}>
      <View style={[styles.checkbox, checked ? styles.checkboxChecked : null]}>
        {checked ? <Text style={styles.checkboxMark}>✓</Text> : null}
      </View>
      <View style={styles.checkboxText}>
        <Text style={styles.checkboxLabel}>{label}</Text>
        {subtitle ? <Text style={styles.checkboxSubtitle}>{subtitle}</Text> : null}
      </View>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  screen: {
    flex: 1,
    backgroundColor: theme.colors.background,
  },
  screenContent: {
    width: '100%',
    maxWidth: 760,
    alignSelf: 'center',
    paddingHorizontal: theme.spacing.lg,
    paddingBottom: theme.spacing.xl,
  },
  scrollContent: {
    flexGrow: 1,
  },
  pageHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: theme.spacing.md,
    paddingTop: theme.spacing.lg,
    paddingBottom: theme.spacing.md,
  },
  backButton: {
    paddingVertical: theme.spacing.xs,
    paddingRight: theme.spacing.xs,
  },
  backButtonText: {
    color: theme.colors.primary,
    fontSize: 15,
    fontWeight: '700',
  },
  pageHeaderText: {
    flex: 1,
  },
  pageTitle: {
    fontSize: 26,
    fontWeight: '800',
    color: theme.colors.text,
  },
  pageSubtitle: {
    marginTop: 4,
    fontSize: 13,
    color: theme.colors.muted,
  },
  section: {
    marginTop: theme.spacing.lg,
  },
  sectionHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginBottom: theme.spacing.sm,
  },
  sectionTitle: {
    fontSize: 16,
    fontWeight: '700',
    color: theme.colors.text,
  },
  card: {
    backgroundColor: theme.colors.surface,
    borderRadius: theme.radius.md,
    padding: theme.spacing.md,
    borderWidth: 1,
    borderColor: theme.colors.border,
    marginBottom: theme.spacing.sm,
  },
  button: {
    minHeight: 44,
    paddingHorizontal: theme.spacing.md,
    borderRadius: theme.radius.sm,
    alignItems: 'center',
    justifyContent: 'center',
    flexDirection: 'row',
  },
  buttonCompact: {
    minHeight: 36,
    paddingHorizontal: theme.spacing.sm,
  },
  buttonPrimary: { backgroundColor: theme.colors.primary },
  buttonSecondary: {
    backgroundColor: theme.colors.primarySoft,
    borderWidth: 1,
    borderColor: theme.colors.border,
  },
  buttonDanger: { backgroundColor: theme.colors.danger },
  buttonGhost: { backgroundColor: 'transparent' },
  buttonPressed: { opacity: 0.75 },
  buttonDisabled: { opacity: 0.5 },
  buttonLabelLight: { color: theme.colors.white, fontWeight: '700', fontSize: 14 },
  buttonLabelDark: { color: theme.colors.primary, fontWeight: '700', fontSize: 14 },
  modalBackdrop: {
    flex: 1,
    backgroundColor: 'rgba(15, 23, 42, 0.55)',
    alignItems: 'center',
    justifyContent: 'center',
    padding: theme.spacing.lg,
  },
  modalCard: {
    width: '100%',
    maxWidth: 420,
    backgroundColor: theme.colors.surface,
    borderRadius: theme.radius.lg,
    padding: theme.spacing.lg,
  },
  modalTitle: { fontSize: 18, fontWeight: '800', color: theme.colors.text },
  modalMessage: { marginTop: theme.spacing.sm, fontSize: 14, color: theme.colors.muted, lineHeight: 20 },
  modalActions: {
    marginTop: theme.spacing.lg,
    flexDirection: 'row',
    justifyContent: 'flex-end',
    gap: theme.spacing.sm,
  },
  field: { marginBottom: theme.spacing.md },
  fieldLabel: { fontSize: 13, fontWeight: '700', color: theme.colors.text, marginBottom: 6 },
  fieldHelper: { fontSize: 12, color: theme.colors.muted, marginTop: 5 },
  fieldError: { fontSize: 12, color: theme.colors.danger, marginTop: 5 },
  input: {
    minHeight: 46,
    borderWidth: 1,
    borderColor: theme.colors.border,
    borderRadius: theme.radius.sm,
    backgroundColor: theme.colors.surface,
    paddingHorizontal: theme.spacing.sm,
    color: theme.colors.text,
    fontSize: 15,
  },
  inputError: { borderColor: theme.colors.danger },
  selectTrigger: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
  },
  selectValue: { flex: 1, fontSize: 15, color: theme.colors.text },
  selectChevron: { marginLeft: theme.spacing.sm, fontSize: 14, color: theme.colors.muted },
  selectOption: {
    paddingVertical: 10,
    paddingHorizontal: theme.spacing.sm,
    borderBottomWidth: 1,
    borderBottomColor: theme.colors.border,
  },
  selectOptionSelected: { backgroundColor: theme.colors.primarySoft },
  selectOptionText: { fontSize: 14, color: theme.colors.text },
  selectOptionTextSelected: { color: theme.colors.primary, fontWeight: '700' },
  switchRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: theme.spacing.md,
    paddingVertical: theme.spacing.sm,
  },
  switchText: { flex: 1 },
  switchLabel: { fontSize: 14, fontWeight: '700', color: theme.colors.text },
  switchDescription: { fontSize: 12, color: theme.colors.muted, marginTop: 3 },
  badge: {
    alignSelf: 'flex-start',
    paddingHorizontal: 10,
    paddingVertical: 4,
    borderRadius: 999,
    borderWidth: 1,
  },
  badgeText: { fontSize: 11, fontWeight: '700' },
  badgeSuccess: { color: theme.colors.success, borderColor: theme.colors.success, backgroundColor: theme.colors.successSoft },
  badgeWarning: { color: theme.colors.warning, borderColor: theme.colors.warning, backgroundColor: theme.colors.warningSoft },
  badgeDanger: { color: theme.colors.danger, borderColor: theme.colors.danger, backgroundColor: theme.colors.dangerSoft },
  badgeNeutral: { color: theme.colors.muted, borderColor: theme.colors.border, backgroundColor: theme.colors.surfaceMuted },
  infoRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    gap: theme.spacing.md,
    paddingVertical: 9,
    borderBottomWidth: 1,
    borderBottomColor: theme.colors.border,
  },
  infoLabel: { fontSize: 13, color: theme.colors.muted, flex: 1 },
  infoValue: { flex: 1, alignItems: 'flex-end' },
  infoValueText: { fontSize: 13, fontWeight: '600', color: theme.colors.text, textAlign: 'right' },
  statTile: {
    flex: 1,
    minWidth: 140,
    borderRadius: theme.radius.md,
    padding: theme.spacing.md,
    marginRight: theme.spacing.sm,
    borderWidth: 1,
  },
  statPrimary: { backgroundColor: theme.colors.primarySoft, borderColor: theme.colors.border },
  statSuccess: { backgroundColor: theme.colors.successSoft, borderColor: theme.colors.border },
  statWarning: { backgroundColor: theme.colors.warningSoft, borderColor: theme.colors.border },
  statDanger: { backgroundColor: theme.colors.dangerSoft, borderColor: theme.colors.border },
  statLabel: { fontSize: 12, color: theme.colors.muted, fontWeight: '600' },
  statValue: { fontSize: 22, fontWeight: '800', color: theme.colors.text, marginTop: 5 },
  statCaption: { fontSize: 11, color: theme.colors.muted, marginTop: 3 },
  listRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: theme.spacing.md,
    paddingVertical: 12,
    borderBottomWidth: 1,
    borderBottomColor: theme.colors.border,
  },
  listRowPressed: { backgroundColor: theme.colors.surfaceMuted },
  listRowText: { flex: 1 },
  listRowTitle: { fontSize: 14, fontWeight: '700', color: theme.colors.text },
  listRowSubtitle: { fontSize: 12, color: theme.colors.muted, marginTop: 3 },
  chevron: { fontSize: 24, color: theme.colors.muted },
  state: { padding: theme.spacing.xl, alignItems: 'center', justifyContent: 'center', gap: theme.spacing.sm },
  stateCard: { alignItems: 'flex-start' },
  stateTitle: { fontSize: 15, fontWeight: '700', color: theme.colors.text, marginBottom: 6 },
  stateText: { fontSize: 13, color: theme.colors.muted, marginBottom: theme.spacing.sm },
  chips: { marginBottom: theme.spacing.sm },
  chip: {
    paddingHorizontal: 12,
    paddingVertical: 7,
    borderRadius: 999,
    borderWidth: 1,
    borderColor: theme.colors.border,
    backgroundColor: theme.colors.surface,
    marginRight: 8,
  },
  chipSelected: { backgroundColor: theme.colors.primary, borderColor: theme.colors.primary },
  chipText: { fontSize: 12, fontWeight: '600', color: theme.colors.muted },
  chipTextSelected: { color: theme.colors.white },
  checkboxRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: theme.spacing.sm,
    paddingVertical: 10,
    borderBottomWidth: 1,
    borderBottomColor: theme.colors.border,
  },
  checkbox: {
    width: 22,
    height: 22,
    borderRadius: 6,
    borderWidth: 1,
    borderColor: theme.colors.border,
    alignItems: 'center',
    justifyContent: 'center',
  },
  checkboxChecked: { backgroundColor: theme.colors.primary, borderColor: theme.colors.primary },
  checkboxMark: { color: theme.colors.white, fontSize: 14, fontWeight: '800' },
  checkboxText: { flex: 1 },
  checkboxLabel: { fontSize: 13, fontWeight: '700', color: theme.colors.text },
  checkboxSubtitle: { fontSize: 12, color: theme.colors.muted, marginTop: 2 },
});
