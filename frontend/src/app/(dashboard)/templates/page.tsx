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

interface TemplateRow {
  id: number;
  language: string;
  template: string;
  active: boolean;
  updated_at: string;
}

interface TemplateIndex {
  data: TemplateRow[];
}

export default function TemplatesPage() {
  const { data, isLoading, error } = useSWR<TemplateIndex>("/dm-templates");
  const [language, setLanguage] = useState("en");
  const [text, setText] = useState("");

  const handleCreate = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    try {
      await api.post("/dm-templates", { language, template: text });
      toast.success(`${language} テンプレを保存しました`);
      setText("");
      await mutate("/dm-templates");
    } catch {
      toast.error("保存に失敗しました");
    }
  };

  const toggleActive = async (row: TemplateRow) => {
    try {
      await api.patch(`/dm-templates/${row.id}`, { active: !row.active });
      await mutate("/dm-templates");
    } catch {
      toast.error("更新に失敗しました");
    }
  };

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-2xl font-semibold">DM テンプレート</h1>
        <p className="text-sm text-zinc-500">
          言語別のフォールバック文面。プレースホルダ: {"{username}"} / {"{store_name}"}
        </p>
      </header>

      <Card>
        <CardTitle>テンプレを追加 / 更新</CardTitle>
        <form className="mt-4 space-y-3" onSubmit={handleCreate}>
          <div className="grid gap-3 md:grid-cols-[160px_1fr]">
            <Input
              value={language}
              onChange={(event) => setLanguage(event.target.value)}
              placeholder="ko, zh-cn, fr ..."
              maxLength={10}
              required
            />
            <textarea
              value={text}
              onChange={(event) => setText(event.target.value)}
              className="min-h-32 rounded-md border border-zinc-300 px-3 py-2 text-sm"
              placeholder="Hi {username}! Welcome to {store_name}..."
              required
            />
          </div>
          <div className="flex justify-end">
            <Button type="submit">保存</Button>
          </div>
        </form>
      </Card>

      <Card>
        <CardTitle>登録済み</CardTitle>
        {isLoading ? (
          <p className="mt-3 text-sm text-zinc-500">読み込み中…</p>
        ) : error ? (
          <p className="mt-3 text-sm text-red-600">取得に失敗しました</p>
        ) : data && data.data.length > 0 ? (
          <Table className="mt-3">
            <THead>
              <Tr>
                <Th>言語</Th>
                <Th>本文</Th>
                <Th>更新</Th>
                <Th>状態</Th>
                <Th className="text-right">操作</Th>
              </Tr>
            </THead>
            <tbody>
              {data.data.map((row) => (
                <Tr key={row.id}>
                  <Td>{row.language}</Td>
                  <Td className="max-w-md whitespace-pre-line text-zinc-700">{row.template}</Td>
                  <Td>{formatDateTime(row.updated_at)}</Td>
                  <Td>
                    <Badge tone={row.active ? "success" : "muted"}>
                      {row.active ? "active" : "inactive"}
                    </Badge>
                  </Td>
                  <Td className="text-right">
                    <Button variant="outline" onClick={() => toggleActive(row)}>
                      {row.active ? "無効化" : "有効化"}
                    </Button>
                  </Td>
                </Tr>
              ))}
            </tbody>
          </Table>
        ) : (
          <p className="mt-3 text-sm text-zinc-500">テンプレートはまだありません。</p>
        )}
      </Card>
    </div>
  );
}
