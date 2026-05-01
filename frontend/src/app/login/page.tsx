"use client";

import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { api } from "@/lib/api";
import { useAuthStore } from "@/lib/auth-store";

interface LoginResponse {
  data: {
    token: string;
    user: { id: number; name: string; email: string };
  };
}

export default function LoginPage() {
  const router = useRouter();
  const setSession = useAuthStore((state) => state.setSession);
  const hydrate = useAuthStore((state) => state.hydrate);
  const token = useAuthStore((state) => state.token);

  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    hydrate();
  }, [hydrate]);

  useEffect(() => {
    if (token) {
      router.replace("/");
    }
  }, [token, router]);

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (submitting) return;
    setSubmitting(true);
    try {
      const { data } = await api.post<LoginResponse>("/auth/login", {
        email,
        password,
        device_name: "dashboard",
      });
      setSession(data.data.token, data.data.user);
      toast.success("ログインしました");
      router.replace("/");
    } catch {
      toast.error("ログインに失敗しました。メール/パスワードをご確認ください。");
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="flex min-h-screen items-center justify-center bg-zinc-100 px-4">
      <Card className="w-full max-w-md">
        <div className="mb-6">
          <p className="text-xs uppercase tracking-widest text-zinc-400">うなら</p>
          <h1 className="mt-1 text-xl font-semibold text-zinc-900">運用ダッシュボード</h1>
          <p className="mt-1 text-sm text-zinc-500">運用代行スタッフ向けログイン</p>
        </div>
        <form className="space-y-4" onSubmit={handleSubmit}>
          <div className="space-y-1">
            <label className="text-xs font-medium text-zinc-600">メールアドレス</label>
            <Input
              type="email"
              required
              value={email}
              onChange={(event) => setEmail(event.target.value)}
              placeholder="staff@unara.local"
            />
          </div>
          <div className="space-y-1">
            <label className="text-xs font-medium text-zinc-600">パスワード</label>
            <Input
              type="password"
              required
              value={password}
              onChange={(event) => setPassword(event.target.value)}
            />
          </div>
          <Button type="submit" disabled={submitting} className="w-full">
            {submitting ? "ログイン中…" : "ログイン"}
          </Button>
        </form>
      </Card>
    </div>
  );
}
