"use client";

import { useRef, useState } from "react";
import { toast } from "sonner";
import useSWR, { mutate } from "swr";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Td, Table, THead, Th, Tr } from "@/components/ui/table";
import { api } from "@/lib/api";
import { formatDateTime } from "@/lib/format";

interface PostRow {
  id: number;
  type: "feed" | "story";
  image_path: string;
  caption: string | null;
  scheduled_at: string;
  posted_at: string | null;
  status: "scheduled" | "posting" | "posted" | "failed";
  ig_media_id: string | null;
}

interface PostIndex {
  data: PostRow[];
}

const STATUS_TONE = {
  scheduled: "default",
  posting: "warning",
  posted: "success",
  failed: "danger",
} as const;

export default function PostsPage() {
  const fileRef = useRef<HTMLInputElement>(null);
  const [type, setType] = useState<"feed" | "story">("story");
  const [caption, setCaption] = useState("");
  const [scheduledAt, setScheduledAt] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const { data, isLoading, error } = useSWR<PostIndex>("/posts");

  const handleCreate = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (submitting) return;
    if (!fileRef.current?.files?.[0]) {
      toast.error("画像を選択してください");
      return;
    }
    setSubmitting(true);
    try {
      const formData = new FormData();
      formData.append("image", fileRef.current.files[0]);
      const upload = await api.post<{ data: { image_path: string } }>(
        "/posts/upload-image",
        formData,
        { headers: { "Content-Type": "multipart/form-data" } },
      );
      await api.post("/posts", {
        type,
        image_path: upload.data.data.image_path,
        caption: caption || null,
        scheduled_at: new Date(scheduledAt).toISOString(),
      });
      toast.success("投稿予約を作成しました");
      setCaption("");
      setScheduledAt("");
      if (fileRef.current) fileRef.current.value = "";
      await mutate("/posts");
    } catch {
      toast.error("作成に失敗しました");
    } finally {
      setSubmitting(false);
    }
  };

  const handleDelete = async (id: number) => {
    try {
      await api.delete(`/posts/${id}`);
      toast.success(`#${id} を削除しました`);
      await mutate("/posts");
    } catch {
      toast.error("削除に失敗しました");
    }
  };

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-2xl font-semibold">投稿スケジュール</h1>
        <p className="text-sm text-zinc-500">事前に画像をアップロードし、投稿日時を予約します。</p>
      </header>

      <Card>
        <CardTitle>新規予約</CardTitle>
        <form className="mt-4 grid gap-3 md:grid-cols-2" onSubmit={handleCreate}>
          <div className="space-y-1">
            <label className="text-xs font-medium text-zinc-600">タイプ</label>
            <select
              className="h-10 w-full rounded-md border border-zinc-300 px-3 text-sm"
              value={type}
              onChange={(event) => setType(event.target.value as "feed" | "story")}
            >
              <option value="feed">フィード</option>
              <option value="story">ストーリー</option>
            </select>
          </div>
          <div className="space-y-1">
            <label className="text-xs font-medium text-zinc-600">画像 (JPG / PNG, 5MB)</label>
            <input
              ref={fileRef}
              type="file"
              accept="image/jpeg,image/png"
              className="block w-full text-sm text-zinc-700"
            />
          </div>
          <div className="space-y-1 md:col-span-2">
            <label className="text-xs font-medium text-zinc-600">キャプション(任意)</label>
            <Input
              value={caption}
              onChange={(event) => setCaption(event.target.value)}
              placeholder="例: Today's special"
            />
          </div>
          <div className="space-y-1">
            <label className="text-xs font-medium text-zinc-600">予約日時(JST)</label>
            <Input
              type="datetime-local"
              value={scheduledAt}
              onChange={(event) => setScheduledAt(event.target.value)}
              required
            />
          </div>
          <div className="flex items-end justify-end">
            <Button type="submit" disabled={submitting}>
              {submitting ? "登録中…" : "予約を作成"}
            </Button>
          </div>
        </form>
      </Card>

      <Card>
        <CardTitle>予約一覧</CardTitle>
        {isLoading ? (
          <p className="mt-3 text-sm text-zinc-500">読み込み中…</p>
        ) : error ? (
          <p className="mt-3 text-sm text-red-600">取得に失敗しました</p>
        ) : data && data.data.length > 0 ? (
          <Table className="mt-3">
            <THead>
              <Tr>
                <Th>予約日時</Th>
                <Th>タイプ</Th>
                <Th>状態</Th>
                <Th>キャプション</Th>
                <Th className="text-right">操作</Th>
              </Tr>
            </THead>
            <tbody>
              {data.data.map((row) => (
                <Tr key={row.id}>
                  <Td>{formatDateTime(row.scheduled_at)}</Td>
                  <Td>{row.type}</Td>
                  <Td>
                    <Badge tone={STATUS_TONE[row.status]}>{row.status}</Badge>
                  </Td>
                  <Td className="max-w-sm truncate">{row.caption ?? "-"}</Td>
                  <Td className="text-right">
                    {row.status === "scheduled" ? (
                      <Button variant="danger" onClick={() => handleDelete(row.id)}>
                        削除
                      </Button>
                    ) : (
                      <span className="text-xs text-zinc-400">{row.ig_media_id ?? "-"}</span>
                    )}
                  </Td>
                </Tr>
              ))}
            </tbody>
          </Table>
        ) : (
          <p className="mt-3 text-sm text-zinc-500">予約はまだありません。</p>
        )}
      </Card>
    </div>
  );
}
