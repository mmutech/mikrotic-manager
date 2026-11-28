import { useIsFocused } from 'expo-router';
import { useCallback, useEffect, useRef, useState } from 'react';

import { ApiError } from '@/src/api/client';
import { useRefresh } from '@/src/hooks/useRefresh';

interface ResourceState<T> {
  data: T | null;
  loading: boolean;
  error: ApiError | null;
}

export function useResource<T>(loader: () => Promise<T>, deps: unknown[] = []) {
  const loaderRef = useRef(loader);
  loaderRef.current = loader;
  const { register } = useRefresh();
  const focused = useIsFocused();

  const [state, setState] = useState<ResourceState<T>>({
    data: null,
    loading: true,
    error: null,
  });

  const reload = useCallback(async () => {
    setState((current) => ({ ...current, loading: true, error: null }));

    try {
      const data = await loaderRef.current();
      setState({ data, loading: false, error: null });
      return data;
    } catch (error) {
      setState({
        data: null,
        loading: false,
        error: error instanceof ApiError ? error : new ApiError(0, 'Unable to load this screen.'),
      });
      return null;
    }
    // The caller controls refresh dependencies explicitly.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps);

  useEffect(() => {
    if (!focused) {
      return;
    }

    return register(reload);
  }, [focused, reload, register]);

  useEffect(() => {
    void reload();
  }, [reload]);

  return { ...state, reload };
}
