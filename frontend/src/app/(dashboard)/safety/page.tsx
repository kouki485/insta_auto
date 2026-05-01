"use client";

import useSWR from "swr";

import { Badge } from "@/components/ui/badge";
import { Card, CardTitle } from "@/components/ui/card";
import { Td, Table, THead, Th, Tr } from "@/components/ui/table";
import { formatDateTime } from "@/lib/format";

interface SafetyRow {
  id: number;
  event_type: string;
  severity: "info" | "warning" | "critical";
  details: Record<string, unknown> | null;
  occurred_at: string;
}

interface SafetyIndex {
  data: SafetyRow[];
}

const TONE = {
  info: "muted",
  warning: "warning",
  critical: "danger",
} as const;

export default function SafetyPage() {
  const { data, isLoading, error } = useSWR<SafetyIndex>("/safety-events?per_page=100");

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-2xl font-semibold">安全イベント</h1>
        <p className="text-sm text-zinc-500">設計書 §4.3 の自動停止判定の根拠ログ。</p>
      </header>
      <Card>
        <CardTitle>直近 100 件</CardTitle>
        {isLoading ? (
          <p className="mt-3 text-sm text-zinc-500">読み込み中…</p>
        ) : error ? (
          <p className="mt-3 text-sm text-red-600">取得に失敗しました</p>
        ) : data && data.data.length > 0 ? (
          <Table className="mt-3">
            <THead>
              <Tr>
                <Th>発生日時</Th>
                <Th>イベント</Th>
                <Th>severity</Th>
                <Th>詳細</Th>
              </Tr>
            </THead>
            <tbody>
              {data.data.map((row) => (
                <Tr key={row.id}>
                  <Td>{formatDateTime(row.occurred_at)}</Td>
                  <Td>{row.event_type}</Td>
                  <Td>
                    <Badge tone={TONE[row.severity] ?? "muted"}>{row.severity}</Badge>
                  </Td>
                  <Td className="max-w-md text-xs text-zinc-500">
                    {row.details ? JSON.stringify(row.details) : "-"}
                  </Td>
                </Tr>
              ))}
            </tbody>
          </Table>
        ) : (
          <p className="mt-3 text-sm text-zinc-500">安全イベントはまだ発生していません。</p>
        )}
      </Card>
    </div>
  );
}
