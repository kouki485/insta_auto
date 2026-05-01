"use client";

import { create } from "zustand";

import { readToken, writeToken } from "@/lib/api";

export type AuthUser = {
  id: number;
  name: string;
  email: string;
};

type AuthState = {
  token: string | null;
  user: AuthUser | null;
  hydrated: boolean;
  hydrate: () => void;
  setSession: (token: string, user: AuthUser) => void;
  clearSession: () => void;
};

export const useAuthStore = create<AuthState>((set) => ({
  token: null,
  user: null,
  hydrated: false,
  hydrate: () => {
    const token = readToken();
    set({ token, hydrated: true });
  },
  setSession: (token, user) => {
    writeToken(token);
    set({ token, user });
  },
  clearSession: () => {
    writeToken(null);
    set({ token: null, user: null });
  },
}));
