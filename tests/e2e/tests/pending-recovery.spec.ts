import { test, expect, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
import path from "node:path";

import { STORE_URL } from "../config.js";

// TWO-26300: a buyer who verifies with Two and never comes back to the shop.
//
// The hermetic harness cannot place a Two order through the storefront (the checkout
// refuses a company that was not verified through Two's live search, which the stub does
// not serve), so dev/ci/e2e-pending-attempt.php seeds the state the payment step leaves:
// an order created at the stub and a checkout attempt REDIRECTED to Two, with no
// PrestaShop order. From there everything is the real module: the cron URL, the resolver,
// validateOrder outside the buyer's session, and the Diagnostics button.

const SFX = process.env.SFX ?? "";
const CONTAINER = `ps-${SFX}`;
const HELPER = "/tmp/e2e-pending-attempt.php";
const ADMIN_EMAIL = "exampleuser@two.inc";
const ADMIN_PASSWORD = "examplepassword123";

type Seeded = { token: string; id_attempt: number; id_cart: number; two_order_id: string };
type State = {
  attempt: { status: string; id_order: string | null; two_order_state: string | null; pending_outcome: string | null };
  id_order: number;
  orders_for_cart: number;
  current_state: number;
  verified_status: number;
};
type Request = { method: string; path: string };

function helper<T>(...args: string[]): T {
  const out = execFileSync("docker", ["exec", "-u", "www-data", CONTAINER, "php", HELPER, ...args], {
    encoding: "utf8"
  });
  const line = out.trim().split("\n").pop() ?? "";
  return JSON.parse(line) as T;
}

// What the check recorded and the module logged, for a failure message.
function diagnose(state: State): string {
  return JSON.stringify({ attempt: state.attempt, log: helper<unknown[]>("log") });
}

function requestsTo(order: string, suffix: string): Request[] {
  return helper<Request[]>("requests", order).filter((r) => r.method === "POST" && r.path.endsWith(suffix));
}

async function runCron(page: Page): Promise<{ status: string; pending: Record<string, number> | null }> {
  const { token } = helper<{ token: string }>("cron-token");
  const response = await page.request.get(
    `${STORE_URL}/index.php?fc=module&module=twopayment&controller=cron&token=${encodeURIComponent(token)}`
  );
  expect(response.status(), "the cron URL accepts the token").toBe(200);
  return response.json();
}

test.describe("Payments the buyer verified but never returned from", () => {
  test.skip(SFX === "", "needs the CI container (SFX)");

  test.beforeAll(() => {
    // The e2e package is CommonJS, so __dirname; import.meta would load this file as ESM.
    execFileSync("docker", ["cp", path.resolve(__dirname, "../../../dev/ci/e2e-pending-attempt.php"), `${CONTAINER}:${HELPER}`]);
  });

  test("the scheduled check places a verified payment, confirming it with Two once", async ({ page }) => {
    const seeded = helper<Seeded>("seed", "31");

    // Still UNVERIFIED at Two: the check records it and changes nothing.
    await runCron(page);
    let state = helper<State>("state", seeded.token);
    expect(state.attempt.status).toBe("REDIRECTED");
    expect(state.id_order).toBe(0);

    // The buyer verifies at Two and closes the tab. Past the 15-minute skip, the next call places it.
    helper("two-state", seeded.two_order_id, "VERIFIED");
    helper("checked-ago", seeded.token, "20");
    await runCron(page);

    state = helper<State>("state", seeded.token);
    expect(state.attempt.status, `the attempt is confirmed: ${diagnose(state)}`).toBe("CONFIRMED");
    expect(state.id_order, "a PrestaShop order exists").toBeGreaterThan(0);
    expect(state.current_state, "at the verified status").toBe(state.verified_status);
    expect(requestsTo(seeded.two_order_id, "/confirm"), "one confirm request").toHaveLength(1);

    // A late return by the buyer is sent to the placed order, with no second order or confirm.
    const key = helper<{ attempt: { customer_secure_key: string } }>("state", seeded.token).attempt.customer_secure_key;
    const back = await page.request.get(
      `${STORE_URL}/index.php?fc=module&module=twopayment&controller=confirmation&attempt_token=${seeded.token}&key=${encodeURIComponent(key)}`,
      { maxRedirects: 0 }
    );
    expect(back.status(), "the late return redirects").toBe(302);
    expect(back.headers()["location"] ?? "", "to the order confirmation, never an error").toMatch(/order-confirmation/);
    state = helper<State>("state", seeded.token);
    expect(state.orders_for_cart, "still one order for the cart").toBe(1);
    expect(requestsTo(seeded.two_order_id, "/confirm"), "still one confirm request").toHaveLength(1);
  });

  test("a payment Two cancelled is left unplaced, with nothing sent to Two", async ({ page }) => {
    const seeded = helper<Seeded>("seed", "31");
    helper("two-state", seeded.two_order_id, "CANCELLED");

    await runCron(page);

    const state = helper<State>("state", seeded.token);
    expect(state.attempt.status).toBe("CANCELLED");
    expect(state.attempt.two_order_state).toBe("CANCELLED");
    expect(state.id_order, "no PrestaShop order").toBe(0);
    expect(requestsTo(seeded.two_order_id, "/cancel"), "no cancel sent to Two").toHaveLength(0);
  });

  test("the Diagnostics button places a verified payment at any age", async ({ page }) => {
    const seeded = helper<Seeded>("seed", "2");
    helper("two-state", seeded.two_order_id, "VERIFIED");

    await page.goto(`${STORE_URL}/admin-dev/index.php?controller=AdminLogin`);
    await page.locator('input[name="email"]').fill(ADMIN_EMAIL);
    await page.locator('input[name="passwd"], input[name="password"]').first().fill(ADMIN_PASSWORD);
    await page.locator('#submit_login, button[type="submit"]').first().click();
    await page.waitForLoadState("networkidle");

    const { url } = helper<{ url: string }>("admin-url", ADMIN_EMAIL);
    await page.goto(`${STORE_URL}/admin-dev/${url}`);
    await page.locator('a[href="#diagnostics-settings"]').click();
    const row = page.locator("#two-pending-payments tr", { hasText: seeded.two_order_id });
    await expect(row).toBeVisible();
    await row.locator('button[name="two_pending_attempt"]').click();

    const state = helper<State>("state", seeded.token);
    await expect(
      page.locator(".alert-success, .module_confirmation"),
      `the button reports the placed order: ${diagnose(state)}`
    ).toContainText("It is now placed");
    expect(state.attempt.status).toBe("CONFIRMED");
    expect(state.current_state).toBe(state.verified_status);
    expect(requestsTo(seeded.two_order_id, "/confirm")).toHaveLength(1);
  });
});
