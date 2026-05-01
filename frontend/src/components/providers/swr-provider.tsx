"use client";

import { Toaster } from "sonner";
import { SWRConfig } from "swr";

import { fetcher } from "@/lib/api";

export function AppProviders({ children }: { children: React.ReactNode }) {
  return (
    <SWRConfig value={{ fetcher, revalidateOnFocus: false, dedupingInterval: 30_000 }}>
      {children}
      <Toaster richColors position="top-right" />
    </SWRConfig>
  );
}
