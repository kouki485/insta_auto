"use client";

import { useState } from "react";
import { toast } from "sonner";
import useSWR, { mutate } from "swr";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardTitle } from "@/components/ui/card";
import { Td, Table, THead, Th, Tr } from "@/components/ui/table";
import { api } from "@/lib/api";
import { formatDateTime, formatNumber } from "@/lib/format";

interface ProspectRow {
  id: number;
  ig_username: string;
  full_name: string | null;
  follower_count: number;
  detected_lang: string | null;
  source_hashtag: string | null;
  tourist_score: number | null;
  status: string;
  found_at: string;
}

interface ProspectIndex {
  data: ProspectRow[];
  current_page: number;
  last_page: number;
}

const STATUS_TONE = {
  new: "default",
  queued: "success",
  dm_sent: "muted",
  replied: "success",
  skipped: "warning",
  blacklisted: "danger",
} as const;

export default function ProspectsPage() {
  const [filter, setFilter] = useState<"new" | "all">("new");
  const queryKey = `/prospects?per_page=50${filter === "new" ? "&status=new" : ""}`;
  const { data, isLoading, error } = useSWR<ProspectIndex>(queryKey);

  const updateStatus = async (id: number, status: "queued" | "skipped" | "blacklisted") => {
    try {
      await api.patch(`/prospects/${id}`, { status });
      toast.success(`#${id} を ${status} に更新しました`);
      await mutate(queryKey);
    } catch {
      toast.error("更新に失敗しました");
    }
  };

  return (
    <div className="space-y-6">
      <header className="flex items-end justify-between">
        <div>
          <h1 className="text-2xl font-semibold">候補リスト</h1>
          <p className="text-sm text-zinc-500">tourist_score 降順で表示します。</p>
        </div>
        <div className="flex items-center gap-2">
          <Button
            variant={filter === "new" ? "default" : "outline"}
            onClick={() => setFilter("new")}
          >
            未対応
          </Button>
          <Button
            variant={filter === "all" ? "default" : "outline"}
            onClick={() => setFilter("all")}
          >
            すべて
          </Button>
        </div>
      </header>

      <Card>
        <CardTitle>{filter === "new" ? "未対応の候補" : "全候補"}</CardTitle>
        {isLoading ? (
          <p className="mt-3 text-sm text-zinc-500">読み込み中…</p>
        ) : error ? (
          <p className="mt-3 text-sm text-red-600">取得に失敗しました</p>
        ) : data && data.data.length > 0 ? (
          <Table className="mt-3">
            <THead>
              <Tr>
                <Th>ユーザー</Th>
                <Th>言語</Th>
                <Th>フォロワー</Th>
                <Th>スコア</Th>
                <Th>ステータス</Th>
                <Th>発見日時</Th>
                <Th className="text-right">アクション</Th>
              </Tr>
            </THead>
            <tbody>
              {data.data.map((row) => (
                <Tr key={row.id}>
                  <Td>
                    <div className="font-medium">@{row.ig_username}</div>
                    {row.full_name && <div className="text-xs text-zinc-500">{row.full_name}</div>}
                    {row.source_hashtag && (
                      <div className="text-xs text-zinc-400">#{row.source_hashtag}</div>
                    )}
                  </Td>
                  <Td>{row.detected_lang ?? "-"}</Td>
                  <Td>{formatNumber(row.follower_count)}</Td>
                  <Td>{row.tourist_score ?? "-"}</Td>
                  <Td>
                    <Badge
                      tone={
                        STATUS_TONE[row.status as keyof typeof STATUS_TONE] ?? "default"
                      }
                    >
                      {row.status}
                    </Badge>
                  </Td>
                  <Td>{formatDateTime(row.found_at)}</Td>
                  <Td>
                    <div className="flex justify-end gap-2">
                      <Button variant="outline" onClick={() => updateStatus(row.id, "queued")}>
                        承認
                      </Button>
                      <Button variant="ghost" onClick={() => updateStatus(row.id, "skipped")}>
                        却下
                      </Button>
                      <Button variant="danger" onClick={() => updateStatus(row.id, "blacklisted")}>
                        BL
                      </Button>
                    </div>
                  </Td>
                </Tr>
              ))}
            </tbody>
          </Table>
        ) : (
          <p className="mt-3 text-sm text-zinc-500">該当する候補はありません。</p>
        )}
      </Card>
    </div>
  );
}
