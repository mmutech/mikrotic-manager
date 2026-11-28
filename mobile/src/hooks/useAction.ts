import { useCallback, useState } from 'react';

import { ApiError } from '@/src/api/client';

export function useAction<TArgs extends unknown[], TResult>(
  action: (...args: TArgs) => Promise<TResult>,
) {
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);

  const run = useCallback(
    async (...args: TArgs): Promise<TResult | null> => {
      setPending(true);
      setError(null);

      try {
        return await action(...args);
      } catch (caught) {
        setError(
          caught instanceof ApiError
            ? caught
            : new ApiError(
                0,
                caught instanceof Error && caught.message
                  ? caught.message
                  : 'The action could not be completed.',
              ),
        );
        return null;
      } finally {
        setPending(false);
      }
    },
    [action],
  );

  return { run, pending, error, clearError: () => setError(null) };
}
