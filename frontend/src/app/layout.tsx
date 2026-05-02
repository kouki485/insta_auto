import type { Metadata } from "next";
import "./globals.css";

import { AppProviders } from "@/components/providers/swr-provider";

export const metadata: Metadata = {
  title: "Insta Auto — 運用ダッシュボード",
  description: "Instagram 運用自動化ツールの管理画面",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="ja">
      <body className="min-h-screen bg-zinc-50 text-zinc-900 antialiased">
        <AppProviders>{children}</AppProviders>
      </body>
    </html>
  );
}
