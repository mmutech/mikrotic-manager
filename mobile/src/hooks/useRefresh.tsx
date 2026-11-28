import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react';

type Reload = () => Promise<unknown>;

interface RefreshContextValue {
  refreshing: boolean;
  refreshAll: () => Promise<void>;
  register: (reload: Reload) => () => void;
}

const RefreshContext = createContext<RefreshContextValue | null>(null);

export function RefreshProvider({ children }: { children: ReactNode }) {
  const reloads = useRef(new Set<Reload>());
  const running = useRef(false);
  const [refreshing, setRefreshing] = useState(false);

  const register = useCallback((reload: Reload) => {
    reloads.current.add(reload);

    return () => {
      reloads.current.delete(reload);
    };
  }, []);

  const refreshAll = useCallback(async () => {
    if (running.current) {
      return;
    }

    running.current = true;
    setRefreshing(true);

    try {
      await Promise.all([...reloads.current].map((reload) => reload()));
    } finally {
      running.current = false;
      setRefreshing(false);
    }
  }, []);

  const value = useMemo(
    () => ({ refreshing, refreshAll, register }),
    [refreshing, refreshAll, register],
  );

  return <RefreshContext.Provider value={value}>{children}</RefreshContext.Provider>;
}

export function useRefresh(): RefreshContextValue {
  const context = useContext(RefreshContext);

  if (!context) {
    throw new Error('useRefresh must be used inside RefreshProvider.');
  }

  return context;
}
