import { test, expect } from "@playwright/test";

import { BUYER_COMPANY } from "../config.js";
import { addFirstProductToCartAndGoToCheckout } from "../pages/store.js";
import {
  completeGuestStep,
  completeAddressStep,
  completeDeliveryStep,
  twoPaymentOption,
  selectTwoPaymentAndPlaceOrder,
  expectCompanyRequiredRejection
} from "../pages/checkout.js";

// Unique per test run so PrestaShop's "email already registered" guard on
// the guest-checkout form never blocks a retry within the same container.
// (Playwright gives each test() its own BrowserContext by default - no
// storageState/context reuse configured in playwright.config.ts - so
// cookies/cart/session never leak between the two tests below or across
// a retry; this email uniqueness is only guarding the one thing that
// *does* persist server-side: the customer record itself.)
function uniqueEmail(): string {
  return `two-e2e-${Date.now()}-${Math.floor(Math.random() * 1e6)}@two.inc`;
}

test.describe("Two payment method at checkout", () => {
  test("Two option renders on the payment step for a B2B buyer", async ({ page }) => {
    await addFirstProductToCartAndGoToCheckout(page);
    await completeGuestStep(page, uniqueEmail());
    await completeAddressStep(page, BUYER_COMPANY);
    await completeDeliveryStep(page);

    await expect(page.locator("#checkout-payment-step")).toBeVisible();
    await expect(twoPaymentOption(page)).toBeVisible();
  });

  test("placing an order without a verified company is declined gracefully", async ({
    page
  }) => {
    // This suite runs hermetically (no real Two merchant credentials — see
    // dev/ci/seed-two-config.sh). Two's own checkout-time gate requires a
    // company verified through its live search widget, so this is the
    // deterministic outcome any unverified/dummy key produces: a graceful,
    // non-fatal decline rather than a silently-created order. That gate
    // itself is real product behaviour worth covering, not a workaround.
    await addFirstProductToCartAndGoToCheckout(page);
    await completeGuestStep(page, uniqueEmail());
    await completeAddressStep(page, BUYER_COMPANY);
    await completeDeliveryStep(page);
    await selectTwoPaymentAndPlaceOrder(page);

    await expectCompanyRequiredRejection(page);
    // No fatal, and no order silently created — still on the checkout page.
    await expect(page).toHaveURL(/\/order/);
  });
});
