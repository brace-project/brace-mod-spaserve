// Example output generated from a Brace route and its PHP DTO.
import { createApi, type ApiRoute } from '@trunkjs/api-stub';

/** Public user returned by the backend. @php App\Api\Dto\User */
export interface User { id: number; name: string; }

export type ApiRoutes = {
  User: {
    Get: ApiRoute<{ userId: number }, { locale: string }, never, User, 'GET'>;
  };
};

export const routes = {
  'User.Get': ['GET', '/api/users/{userId}'],
} as const;

const baseAPI = createApi<ApiRoutes>(routes);
export type ApiConfig = Parameters<typeof baseAPI.with>[0];
export const createAPI = (config?: ApiConfig) => config ? baseAPI.with(config) : baseAPI;
export const API = baseAPI;
