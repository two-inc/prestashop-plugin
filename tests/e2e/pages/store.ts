import { type Page } from "@playwright/test";

/**
 * Adds the first available demo product to the cart and opens the
 * checkout page. PS docker images ship a fixed demo catalog on
 * auto-install, so hard-coding this path is stable across PS 8/9.
 */
export async function addFirstProductToCartAndGoToCheckout(page: Page) {
  await page.goto("/");
  await page.waitForLoadState("networkidle");

  const thumbnail = page.locator("a.product-thumbnail").first();
  const href = await thumbnail.getAttribute("href");
  if (!href) {
    throw new Error("no demo product thumbnail found on the homepage");
  }

  await page.goto(href);
  await page.waitForLoadState("networkidle");
  await page.locator('button[data-button-action="add-to-cart"]').first().click();
  // Give the add-to-cart ajax call + modal a moment before navigating away.
  await page.waitForTimeout(1000);

  await page.goto("/order");
  await page.waitForLoadState("networkidle");
}
