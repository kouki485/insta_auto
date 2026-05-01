"use client";

import { useEffect, useState } from "react";
import { toast } from "sonner";
import useSWR, { mutate } from "swr";

import { Button } from "@/components/ui/button";
import { Card, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { api } from "@/lib/api";

interface Account {
  id: number;
  store_name: string;
  ig_username: string;
  daily_dm_limit: number;
  daily_follow_limit: number;
  daily_like_limit: number;
  status: string;
  timezone: string;
}

interface AccountsIndex {
  data: Account[];
}

export default function SettingsPage() {
  const { data } = useSWR<AccountsIndex>("/accounts");
  const account = data?.data[0];

  const [storeName, setStoreName] = useState("");
  const [dmLimit, setDmLimit] = useState(0);
  const [followLimit, setFollowLimit] = useState(0);
  const [likeLimit, setLikeLimit] = useState(0);

  useEffect(() => {
    if (!account) return;
    setStoreName(account.store_name);
    setDmLimit(account.daily_dm_limit);
    setFollowLimit(account.daily_follow_limit);
    setLikeLimit(account.daily_like_limit);
  }, [account]);

  if (!account) {
    return <p className="text-sm text-zinc-500">読み込み中…</p>;
  }

  const handleSave = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    try {
      await api.patch(`/accounts/${account.id}`, {
        store_name: storeName,
        daily_dm_limit: dmLimit,
        daily_follow_limit: followLimit,
        daily_like_limit: likeLimit,
      });
      toast.success("保存しました");
      await mutate("/accounts");
    } catch {
      toast.error("保存に失敗しました");
    }
  };

  const handleAction = async (action: "pause" | "resume") => {
    try {
      await api.post(`/accounts/${account.id}/${action}`);
      toast.success(action === "pause" ? "停止しました" : "再開しました");
      await mutate("/accounts");
    } catch {
      toast.error("操作に失敗しました");
    }
  };

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-2xl font-semibold">設定</h1>
        <p className="text-sm text-zinc-500">レート制限・稼働状態の管理。</p>
      </header>

      <Card>
        <CardTitle>アカウント情報</CardTitle>
        <form className="mt-4 grid gap-3 md:grid-cols-2" onSubmit={handleSave}>
          <div className="space-y-1">
            <label className="text-xs font-medium text-zinc-600">店舗名</label>
            <Input value={storeName} onChange={(event) => setStoreName(event.target.value)} />
          </div>
          <div className="space-y-1">
            <label className="text-xs font-medium text-zinc-600">IG ユーザー名</label>
            <Input value={account.ig_username} disabled />
          </div>
          <div className="space-y-1">
            <label className="text-xs font-medium text-zinc-600">日次 DM 上限</label>
            <Input
              type="number"
              min={0}
              max={100}
              value={dmLimit}
              onChange={(event) => setDmLimit(Number(event.target.value))}
            />
          </div>
          <div className="space-y-1">
            <label className="text-xs font-medium text-zinc-600">日次フォロー上限</label>
            <Input
              type="number"
              min={0}
              max={200}
              value={followLimit}
              onChange={(event) => setFollowLimit(Number(event.target.value))}
            />
          </div>
          <div className="space-y-1">
            <label className="text-xs font-medium text-zinc-600">日次いいね上限</label>
            <Input
              type="number"
              min={0}
              max={1000}
              value={likeLimit}
              onChange={(event) => setLikeLimit(Number(event.target.value))}
            />
          </div>
          <div className="flex items-end justify-end md:col-span-2">
            <Button type="submit">保存</Button>
          </div>
        </form>
      </Card>

      <Card>
        <CardTitle>稼働状態</CardTitle>
        <p className="mt-2 text-sm">
          現在: <strong>{account.status}</strong>
        </p>
        <div className="mt-3 flex gap-2">
          <Button variant="danger" onClick={() => handleAction("pause")}>
            一時停止
          </Button>
          <Button variant="default" onClick={() => handleAction("resume")}>
            再開(再ウォームアップ)
          </Button>
        </div>
      </Card>
    </div>
  );
}
