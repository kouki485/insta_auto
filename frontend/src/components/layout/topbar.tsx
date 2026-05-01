"use client";

import { useRouter } from "next/navigation";

import { Button } from "@/components/ui/button";
import { api } from "@/lib/api";
import { useAuthStore } from "@/lib/auth-store";

export function Topbar() {
  const router = useRouter();
  const user = useAuthStore((state) => state.user);
  const clearSession = useAuthStore((state) => state.clearSession);

  const handleLogout = async () => {
    try {
      await api.post("/auth/logout");
    } catch {
      // best-effort: ローカルセッションは必ず破棄する
    }
    clearSession();
    router.replace("/login");
  };

  return (
    <header className="flex items-center justify-between border-b border-zinc-200 bg-white px-6 py-3">
      <div>
        <p className="text-xs uppercase tracking-widest text-zinc-400">Operations</p>
        <p className="text-base font-semibold text-zinc-900">{user?.name ?? "運用代行スタッフ"}</p>
      </div>
      <Button variant="outline" onClick={handleLogout}>
        ログアウト
      </Button>
    </header>
  );
}
