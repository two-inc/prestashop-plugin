import { type Page, type Locator, expect } from "@playwright/test";

import { PHONE_NUMBER, LONG_TIMEOUT } from "../config.js";

/**
 * Completes the "Personal information" guest-checkout step. Field IDs
 * (#field-firstname etc.) collide with the sign-in tab's IDs on the same
 * page, so every locator here is scoped under #checkout-guest-form.
 */
export async function completeGuestStep(page: Page, email: string) {
  const guest = page.locator("#checkout-guest-form");
  await guest.locator("#field-firstname").fill("Test");
  await guest.locator("#field-lastname").fill("Buyer");
  await guest.locator("#field-email").fill(email);
  await guest.locator('input[name="password"]').fill("TwoE2eTestPassw0rd!");
  // Both are required checkboxes on the PS demo fixture (data privacy +
  // GDPR consent) — the form silently no-ops on submit without them.
  await guest.locator('input[name="customer_privacy"]').check();
  await guest.locator('input[name="psgdpr"]').check();
  await guest.locator('button[type="submit"]').first().click();
  await page.waitForLoadState("networkidle");
}

/**
 * Fills and submits the address step with a company name set (Two's
 * checkout gate gets past "no company" and as far as "no *verified*
 * company" — see checkTwoOrderIntentApprovalAtPayment in twopayment.php).
 * A real organization-number match requires driving Two's live company
 * search widget, which needs a real merchant key; out of scope for this
 * hermetic suite (see seed-two-config.sh).
 */
export async function completeAddressStep(page: Page, company: string) {
  const addr = page.locator("#checkout-addresses-step");
  await addr.locator('input[name="firstname"]').fill("Test");
  await addr.locator('input[name="lastname"]').fill("Buyer");
  await addr.locator('input[name="company"]').fill(company);
  await addr.locator('input[name="address1"]').fill("123 Test Street");
  await addr.locator('input[name="city"]').fill("Testville");
  await addr.locator('input[name="postcode"]').fill("10001");
  await addr.locator('input[name="phone"]').fill(PHONE_NUMBER);

  const stateSelect = addr.locator('select[name="id_state"]');
  if ((await stateSelect.count()) > 0 && (await stateSelect.isVisible())) {
    await stateSelect.selectOption({ index: 1 });
  }

  await addr.locator('button[name="submitAddress"], button[type="submit"]').first().click();
  await page.waitForLoadState("networkidle");
}

export async function completeDeliveryStep(page: Page) {
  const delivery = page.locator("#checkout-delivery-step");
  await delivery.locator('button[name="confirmDeliveryOption"]').click();
  await page.waitForLoadState("networkidle");
}

/** The Two payment radio option, identified by its module name — stable
 * across PS 8/9 core template changes even if PrestaShop's own generated
 * "payment-option-N" id shifts. */
export function twoPaymentOption(page: Page): Locator {
  return page.locator('input[data-module-name="twopayment"]');
}

export async function selectTwoPaymentAndPlaceOrder(page: Page) {
  const payment = page.locator("#checkout-payment-step");
  await twoPaymentOption(page).check({ force: true });
  await payment.locator('input[name="conditions_to_approve[terms-and-conditions]"]').check();
  await payment.locator("#payment-confirmation button[type=submit]").click();
  await page.waitForLoadState("networkidle");
}

/**
 * Two's own checkout-time gate rejects an unverified/uncommitted company
 * with this exact copy (twopayment.php: getTwoCheckoutCompanyData caller).
 * A dummy/unverified merchant key can never get further than this in a
 * hermetic CI run — see seed-two-config.sh header for why that's expected,
 * not a test bug.
 */
export async function expectCompanyRequiredRejection(page: Page) {
  await expect(page.locator("article.alert-danger")).toContainText(
    /select your company/i,
    { timeout: LONG_TIMEOUT }
  );
}
