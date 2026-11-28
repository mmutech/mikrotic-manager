import { usePathname, useRouter } from 'expo-router';
import { SafeAreaView } from 'react-native-safe-area-context';
import { ChevronLeft, type LucideIcon } from 'lucide-react-native';
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Modal,
  PanResponder,
  Platform,
  Pressable,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Switch,
  Text,
  TextInput,
  View,
  type StyleProp,
  type TextInputProps,
  type ViewStyle,
} from 'react-native';
import { useMemo, useRef, useState, type ReactNode } from 'react';

import { theme } from '@/src/theme';
import { humanize } from '@/src/format';
import { useRefresh } from '@/src/hooks/useRefresh';
import { useResponsive } from '@/src/hooks/useResponsive';

type Tone = 'success' | 'warning' | 'danger' | 'neutral';
type RouterHref = Parameters<ReturnType<typeof useRouter>['push']>[0];

const TAB_ROUTES: Array<{ path: string; href: RouterHref }> = [
  { path: '/', href: '/(tabs)' },
  { path: '/routers', href: '/(tabs)/routers' },
  { path: '/vouchers', href: '/(tabs)/vouchers' },
  { path: '/plans', href: '/(tabs)/plans' },
  { path: '/account', href: '/(tabs)/account' },
];
const SWIPE_EDGE = 40;
const SWIPE_DISTANCE = 60;
const PULL_ZONE = 120;
const PULL_DISTANCE = 80;

export function Screen({
  children,
  header,
  scroll = true,
  contentStyle,
}: {
  children: ReactNode;
  header?: ReactNode;
  scroll?: boolean;
  contentStyle?: StyleProp<ViewStyle>;
}) {
  const router = useRouter();
  const pathname = usePathname();
  const { width, contentPadding, contentMaxWidth } = useResponsive();
  const { refreshing, refreshAll } = useRefresh();
  const scrollY = useRef(0);
  const webPullToRefresh = Platform.OS === 'web';

  const swipeHandlers = useMemo(() => {
    const startsAtEdge = (x: number) => x <= SWIPE_EDGE || x >= width - SWIPE_EDGE;
    const atTop = () => scrollY.current <= 0;

    return PanResponder.create({
      onStartShouldSetPanResponder: () => false,
      onMoveShouldSetPanResponder: (_event, gesture) => {
        const wantsHorizontal =
          startsAtEdge(gesture.x0)
          && Math.abs(gesture.dx) > 12
          && Math.abs(gesture.dx) > Math.abs(gesture.dy) * 1.2;
        const wantsPull =
          webPullToRefresh
          && atTop()
          && gesture.y0 <= PULL_ZONE
          && gesture.dy > 12
          && gesture.dy > Math.abs(gesture.dx) * 1.2;

        return wantsHorizontal || wantsPull;
      },
      onPanResponderTerminationRequest: () => true,
      onPanResponderRelease: (_event, gesture) => {
        const isPull =
          webPullToRefresh
          && atTop()
          && gesture.y0 <= PULL_ZONE
          && gesture.dy >= PULL_DISTANCE
          && gesture.dy > Math.abs(gesture.dx);

        if (isPull) {
          void refreshAll();
          return;
        }

        if (Math.abs(gesture.dx) < SWIPE_DISTANCE || Math.abs(gesture.dx) < Math.abs(gesture.dy)) {
          return;
        }

        const tabIndex = TAB_ROUTES.findIndex(
          (route) =>
            route.path === pathname
            || (route.path === '/' && (pathname === '/index' || pathname === '/(tabs)')),
        );

        if (tabIndex >= 0) {
          const nextIndex =
            gesture.dx < 0
              ? (tabIndex + 1) % TAB_ROUTES.length
              : (tabIndex - 1 + TAB_ROUTES.length) % TAB_ROUTES.length;

          router.push(TAB_ROUTES[nextIndex].href);
          return;
        }

        if (gesture.dx > 0 && gesture.x0 <= SWIPE_EDGE && router.canGoBack()) {
          router.back();
        }
      },
    }).panHandlers;
  }, [pathname, refreshAll, router, webPullToRefresh, width]);

  const content = (
    <View
      style={[
        {
          width: '100%',
          maxWidth: contentMaxWidth,
          alignSelf: 'center',
          backgroundColor: theme.colors.background,
          paddingHorizontal: contentPadding,
          paddingTop: header ? theme.spacing.sm : 0,
          paddingBottom: theme.spacing.xl,
        },
        contentStyle,
      ]}>
      {children}
    </View>
  );
  const refreshControl = (
    <RefreshControl
      refreshing={refreshing}
      onRefresh={() => void refreshAll()}
      tintColor={theme.colors.primary}
      colors={[theme.colors.primary]}
    />
  );

  return (
    <SafeAreaView style={styles.screen} edges={['top', 'left', 'right']} {...swipeHandlers}>
      {header ? (
        <View
          style={[
            styles.fixedHeader,
            {
              width: '100%',
              maxWidth: contentMaxWidth,
              alignSelf: 'center',
              paddingHorizontal: contentPadding,
            },
          ]}>
          {header}
        </View>
      ) : null}
      <KeyboardAvoidingView
        style={styles.keyboardAvoid}
        behavior={Platform.OS === 'ios' ? 'padding' : 'height'}
        enabled={Platform.OS !== 'web'}>
        {scroll ? (
          <ScrollView
            refreshControl={webPullToRefresh ? undefined : refreshControl}
            contentContainerStyle={styles.scrollContent}
            keyboardShouldPersistTaps="handled"
            scrollEventThrottle={16}
            onScroll={(event) => {
              scrollY.current = event.nativeEvent.contentOffset.y;
            }}>
            {content}
          </ScrollView>
        ) : (
          content
        )}
      </KeyboardAvoidingView>
      {webPullToRefresh && refreshing ? (
        <View style={styles.webRefreshOverlay} pointerEvents="none">
          <View style={styles.webRefreshPill}>
            <ActivityIndicator size="small" color={theme.colors.primary} />
          </View>
        </View>
      ) : null}
    </SafeAreaView>
  );
}

export function Avatar({
  name,
  onPress,
  size = 40,
}: {
  name?: string | null;
  onPress?: () => void;
  size?: number;
}) {
  const initials = useMemo(() => {
    const parts = (name ?? '').trim().split(/\s+/).filter(Boolean);

    if (parts.length === 0) return '?';
    if (parts.length === 1) return (parts[0] ?? '').slice(0, 2).toUpperCase();

    return `${(parts[0] ?? '').charAt(0)}${(parts[parts.length - 1] ?? '').charAt(0)}`.toUpperCase();
  }, [name]);

  const content = (
    <View style={[styles.avatar, { width: size, height: size, borderRadius: size / 2 }]}>
      <Text style={styles.avatarText}>{initials}</Text>
    </View>
  );

  if (!onPress) return content;

  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      accessibilityLabel="Open account"
      style={({ pressed }) => [pressed ? styles.avatarPressed : null]}>
      {content}
    </Pressable>
  );
}

export function PageHeader({
  title,
  subtitle,
  action,
  avatar,
  titleSize = 14,
  back = false,
}: {
  title: string;
  subtitle?: string;
  action?: ReactNode;
  avatar?: ReactNode;
  titleSize?: number;
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
          accessibilityLabel={`Back from ${title}`}
          style={styles.backButton}>
          <ChevronLeft size={26} color={theme.colors.primary} />
        </Pressable>
      ) : null}
      {avatar}
      <View style={styles.pageHeaderText}>
        <Text numberOfLines={1} style={[styles.pageTitle, { fontSize: titleSize }]}>
          {title}
        </Text>
        {subtitle ? (
          <Text numberOfLines={1} style={styles.pageSubtitle}>
            {subtitle}
          </Text>
        ) : null}
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
  icon: Icon,
  iconOnly = false,
  variant = 'primary',
  loading = false,
  disabled = false,
  compact = false,
  style,
}: {
  label: string;
  onPress: () => void;
  icon?: LucideIcon;
  iconOnly?: boolean;
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
  const iconColor = variant === 'primary' || variant === 'danger' ? theme.colors.white : theme.colors.primary;

  return (
    <Pressable
      onPress={onPress}
      disabled={disabled || loading}
      accessibilityRole="button"
      accessibilityLabel={label}
      style={({ pressed }) => [
        styles.button,
        compact && styles.buttonCompact,
        variantStyle,
        pressed && !disabled && !loading ? styles.buttonPressed : null,
        disabled || loading ? styles.buttonDisabled : null,
        style,
      ]}>
      {loading ? (
        <ActivityIndicator color={iconColor} size="small" />
      ) : (
        <>
          {Icon ? (
            <Icon
              size={iconOnly ? (compact ? 18 : 20) : compact ? 14 : 16}
              color={iconColor}
              style={iconOnly ? undefined : styles.buttonIcon}
            />
          ) : null}
          {iconOnly ? null : <Text style={labelStyle}>{label}</Text>}
        </>
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
  const { isSmall } = useResponsive();

  return (
    <View style={[styles.infoRow, isSmall ? styles.infoRowStacked : null]}>
      <Text style={[styles.infoLabel, isSmall ? styles.infoLabelStacked : null]}>{label}</Text>
      <View style={[styles.infoValue, isSmall ? styles.infoValueStacked : null]}>
        {typeof value === 'string' ? (
          <Text style={[styles.infoValueText, isSmall ? styles.infoValueTextStacked : null]}>{value}</Text>
        ) : (
          value
        )}
      </View>
    </View>
  );
}

export function StatTile({
  label,
  value,
  caption,
  tone = 'primary',
  compact = false,
}: {
  label: string;
  value: ReactNode;
  caption?: string;
  tone?: 'primary' | 'success' | 'warning' | 'danger';
  compact?: boolean;
}) {
  const toneStyle = {
    primary: styles.statPrimary,
    success: styles.statSuccess,
    warning: styles.statWarning,
    danger: styles.statDanger,
  }[tone];

  return (
    <View style={[styles.statTile, compact && styles.statTileCompact, toneStyle]}>
      <Text style={[styles.statLabel, compact && styles.statLabelCompact]}>{label}</Text>
      <Text style={[styles.statValue, compact && styles.statValueCompact]}>{value}</Text>
      {caption ? <Text style={[styles.statCaption, compact && styles.statCaptionCompact]}>{caption}</Text> : null}
    </View>
  );
}

export function ListRow({
  title,
  subtitle,
  trailing,
  onPress,
  style,
}: {
  title: string;
  subtitle?: string;
  trailing?: ReactNode;
  onPress?: () => void;
  style?: StyleProp<ViewStyle>;
}) {
  const content = (
    <>
      <View style={styles.listRowText}>
        <Text numberOfLines={1} style={styles.listRowTitle}>
          {title}
        </Text>
        {subtitle ? (
          <Text numberOfLines={1} style={styles.listRowSubtitle}>
            {subtitle}
          </Text>
        ) : null}
      </View>
      {trailing ?? <Text style={styles.chevron}>›</Text>}
    </>
  );

  if (!onPress) {
    return <View style={[styles.listRow, style]}>{content}</View>;
  }

  return (
    <Pressable
      onPress={onPress}
      style={({ pressed }) => [styles.listRow, pressed ? styles.listRowPressed : null, style]}>
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
    backgroundColor: theme.colors.white,
  },
  keyboardAvoid: {
    flex: 1,
  },
  fixedHeader: {
    backgroundColor: theme.colors.white,
    zIndex: 2,
  },
  webRefreshOverlay: {
    position: 'absolute',
    top: 8,
    left: 0,
    right: 0,
    alignItems: 'center',
    zIndex: 10,
  },
  webRefreshPill: {
    minWidth: 48,
    paddingVertical: 6,
    paddingHorizontal: 14,
    borderRadius: 999,
    backgroundColor: theme.colors.surface,
    alignItems: 'center',
    justifyContent: 'center',
  },
  scrollContent: {
    flexGrow: 1,
    backgroundColor: theme.colors.background,
  },
  avatar: {
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: theme.colors.primarySoft,
    borderWidth: 1,
    borderColor: theme.colors.border,
  },
  avatarText: {
    fontSize: 14,
    fontWeight: '800',
    color: theme.colors.primary,
  },
  avatarPressed: {
    opacity: 0.7,
  },
  pageHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: theme.spacing.xxs,
    paddingTop: theme.spacing.sm,
    paddingBottom: theme.spacing.xs,
  },
  backButton: {
    alignSelf: 'center',
    marginLeft: -theme.spacing.xs,
  },
  pageHeaderText: {
    flex: 1,
    minWidth: 0,
  },
  pageTitle: {
    fontSize: 14,
    fontWeight: '800',
    color: theme.colors.text,
  },
  pageSubtitle: {
    marginTop: 0.5,
    fontSize: 11,
    color: theme.colors.muted,
  },
  section: {
    marginTop: theme.spacing.lg,
  },
  sectionHeader: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: theme.spacing.xs,
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
  buttonIcon: {
    marginRight: 6,
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
    flexWrap: 'wrap',
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
  infoRowStacked: { flexDirection: 'column', alignItems: 'flex-start', gap: 4 },
  infoLabelStacked: { flex: 0 },
  infoValueStacked: { flex: 0, width: '100%', alignItems: 'flex-start' },
  infoValueTextStacked: { textAlign: 'left' },
  statTile: {
    flex: 1,
    minWidth: 140,
    borderRadius: theme.radius.md,
    padding: theme.spacing.md,
    marginRight: theme.spacing.sm,
    borderWidth: 1,
  },
  statTileCompact: {
    minWidth: 0,
    flexBasis: 140,
    padding: theme.spacing.sm,
    marginRight: 0,
  },
  statLabelCompact: {
    fontSize: 11,
  },
  statValueCompact: {
    fontSize: 18,
    marginTop: 3,
  },
  statCaptionCompact: {
    fontSize: 10,
    marginTop: 2,
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
  listRowText: { flex: 1, minWidth: 0 },
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
