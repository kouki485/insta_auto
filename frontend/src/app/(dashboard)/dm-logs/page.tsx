"use client";

import useSWR from "swr";

import { Badge } from "@/components/ui/badge";
import { Card, CardTitle } from "@/components/ui/card";
import { Td, Table, THead, Th, Tr } from "@/components/ui/table";
import { formatDateTime } from "@/lib/format";

interface DmLogRow {
  id: number;
  language: string;
  message_sent: string;
  status: string;
  ig_message_id: string | null;
  sent_at: string | null;
  created_at: string;
  prospect: { id: number; ig_username: string; detected_lang: string | null } | null;
}

interface DmLogIndex {
  data: DmLogRow[];
}

const STATUS_TONE = {
  queued: "muted",
  sent: "success",
  failed: "danger",
  rate_limited: "warning",
  blocked: "danger",
} as const;

export default function DmLogsPage() {
  const { data, isLoading, error } = useSWR<DmLogIndex>("/dm-logs?per_page=100");

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-2xl font-semibold">DM ログ</h1>
        <p className="text-sm text-zinc-500">直近 100 件の送信履歴。</p>
      </header>
      <Card>
        <CardTitle>送信履歴</CardTitle>
        {isLoading ? (
          <p className="mt-3 text-sm text-zinc-500">読み込み中…</p>
        ) : error ? (
          <p className="mt-3 text-sm text-red-600">取得に失敗しました</p>
        ) : data && data.data.length > 0 ? (
          <Table className="mt-3">
            <THead>
              <Tr>
                <Th>送信日時</Th>
                <Th>相手</Th>
                <Th>言語</Th>
                <Th>状態</Th>
                <Th>メッセージ</Th>
              </Tr>
            </THead>
            <tbody>
              {data.data.map((row) => (
                <Tr key={row.id}>
                  <Td>{formatDateTime(row.sent_at ?? row.created_at)}</Td>
                  <Td>{row.prospect ? `@${row.prospect.ig_username}` : "-"}</Td>
                  <Td>{row.language}</Td>
                  <Td>
                    <Badge tone={STATUS_TONE[row.status as keyof typeof STATUS_TONE] ?? "default"}>
                      {row.status}
                    </Badge>
                  </Td>
                  <Td className="max-w-md truncate text-zinc-700">{row.message_sent}</Td>
                </Tr>
              ))}
            </tbody>
          </Table>
        ) : (
          <p className="mt-3 text-sm text-zinc-500">送信履歴はまだありません。</p>
        )}
      </Card>
    </div>
  );
}
