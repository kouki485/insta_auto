"use client";

import { useState } from "react";
import { toast } from "sonner";
import useSWR, { mutate } from "swr";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardDescription, CardTitle } from "@/components/ui/card";
import { Td, Table, THead, Th, Tr } from "@/components/ui/table";
import { api } from "@/lib/api";
import { formatDateTime, formatNumber } from "@/lib/format";

interface Summary {
  data: {
    account_id: number;
    store_name: string;
    health_score: number;
    health_action: "none" | "halve_dm_limit" | "auto_pause";
    today: {
      dm_sent: number;
      dm_limit: number;
      dm_replies: number;
      stories_posted: number;
      stories_planned: number;
    };
    prospects_pool: {
      new: number;
      queued: number;
      dm_sent_total: number;
      replied_total: number;
    };
    weekly_trend: Array<{ date: string; sent: number; replies: number }>;
    recent_safety_events: Array<{
      id: number;
      event_type: string;
      severity: "info" | "warning" | "critical";
      occurred_at: string;
    }>;
  } | null;
}

const TONE_BY_SEVERITY = {
  critical: "danger",
  warning: "warning",
  info: "muted",
} as const;

type HealthAction = "none" | "halve_dm_limit" | "auto_pause";

const HEALTH_ACTION_LABEL: Record<HealthAction, string> = {
  none: "問題なし",
  halve_dm_limit: "DM上限半減中",
  auto_pause: "自動停止中",
};

const SUMMARY_ENDPOINT = "/dashboard/summary";

export default function DashboardHome() {
  const { data, error, isLoading } = useSWR<Summary>(SUMMARY_ENDPOINT);
  const [refreshing, setRefreshing] = useState(false);

  const handleRefresh = async () => {
    setRefreshing(true);
    try {
      const fresh = await api.get<Summary>(`${SUMMARY_ENDPOINT}?fresh=1`);
      await mutate(SUMMARY_ENDPOINT, fresh.data, { revalidate: false });
      toast.success("最新値に更新しました");
    } catch {
      toast.error("更新に失敗しました");
    } finally {
      setRefreshing(false);
    }
  };

  if (isLoading) {
    return <p className="text-sm text-zinc-500">読み込み中…</p>;
  }
  if (error || !data?.data) {
    return <p className="text-sm text-red-600">ダッシュボードの取得に失敗しました。</p>;
  }
  const summary = data.data;
  const healthTone =
    summary.health_score >= 70 ? "success" : summary.health_score >= 50 ? "warning" : "danger";

  return (
    <div className="space-y-6">
      <header className="flex items-end justify-between gap-4">
        <div>
          <p className="text-xs uppercase tracking-widest text-zinc-400">
            アカウント #{summary.account_id}
          </p>
          <h1 className="mt-1 text-2xl font-semibold">{summary.store_name}</h1>
        </div>
        <div className="flex items-center gap-3">
          <Badge tone={healthTone}>
            ヘルス {summary.health_score} / 100
            {summary.health_action !== "none" &&
              ` ・ ${HEALTH_ACTION_LABEL[summary.health_action]}`}
          </Badge>
          <Button variant="outline" onClick={handleRefresh} disabled={refreshing}>
            {refreshing ? "更新中…" : "最新に更新"}
          </Button>
        </div>
      </header>

      <div className="grid gap-4 md:grid-cols-4">
        <Card>
          <CardTitle>本日の DM 送信</CardTitle>
          <CardDescription>
            {formatNumber(summary.today.dm_sent)} / {formatNumber(summary.today.dm_limit)}
          </CardDescription>
        </Card>
        <Card>
          <CardTitle>本日の返信数</CardTitle>
          <CardDescription>{formatNumber(summary.today.dm_replies)}</CardDescription>
        </Card>
        <Card>
          <CardTitle>本日のストーリー投稿</CardTitle>
          <CardDescription>
            {formatNumber(summary.today.stories_posted)} / {formatNumber(summary.today.stories_planned)}
          </CardDescription>
        </Card>
        <Card>
          <CardTitle>候補プール (new)</CardTitle>
          <CardDescription>{formatNumber(summary.prospects_pool.new)}</CardDescription>
        </Card>
      </div>

      <Card>
        <CardTitle>過去 7 日のエンゲージメント</CardTitle>
        <Table className="mt-3">
          <THead>
            <Tr>
              <Th>日付</Th>
              <Th>送信</Th>
              <Th>返信</Th>
            </Tr>
          </THead>
          <tbody>
            {summary.weekly_trend.map((row) => (
              <Tr key={row.date}>
                <Td>{row.date}</Td>
                <Td>{formatNumber(row.sent)}</Td>
                <Td>{formatNumber(row.replies)}</Td>
              </Tr>
            ))}
          </tbody>
        </Table>
      </Card>

      <Card>
        <CardTitle>直近 24 時間の安全イベント</CardTitle>
        {summary.recent_safety_events.length === 0 ? (
          <p className="mt-3 text-sm text-zinc-500">該当するイベントはありません。</p>
        ) : (
          <Table className="mt-3">
            <THead>
              <Tr>
                <Th>発生時刻</Th>
                <Th>イベント</Th>
                <Th>重大度</Th>
              </Tr>
            </THead>
            <tbody>
              {summary.recent_safety_events.map((event) => (
                <Tr key={event.id}>
                  <Td>{formatDateTime(event.occurred_at)}</Td>
                  <Td>{event.event_type}</Td>
                  <Td>
                    <Badge tone={TONE_BY_SEVERITY[event.severity] ?? "muted"}>
                      {event.severity}
                    </Badge>
                  </Td>
                </Tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>
    </div>
  );
}
