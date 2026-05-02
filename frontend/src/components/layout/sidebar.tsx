"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";

import { cn } from "@/lib/cn";

const NAV = [
  { href: "/", label: "ダッシュボード" },
  { href: "/prospects", label: "候補リスト" },
  { href: "/dm-logs", label: "DM ログ" },
  { href: "/posts", label: "投稿スケジュール" },
  { href: "/templates", label: "DM テンプレート" },
  { href: "/hashtags", label: "ハッシュタグ" },
  { href: "/safety", label: "安全イベント" },
  { href: "/settings", label: "設定" },
];

export function Sidebar() {
  const pathname = usePathname() ?? "/";
  return (
    <aside className="hidden w-60 shrink-0 border-r border-zinc-200 bg-white md:flex md:flex-col">
      <div className="px-5 py-6">
        <p className="text-xs font-semibold uppercase tracking-widest text-zinc-400">Insta Auto</p>
        <p className="mt-1 text-lg font-semibold text-zinc-900">Instagram 運用ダッシュボード</p>
      </div>
      <nav className="flex flex-col gap-1 px-3 pb-6">
        {NAV.map((item) => {
          const active = item.href === "/" ? pathname === "/" : pathname.startsWith(item.href);
          return (
            <Link
              key={item.href}
              href={item.href}
              className={cn(
                "rounded-md px-3 py-2 text-sm transition",
                active
                  ? "bg-zinc-900 text-white"
                  : "text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900",
              )}
            >
              {item.label}
            </Link>
          );
        })}
      </nav>
    </aside>
  );
}
