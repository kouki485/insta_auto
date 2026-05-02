// 実ブラウザでログイン → ダッシュボード遷移を検証する smoke test
import { chromium } from "@playwright/test";

const BASE = process.env.SMOKE_FRONTEND_URL ?? "http://localhost:13000";

const main = async () => {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext();
  const page = await context.newPage();
  page.on("console", (msg) => console.log(`[browser:${msg.type()}]`, msg.text()));
  page.on("pageerror", (err) => console.log("[browser:error]", err.message));
  page.on("requestfailed", (req) => {
    console.log("[req-failed]", req.method(), req.url(), req.failure()?.errorText);
  });
  page.on("response", async (res) => {
    const url = res.url();
    if (url.includes("/api/")) {
      console.log(`[api] ${res.status()} ${res.request().method()} ${url}`);
    }
  });

  console.log(`>>> goto ${BASE}/login`);
  await page.goto(`${BASE}/login`, { waitUntil: "networkidle" });

  await page.fill('input[type="email"]', "staff@example.com");
  await page.fill('input[type="password"]', "password");

  console.log(">>> click login");
  const [resp] = await Promise.all([
    page.waitForResponse((res) => res.url().includes("/auth/login"), { timeout: 30_000 }),
    page.locator('button[type="submit"]').click(),
  ]);
  console.log(">>> login response status:", resp.status());

  console.log(">>> wait for dashboard");
  await page.waitForURL((url) => !url.pathname.startsWith("/login"), { timeout: 10_000 });

  const heading = await page.locator("h1").first().textContent({ timeout: 5_000 });
  console.log(">>> H1 on dashboard:", heading);

  const cookies = await context.cookies();
  const localToken = await page.evaluate(() => window.localStorage.getItem("instaauto.token"));
  console.log(">>> cookies count:", cookies.length, "token saved:", Boolean(localToken));

  // ブランド表記がページ全体に "うなら" を含まないことを確認
  const bodyText = (await page.locator("body").innerText({ timeout: 5_000 })) ?? "";
  if (bodyText.includes("うなら")) {
    throw new Error('レガシーなブランド表記「うなら」がダッシュボードに残っている: ' + bodyText.slice(0, 200));
  }
  console.log(">>> brand check: OK (うなら not present)");

  await browser.close();
  console.log("OK: login flow succeeded");
};

main().catch((err) => {
  console.error("SMOKE FAILED:", err);
  process.exit(1);
});
