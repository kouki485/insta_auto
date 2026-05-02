import axios, { AxiosError, type AxiosInstance } from "axios";

const PUBLIC_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8080/api";

/**
 * Sanctum の Bearer Token は localStorage に保存している.
 * SECURITY: 任意の HTML を注入する API (たとえば React の dangerous innerHTML 系)
 * をこのアプリに追加しないこと。XSS が成立した瞬間にトークンが盗まれる.
 * SaaS 化時に HttpOnly Cookie への移行を検討する.
 */
const TOKEN_KEY = "instaauto.token";

export function readToken(): string | null {
  if (typeof window === "undefined") return null;
  return window.localStorage.getItem(TOKEN_KEY);
}

export function writeToken(token: string | null): void {
  if (typeof window === "undefined") return;
  if (token === null) {
    window.localStorage.removeItem(TOKEN_KEY);
  } else {
    window.localStorage.setItem(TOKEN_KEY, token);
  }
}

export const api: AxiosInstance = axios.create({
  baseURL: PUBLIC_BASE_URL,
  headers: { Accept: "application/json" },
  withCredentials: false,
});

api.interceptors.request.use((config) => {
  const token = readToken();
  if (token) {
    config.headers = config.headers ?? {};
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

api.interceptors.response.use(
  (response) => response,
  (error: AxiosError) => {
    if (error.response?.status === 401 && typeof window !== "undefined") {
      writeToken(null);
      if (window.location.pathname !== "/login") {
        window.location.href = "/login";
      }
    }
    return Promise.reject(error);
  },
);

export const fetcher = async <T>(url: string): Promise<T> => {
  const response = await api.get<T>(url);
  return response.data;
};
