import type { ApiMeta, ListResult } from '@/src/types';

export type QueryValue = string | number | boolean | null | undefined;
export type QueryParams = Record<string, QueryValue>;

export interface ApiRequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  body?: unknown;
  params?: QueryParams;
  headers?: Record<string, string>;
}

export interface ApiResponse<T> {
  data: T;
  message?: string;
  meta?: ApiMeta;
  links?: Record<string, unknown>;
}

export class ApiError extends Error {
  readonly status: number;
  readonly errors: Record<string, string[]>;
  readonly code: string | null;
  readonly payload: unknown;

  constructor(
    status: number,
    message: string,
    errors: Record<string, string[]> = {},
    code: string | null = null,
    payload: unknown = null,
  ) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.errors = errors;
    this.code = code;
    this.payload = payload;
  }
}

function normalizeBaseUrl(): string {
  const raw = (process.env.EXPO_PUBLIC_API_BASE_URL ?? 'http://10.0.2.2:8000/api/v1')
    .trim()
    .replace(/\/+$/, '');

  if (!/^https?:\/\//i.test(raw)) {
    throw new Error('EXPO_PUBLIC_API_BASE_URL must start with http:// or https://');
  }

  return raw.endsWith('/api/v1') ? raw : `${raw}/api/v1`;
}

export const API_BASE_URL = normalizeBaseUrl();

function buildUrl(path: string, params?: QueryParams): string {
  const suffix = path.startsWith('/') ? path : `/${path}`;
  const query = new URLSearchParams();

  Object.entries(params ?? {}).forEach(([key, value]) => {
    if (value === null || value === undefined || value === '') return;
    query.append(key, String(value));
  });

  const search = query.toString();
  return `${API_BASE_URL}${suffix}${search ? `?${search}` : ''}`;
}

function parseErrors(value: unknown): Record<string, string[]> {
  if (!value || typeof value !== 'object') return {};

  return Object.entries(value as Record<string, unknown>).reduce<Record<string, string[]>>(
    (result, [key, messages]) => {
      if (Array.isArray(messages)) {
        result[key] = messages.map(String);
      } else if (typeof messages === 'string') {
        result[key] = [messages];
      }

      return result;
    },
    {},
  );
}

class ApiClient {
  private token: string | null = null;
  private authFailureHandler: ((error: ApiError) => void | Promise<void>) | null = null;

  setToken(token: string | null): void {
    this.token = token;
  }

  getToken(): string | null {
    return this.token;
  }

  setAuthFailureHandler(handler: ((error: ApiError) => void | Promise<void>) | null): void {
    this.authFailureHandler = handler;
  }

  async request<T>(path: string, options: ApiRequestOptions = {}): Promise<ApiResponse<T>> {
    const headers: Record<string, string> = {
      Accept: 'application/json',
      ...options.headers,
    };

    if (this.token) {
      headers.Authorization = `Bearer ${this.token}`;
    }

    if (options.body !== undefined) {
      headers['Content-Type'] = 'application/json';
    }

    let response: Response;

    try {
      response = await fetch(buildUrl(path, options.params), {
        method: options.method ?? 'GET',
        headers,
        body: options.body === undefined ? undefined : JSON.stringify(options.body),
      });
    } catch (error) {
      throw new ApiError(
        0,
        error instanceof Error ? error.message : 'Unable to reach the API.',
        {},
        'network_error',
      );
    }

    let payload: unknown = null;

    try {
      payload = await response.json();
    } catch {
      payload = null;
    }

    if (!response.ok) {
      const record = payload && typeof payload === 'object' ? (payload as Record<string, unknown>) : {};
      const message =
        typeof record.message === 'string' ? record.message : `Request failed (${response.status}).`;
      const error = new ApiError(
        response.status,
        message,
        parseErrors(record.errors),
        typeof record.error === 'string' ? record.error : null,
        payload,
      );

      if (response.status === 401 || error.code === 'account_inactive') {
        await this.authFailureHandler?.(error);
      }

      throw error;
    }

    if (payload && typeof payload === 'object' && 'data' in payload) {
      return payload as ApiResponse<T>;
    }

    return { data: payload as T };
  }

  async data<T>(path: string, options: ApiRequestOptions = {}): Promise<T> {
    const response = await this.request<T>(path, options);
    return response.data;
  }

  async list<T>(path: string, params?: QueryParams): Promise<ListResult<T>> {
    const response = await this.request<T[]>(path, { params });

    return {
      items: Array.isArray(response.data) ? response.data : [],
      meta: response.meta ?? {},
      links: response.links,
    };
  }
}

export const apiClient = new ApiClient();
