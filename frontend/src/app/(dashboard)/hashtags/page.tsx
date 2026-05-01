"use client";

import { useState } from "react";
import { toast } from "sonner";
import useSWR, { mutate } from "swr";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Td, Table, THead, Th, Tr } from "@/components/ui/table";
import { api } from "@/lib/api";
import { formatDateTime } from "@/lib/format";

interface HashtagRow {
  id: number;
  hashtag: string;
  language: string | null;
  priority: number;
  active: boolean;
  last_scraped_at: string | null;
}

interface HashtagIndex {
  data: HashtagRow[];
}

export default function HashtagsPage() {
  const { data, isLoading, error } = useSWR<HashtagIndex>("/hashtags");
  const [hashtag, setHashtag] = useState("");
  const [language, setLanguage] = useState("");
  const [priority, setPriority] = useState(5);

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    try {
      await api.post("/hashtags", { hashtag, language: language || null, priority });
      toast.success(`#${hashtag} を追加しました`);
      setHashtag("");
      setLanguage("");
      setPriority(5);
      await mutate("/hashtags");
    } catch {
      toast.error("追加に失敗しました");
    }
  };

  const handleDelete = async (id: number) => {
    try {
      await api.delete(`/hashtags/${id}`);
      await mutate("/hashtags");
    } catch {
      toast.error("削除に失敗しました");
    }
  };

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-2xl font-semibold">監視ハッシュタグ</h1>
        <p className="text-sm text-zinc-500">スクレイピング対象タグ (1 時間あたり 10 タグ上限)。</p>
      </header>

      <Card>
        <CardTitle>新規追加</CardTitle>
        <form className="mt-4 grid gap-3 md:grid-cols-4" onSubmit={handleSubmit}>
          <Input
            value={hashtag}
            onChange={(event) => setHashtag(event.target.value)}
            placeholder="asakusa"
            required
            className="md:col-span-2"
          />
          <Input
            value={language}
            onChange={(event) => setLanguage(event.target.value)}
            placeholder="en / zh-cn / ja..."
            maxLength={10}
          />
          <div className="flex items-center gap-2">
            <Input
              type="number"
              min={1}
              max={10}
              value={priority}
              onChange={(event) => setPriority(Number(event.target.value))}
              className="w-24"
            />
            <Button type="submit">追加</Button>
          </div>
        </form>
      </Card>

      <Card>
        <CardTitle>監視中ハッシュタグ</CardTitle>
        {isLoading ? (
          <p className="mt-3 text-sm text-zinc-500">読み込み中…</p>
        ) : error ? (
          <p className="mt-3 text-sm text-red-600">取得に失敗しました</p>
        ) : data && data.data.length > 0 ? (
          <Table className="mt-3">
            <THead>
              <Tr>
                <Th>#hashtag</Th>
                <Th>言語</Th>
                <Th>優先度</Th>
                <Th>状態</Th>
                <Th>最終スクレイプ</Th>
                <Th className="text-right">操作</Th>
              </Tr>
            </THead>
            <tbody>
              {data.data.map((row) => (
                <Tr key={row.id}>
                  <Td>#{row.hashtag}</Td>
                  <Td>{row.language ?? "-"}</Td>
                  <Td>{row.priority}</Td>
                  <Td>
                    <Badge tone={row.active ? "success" : "muted"}>
                      {row.active ? "active" : "paused"}
                    </Badge>
                  </Td>
                  <Td>{formatDateTime(row.last_scraped_at)}</Td>
                  <Td className="text-right">
                    <Button variant="danger" onClick={() => handleDelete(row.id)}>
                      削除
                    </Button>
                  </Td>
                </Tr>
              ))}
            </tbody>
          </Table>
        ) : (
          <p className="mt-3 text-sm text-zinc-500">ハッシュタグはまだ登録されていません。</p>
        )}
      </Card>
    </div>
  );
}
