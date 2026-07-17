import { type Page } from "@playwright/test";

/**
 * Adds product #1 (PS's fixed demo-fixture "Hummingbird printed t-shirt")
 * to the cart and opens the checkout page.
 *
 * TWO-25110 review history: earlier versions located a product via the
 * homepage — first `a.product-thumbnail` (doesn't exist on PS9's default
 * homepage layout), then the homepage's first "Add to cart" button
 * (unreliable even on a single PS version: which demo block renders on
 * the homepage — and whether it exposes inline add-to-cart at all, vs.
 * only "Quick view" — varies run to run). Navigating straight to the
 * product's non-SEO URL sidesteps both: it resolves the same way
 * regardless of theme, friendly-URL slug, or which homepage blocks are
 * active, and always renders the product page's own add-to-cart button.
 */
export async function addFirstProductToCartAndGoToCheckout(page: Page) {
  await page.goto("/index.php?id_product=1&controller=product");
  await page.waitForLoadState("networkidle");

  // PrestaShop's dev mode (_PS_MODE_DEV_, set by this harness's default
  // PS_DEV_MODE=1) renders a "[Debug] This page has moved" notice instead
  // of a real HTTP redirect when a URL doesn't match its canonical
  // friendly-URL form - which the non-SEO id_product URL above never
  // does. That debug page has exactly one link (the canonical URL) -
  // follow it generically rather than hardcoding the resolved slug, which
  // would reintroduce the same fragility this function exists to avoid.
  const movedNotice = page.getByText(/this page has moved/i);
  if (await movedNotice.isVisible({ timeout: 3_000 }).catch(() => false)) {
    await page.locator("a").first().click();
    await page.waitForLoadState("networkidle");
  }

  const addToCartButton = page.locator('button[data-button-action="add-to-cart"]').first();
  await addToCartButton.waitFor({ state: "visible" });

  // Wait for the actual add-to-cart ajax call to complete rather than a
  // fixed sleep (TWO-25110 review finding: this harness's own
  // no-blind-sleeps convention - see boot-prestashop.sh's header).
  const [cartResponse] = await Promise.all([
    page.waitForResponse(
      (r) => r.url().includes("/module/ps_shoppingcart/ajax") && r.request().method() === "POST"
    ),
    addToCartButton.click()
  ]);
  if (!cartResponse.ok()) {
    throw new Error(`add-to-cart ajax call failed with status ${cartResponse.status()}`);
  }

  await page.goto("/order");
  await page.waitForLoadState("networkidle");
}
