export const theme = {
  colors: {
    background: '#F4F6FB',
    surface: '#FFFFFF',
    surfaceMuted: '#F8FAFC',
    text: '#111827',
    muted: '#64748B',
    border: '#E2E8F0',
    primary: '#2563EB',
    primaryDark: '#1D4ED8',
    primarySoft: '#EFF6FF',
    success: '#059669',
    successSoft: '#ECFDF5',
    warning: '#D97706',
    warningSoft: '#FFFBEB',
    danger: '#DC2626',
    dangerSoft: '#FEF2F2',
    white: '#FFFFFF',
  },
  spacing: {
    xxs: 4,
    xs: 6,
    sm: 10,
    md: 14,
    lg: 20,
    xl: 28,
  },
  radius: {
    sm: 8,
    md: 14,
    lg: 20,
  },
} as const;

export type AppColors = keyof typeof theme.colors;
