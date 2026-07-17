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
  // PS 9 only renders/reveals #field-password when the "Create an account
  // (optional)" checkbox above it is ticked — for a plain guest checkout
  // (this suite never ticks it) the field exists in the DOM but is hidden,
  // so an unconditional .fill() times out waiting for visibility. PS 8
  // shows this field unconditionally for guest checkout, so still fill it
  // there. Mirrors the visibility-gated pattern already used for the
  // optional state <select> in completeAddressStep below.
  const password = guest.locator('input[name="password"]');
  if ((await password.count()) > 0 && (await password.isVisible())) {
    await password.fill("TwoE2eTestPassw0rd!");
  }
  // Both are required checkboxes on the PS demo fixture (data privacy +
  // GDPR consent) — the form silently no-ops on submit without them.
  await guest.locator('input[name="customer_privacy"]').check();
  await guest.locator('input[name="psgdpr"]').check();
  await guest.locator('button[type="submit"]').first().click();
  await page.waitForLoadState("networkidle");
}

/**
 * Fills and submits the address step with a company name set. This gets
 * past controllers/front/payment.php's local, network-free pre-flight
 * guard's "no company at all" branch and as far as its "no *verified*
 * organization number" branch (getTwoCheckoutCompanyData ->
 * getCompanyDataWithFallbacks in twopayment.php - a pure local check
 * against the Address row and session cookies, no HTTP call). TWO-25110
 * review: this is NOT the same gate as checkTwoOrderIntentApprovalAtPayment
 * (the real /v1/order_intent live-API call) - that call is never reached
 * here, since the pre-flight guard above returns first. A real
 * organization-number match requires driving Two's live company search
 * widget, which needs a real merchant key; out of scope for this hermetic
 * suite (see seed-two-config.sh).
 */
export async function completeAddressStep(page: Page, company: string) {
  const addr = page.locator("#checkout-addresses-step");
  // PS 9's demo fixture defaults the address country to Norway, whose
  // postcode rule (NNNN) rejects the "10001" filled below — the step then
  // silently re-renders with a validation alert instead of advancing, and
  // completeDeliveryStep times out one step later (confirmed via the PS 9
  // CI run's accessibility snapshot: 'Invalid postcode - should look like
  // "NNNN"', country combobox stuck on Norway). PS 8's fixture defaults to
  // a country that accepts this postcode, which is why it passes there.
  // Pin the country explicitly so the postcode below is valid on both.
  // Selecting a country makes PS re-render the address form via AJAX
  // (country-specific fields like the US state <select> appear), so do it
  // FIRST and let the reload settle before filling anything.
  await addr.locator('select[name="id_country"]').selectOption({ label: "United States" });
  await page.waitForLoadState("networkidle");
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
 * The client-side order-intent check (views/js/modules/
 * TwoCheckoutManager.js's handleOrderIntentResult -> showOrderIntentError)
 * runs as soon as Two is selected as the payment method, well before the
 * buyer ever reaches "place order" - an unresolved company (as here: a
 * company name typed into the plain text field, never through Two's live
 * search widget, so no organization number resolves) surfaces this exact
 * guidance into `.two-payment-info`, the theme-independent container the
 * paymentinfo.tpl hook template always renders alongside the Two payment
 * option on every PS version.
 *
 * This is NOT the old assumption (fixed as part of TWO-24755's backport):
 * that assumption was that an unverified company falls through silently
 * to controllers/front/payment.php's own pre-flight guard, flashed via
 * PS's `#notifications` area, only at submit time. That's no longer
 * reachable by default - TWO-24755 removed the PS_TWO_USE_ACCOUNT_TYPE
 * toggle that used to gate order-intent's client-side submit-prevention
 * off, so the decline is now always caught client-side, pre-submit,
 * before payment.php is ever hit. `#notifications` was also never a
 * reliable target for that path in the first place: the PS 8 CI run's
 * trace showed it falling back to a native `alert()` dialog (via
 * TwoOrderIntent.js's showOrderPreventionMessage, since
 * window.prestashop.notification doesn't exist on PS 8's classic theme),
 * which Playwright silently auto-dismisses - the message never reached
 * the DOM there at all. That alert() fallback has been fixed to reuse the
 * same theme-independent container instead, but `.two-payment-info`
 * remains the earlier, more reliable signal since it never depended on
 * submit-time timing to begin with.
 */
export async function expectCompanyRequiredRejection(page: Page) {
  await expect(page.locator(".two-payment-info").first()).toContainText(/select your company/i, {
    timeout: LONG_TIMEOUT
  });
}
