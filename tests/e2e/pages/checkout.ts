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
/**
 * Put a company name into the address form.
 *
 * TWO-25326 made the company-name field a search TRIGGER rather than a text
 * box: in search mode it carries `readonly`, clicking it opens the dropdown
 * panel, and typing is routed into the panel's own query field so the buyer
 * cannot overwrite a confirmed name by hand. A plain `.fill()` therefore times
 * out with "element is not editable" — which is the control working as
 * specified, not a regression.
 *
 * The route to an arbitrary, unverified name is manual entry, so that is what
 * this drives: open the panel, take "My company is not on the list", then type
 * into the now-editable field. That is also the flow these tests want — both
 * callers supply a name that is deliberately NOT a real registered company.
 *
 * Falls back to a direct fill when the field is already editable, so the tests
 * still work on a build where the module's checkout JS never ran (no
 * `checkoutHost` configured, assets not registered). Without that fallback a
 * genuine asset-loading regression would surface here as a confusing timeout
 * rather than as the payment-option assertion these tests actually make.
 */
async function fillCompanyName(page: Page, addr: Locator, company: string) {
  const field = addr.locator('input[name="company"]');
  const isReadonly = await field.getAttribute("readonly");

  if (isReadonly !== null) {
    await field.click();
    const notListed = addr.locator("button.two-company-not-listed");
    await notListed.click();

    // enterManualEntryMode() strips `readonly` and focuses the field.
    //
    // This is the assertion that caught the reflow bug: pressing the button
    // blurred the query field, which emptied the results area directly above
    // it, which moved the button between mousedown and mouseup - so the
    // browser dispatched `click` on an ancestor and the button's own handler
    // never ran. See freezeResultsHeight() in TwoCompanySearch.js. It is
    // asserted here rather than only in Jest because jsdom has no layout and
    // cannot see an element move.
    await expect(field).not.toHaveAttribute("readonly", /.*/, {
      timeout: 5000,
    });
  }

  await field.fill(company);
  await expect(field).toHaveValue(company);
}

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
  await fillCompanyName(page, addr, company);
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
  // Selecting Two kicks off an async order-intent AJAX check (see
  // expectCompanyRequiredRejection's docblock below) that the real
  // checkout-payment-step UI depends on to decide whether to allow
  // submission. A real buyer takes at least a few seconds reading the
  // payment step before clicking submit, giving that check time to
  // settle; waiting here mirrors that instead of racing it.
  await page.waitForLoadState("networkidle");
  await payment.locator('input[name="conditions_to_approve[terms-and-conditions]"]').check();
  await payment.locator("#payment-confirmation button[type=submit]").click();
  await page.waitForLoadState("networkidle");
}

/**
 * TWO-24755 removed PS_TWO_USE_ACCOUNT_TYPE, which used to gate off
 * order-intent's client-side submit-prevention by default. That toggle
 * being gone means there are now genuinely TWO valid places an unresolved
 * company (as here: a company name typed into the plain text field,
 * never through Two's live search widget, so no organization number
 * resolves) can surface its rejection, and this test's own helper
 * (selectTwoPaymentAndPlaceOrder above) doesn't wait for the order-intent
 * AJAX check to settle before clicking submit - so which one fires is a
 * genuine race, confirmed by CI: the same commit passed on PS 8 via one
 * path and failed on PS 9 via the other, in a single run:
 *
 *  1. Client-side: TwoCheckoutManager.js's handleOrderIntentResult ->
 *     showOrderIntentError, which runs as soon as Two is selected and
 *     writes this same guidance into `.two-payment-info` before the
 *     buyer ever reaches "place order" - if this AJAX call resolves
 *     before the click, submission never leaves the browser.
 *  2. Server-side: if the click wins the race, submission reaches
 *     controllers/front/payment.php's own (unchanged-by-this-PR)
 *     pre-flight guard, which redirects back to the same /order checkout
 *     page and flashes the identical copy via PS's `#notifications` area
 *     instead.
 *
 * Both are a correct "declined gracefully" outcome - a production buyer
 * reads the payment step for more than a few milliseconds before
 * clicking, so the client-side path wins in practice; the server-side
 * path is what exercises payment.php's guard as defence-in-depth. Assert
 * on the guidance text itself, wherever it lands, rather than betting on
 * one specific race outcome.
 */
export async function expectCompanyRequiredRejection(page: Page) {
  await expect(page.getByText(/select your company/i).first()).toBeVisible({
    timeout: LONG_TIMEOUT
  });
}
