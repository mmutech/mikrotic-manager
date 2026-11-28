import { useIsFocused } from 'expo-router';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

import { ApiError, type QueryParams } from '@/src/api/client';
import { useRefresh } from '@/src/hooks/useRefresh';
import type { Identifier, ListResult, PaginationMeta } from '@/src/types';

export interface PageParams extends QueryParams {
  page: number;
  per_page: number;
}

interface UsePaginatedOptions<T> {
  perPage?: number;
  getKey?: (item: T) => Identifier;
}

function toNumber(value: unknown): number | null {
  const parsed = Number(value);

  return Number.isFinite(parsed) ? parsed : null;
}

function defaultKey(item: unknown): Identifier {
  if (item && typeof item === 'object') {
    const record = item as Record<string, unknown>;

    if (record.public_id !== null && record.public_id !== undefined) {
      return String(record.public_id);
    }

    if (record.id !== null && record.id !== undefined) {
      return String(record.id);
    }
  }

  return JSON.stringify(item);
}

export function usePaginatedResource<T>(
  loader: (params: PageParams) => Promise<ListResult<T>>,
  deps: unknown[] = [],
  options: UsePaginatedOptions<T> = {},
) {
  const loaderRef = useRef(loader);
  loaderRef.current = loader;
  const getKeyRef = useRef(options.getKey ?? defaultKey);
  getKeyRef.current = options.getKey ?? defaultKey;

  const perPage = options.perPage ?? 10;
  const { register } = useRefresh();
  const focused = useIsFocused();
  const requestIdRef = useRef(0);
  const pageRef = useRef(1);

  const [items, setItems] = useState<T[]>([]);
  const [meta, setMeta] = useState<PaginationMeta>({});
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const [loadMoreError, setLoadMoreError] = useState<ApiError | null>(null);

  const hasMore = useMemo(() => {
    const current = toNumber(meta.current_page);
    const last = toNumber(meta.last_page);

    if (current !== null && last !== null) {
      return current < last;
    }

    const next = meta.links?.next;

    if (next) {
      return true;
    }

    return items.length === perPage;
  }, [items.length, meta.current_page, meta.last_page, meta.links, perPage]);

  const reload = useCallback(async () => {
    const requestId = ++requestIdRef.current;
    pageRef.current = 1;
    setLoading(true);
    setLoadingMore(false);
    setError(null);
    setLoadMoreError(null);

    try {
      const result = await loaderRef.current({ page: 1, per_page: perPage });

      if (requestId !== requestIdRef.current) {
        return null;
      }

      setItems(result.items);
      setMeta(result.meta ?? {});
      setLoading(false);
      return result;
    } catch (caught) {
      if (requestId !== requestIdRef.current) {
        return null;
      }

      setItems([]);
      setMeta({});
      setError(caught instanceof ApiError ? caught : new ApiError(0, 'Unable to load this list.'));
      setLoading(false);
      return null;
    }
    // The caller controls refresh dependencies explicitly.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [perPage, ...deps]);

  const loadMore = useCallback(async () => {
    if (loading || loadingMore || !hasMore) {
      return;
    }

    const nextPage = pageRef.current + 1;
    const requestId = requestIdRef.current;
    setLoadingMore(true);
    setLoadMoreError(null);

    try {
      const result = await loaderRef.current({ page: nextPage, per_page: perPage });

      if (requestId !== requestIdRef.current) {
        return;
      }

      setItems((current) => {
        const seen = new Set(current.map((item) => String(getKeyRef.current(item))));

        return [
          ...current,
          ...result.items.filter((item) => !seen.has(String(getKeyRef.current(item)))),
        ];
      });
      setMeta(result.meta ?? {});
      pageRef.current = toNumber(result.meta?.current_page) ?? nextPage;
      setLoadingMore(false);
    } catch (caught) {
      if (requestId !== requestIdRef.current) {
        return;
      }

      setLoadMoreError(
        caught instanceof ApiError ? caught : new ApiError(0, 'Unable to load more records.'),
      );
      setLoadingMore(false);
    }
  }, [hasMore, loading, loadingMore, perPage]);

  useEffect(() => {
    if (!focused) {
      return;
    }

    return register(reload);
  }, [focused, reload, register]);

  useEffect(() => {
    void reload();
  }, [reload]);

  return {
    items,
    meta,
    loading,
    loadingMore,
    error,
    loadMoreError,
    hasMore,
    reload,
    loadMore,
  };
}
