import type { ReactElement } from 'react';
import {
  ActivityIndicator,
  FlatList,
  Platform,
  RefreshControl,
  StyleSheet,
  Text,
  View,
  type ListRenderItem,
} from 'react-native';

import { ErrorState, LoadingState } from '@/src/components/ui';
import { useRefresh } from '@/src/hooks/useRefresh';
import { theme } from '@/src/theme';

export function PaginatedList<T>({
  items,
  renderItem,
  keyExtractor,
  ListHeaderComponent,
  ListFooterComponent,
  ListEmptyComponent,
  loading,
  loadingMore,
  error,
  loadMoreError,
  hasMore,
  onLoadMore,
  onReload,
  loadingLabel = 'Loading…',
  emptyLabel = 'No records found.',
}: {
  items: T[];
  renderItem: ListRenderItem<T>;
  keyExtractor: (item: T, index: number) => string;
  ListHeaderComponent?: ReactElement;
  ListFooterComponent?: ReactElement;
  ListEmptyComponent?: ReactElement;
  loading: boolean;
  loadingMore: boolean;
  error: Error | null;
  loadMoreError?: Error | null;
  hasMore: boolean;
  onLoadMore: () => void | Promise<unknown>;
  onReload: () => void | Promise<unknown>;
  loadingLabel?: string;
  emptyLabel?: string;
}) {
  const { refreshing, refreshAll } = useRefresh();

  return (
    <FlatList
      data={items}
      renderItem={renderItem}
      keyExtractor={keyExtractor}
      ListHeaderComponent={ListHeaderComponent ?? undefined}
      ListFooterComponent={
        <>
          {ListFooterComponent ?? null}
          {loadingMore ? (
            <View style={styles.footer}>
              <ActivityIndicator size="small" color={theme.colors.primary} />
              <Text style={styles.footerText}>Loading more…</Text>
            </View>
          ) : loadMoreError ? (
            <View style={styles.footer}>
              <ErrorState
                message={loadMoreError.message}
                onRetry={() => void onLoadMore()}
              />
            </View>
          ) : items.length > 0 && !hasMore ? (
            <View style={styles.footer}>
              <Text style={styles.footerText}>End of list</Text>
            </View>
          ) : null}
        </>
      }
      ListEmptyComponent={
        loading ? (
          <LoadingState label={loadingLabel} />
        ) : error ? (
          <ErrorState message={error.message} onRetry={() => void onReload()} />
        ) : (
          ListEmptyComponent ?? <Text style={styles.emptyText}>{emptyLabel}</Text>
        )
      }
      onEndReached={() => {
        if (hasMore && !loading && !loadingMore && !loadMoreError) {
          void onLoadMore();
        }
      }}
      onEndReachedThreshold={0.35}
      refreshControl={
        Platform.OS === 'web' ? undefined : (
          <RefreshControl
            refreshing={refreshing}
            onRefresh={() => void refreshAll()}
            tintColor={theme.colors.primary}
            colors={[theme.colors.primary]}
          />
        )
      }
      style={styles.list}
      contentContainerStyle={styles.content}
      keyboardShouldPersistTaps="handled"
      showsVerticalScrollIndicator={false}
      initialNumToRender={10}
      windowSize={7}
    />
  );
}

const styles = StyleSheet.create({
  list: {
    flex: 1,
  },
  content: {
    paddingBottom: theme.spacing.xl,
  },
  footer: {
    paddingVertical: theme.spacing.md,
    alignItems: 'center',
    gap: theme.spacing.xs,
  },
  footerText: {
    fontSize: 12,
    color: theme.colors.muted,
  },
  emptyText: {
    padding: theme.spacing.lg,
    textAlign: 'center',
    color: theme.colors.muted,
    fontSize: 13,
  },
});
