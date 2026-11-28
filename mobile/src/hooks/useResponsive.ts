import { useWindowDimensions } from 'react-native';

export function useResponsive() {
  const { width, height } = useWindowDimensions();
  const isSmall = width < 360;
  const isTablet = width >= 720;

  return {
    width,
    height,
    isSmall,
    isTablet,
    contentPadding: isSmall ? 12 : width < 720 ? 16 : 24,
    contentMaxWidth: isTablet ? 960 : 720,
  };
}
