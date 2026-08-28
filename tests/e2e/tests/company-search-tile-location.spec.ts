import { execFileSync } from "node:child_process";

import { test, expect } from "@playwright/test";

import { addFirstProductToCartAndGoToCheckout } from "../pages/store.js";
import { completeGuestStep, twoPaymentOption } from "../pages/checkout.js";

/**
 * TWO-25326 §7.1 (2026-08-03 design ruling, with two follow-up corrections
 * from Doug). No new admin setting was added - the EXISTING
 * PS_ENABLE_COMPANY_SEARCH_IN_ADDRESS switch ("Enable company search in address
 * entry" - the shipped label, pinned by tests/AddressLookupGatingSpec.php) now
 * decides WHERE the one shared company-search control renders:
 *
 *   - Yes (default): the control (dropdown/query field/manual entry) is in
 *     the address area, unchanged from before this ticket.
 *   - No: the SAME control instead renders in the payment tile. The
 *     address area's native `company` field is NOT hidden or removed - it
 *     stays visible and typeable, just without the search enhancement (a
 *     bug found on woocommerce-plugin that this suite checks does not
 *     recur here).
 *
 * This suite drives both settings for real, against a real running
 * checkout. The toggle itself runs via `docker exec`, from the test
 * process, against whichever container this job already booted - same
 * PS_CONTAINER/SFX convention as dev/ci/seed-carrierless-cart.sh and
 * run-integration-probes.sh (PS_CONTAINER overrides for a shop this harness
 * did not boot; ps-$SFX is how the e2e.yml/boot-prestashop.sh CI job names
 * it). There is no per-test hook in the e2e.yml workflow to run a shell step
 * between tests, so the spec has to be able to reach the container itself.
 */
function setCompanySearchInAddressArea(enabled: boolean): void {
  const container = process.env.PS_CONTAINER ?? `ps-${process.env.SFX}`;
  execFileSync("docker", [
    "exec",
    "-u",
    "www-data",
    container,
    "php",
    "-d",
    "memory_limit=512M",
    "-r",
    `require "/var/www/html/config/config.inc.php"; Configuration::updateValue("PS_ENABLE_COMPANY_SEARCH_IN_ADDRESS", ${enabled ? 1 : 0});`
  ]);
  execFileSync("docker", ["exec", container, "bash", "-c", "rm -rf /var/www/html/var/cache/*"]);
}

test.describe("TWO-25326 §7.1 company-search location", () => {
  test("address area (default): full search control renders in the address step, no tile mount", async ({
    page
  }) => {
    setCompanySearchInAddressArea(true);
    await addFirstProductToCartAndGoToCheckout(page);
    await completeGuestStep(page, `location-address-${Date.now()}@example.com`);

    const addr = page.locator("#checkout-addresses-step");
    const companyField = addr.locator('input[name="company"]');
    await expect(companyField).toBeVisible();

    // The tile mount only ever renders when the switch is "No" - it must not
    // exist in the DOM at all with the default (Yes / address-area) setting.
    await expect(page.locator("#two-tile-company-search")).toHaveCount(0);

    // Same TwoCompanySearch behaviour as before this ticket (§1): clicking
    // the field opens the anchored dropdown panel.
    await companyField.click();
    await expect(page.locator(".two-company-dropdown")).toBeVisible({ timeout: 10_000 });
  });

  test("payment tile: address-area company field stays visible and plain (never hidden), tile mount renders and is interactive", async ({
    page
  }) => {
    setCompanySearchInAddressArea(false);
    await addFirstProductToCartAndGoToCheckout(page);
    await completeGuestStep(page, `location-tile-${Date.now()}@example.com`);

    const addr = page.locator("#checkout-addresses-step");
    const companyField = addr.locator('input[name="company"]');

    // The address area's native `company` field must stay visible and
    // typeable - never hidden, never removed. Confirmed regression on
    // woocommerce-plugin (2026-08-04); this is the PS-side check for the
    // same bug.
    await expect(companyField).toBeVisible();
    await expect(companyField).toBeEditable();
    // Plain, unenhanced: no search/autocomplete attached in this mode -
    // clicking it must NOT open the dropdown panel that the address-area
    // control opens in the other test.
    await companyField.click();
    await expect(page.locator(".two-company-dropdown")).toHaveCount(0);
    await companyField.fill("Plain Typed Company Name");
    await expect(companyField).toHaveValue("Plain Typed Company Name");

    // Fill the rest of the address and advance.
    await addr.locator('select[name="id_country"]').selectOption({ label: "United States" });
    await page.waitForLoadState("networkidle");
    await addr.locator('input[name="firstname"]').fill("Test");
    await addr.locator('input[name="lastname"]').fill("Buyer");
    await addr.locator('input[name="address1"]').fill("123 Test Street");
    await addr.locator('input[name="city"]').fill("Testville");
    await addr.locator('input[name="postcode"]').fill("10001");
    await addr.locator('input[name="phone"]').fill("+447777777777");
    const stateSelect = addr.locator('select[name="id_state"]');
    if ((await stateSelect.count()) > 0 && (await stateSelect.isVisible())) {
      await stateSelect.selectOption({ index: 1 });
    }
    await addr.locator('button[name="submitAddress"], button[type="submit"]').first().click();
    await page.waitForLoadState("networkidle");

    const delivery = page.locator("#checkout-delivery-step");
    if (await delivery.locator('button[name="confirmDeliveryOption"]').isVisible().catch(() => false)) {
      await delivery.locator('button[name="confirmDeliveryOption"]').click();
      await page.waitForLoadState("networkidle");
    }

    // The tile's "additionalInformation" content (which carries the tile
    // mount, per PrestaShop's own PaymentOption rendering) is only visible
    // for the SELECTED payment method - selecting Two here is what a real
    // buyer does before it ever becomes visible, matching
    // selectTwoPaymentAndPlaceOrder()'s own use of this same locator.
    await twoPaymentOption(page).check({ force: true });
    await page.waitForLoadState("networkidle");

    // The tile mount: rendered only because PS_ENABLE_COMPANY_SEARCH_IN_ADDRESS=0,
    // per paymentinfo.tpl's {if $company_search_tile}.
    const tileField = page.locator("#two_tile_company");
    await expect(tileField).toBeVisible({ timeout: 15_000 });

    // Same TwoCompanySearch.js control, just mounted here (§7.1: "genuinely
    // move/reuse the component"): clicking it must open the same dropdown
    // behaviour as the address-area control does in the other test.
    await tileField.click();
    await expect(page.locator(".two-company-dropdown")).toBeVisible({ timeout: 10_000 });
    // "Enter Manually" is suppressed here (TWO-25503: manual entry captures no
    // company number and only the address-step lookup does), and this country
    // has no sole-trader registry, which leaves "Registered company" as the
    // only chip - the mode the buyer is already in. A row offering one chip
    // offers no choice, so it is not rendered (TWO-40).
    await expect(page.locator(".two-company-mode-chips")).toHaveCount(1);
    await expect(page.locator(".two-company-mode-chips")).toBeHidden();
    await expect(page.locator("button.two-company-not-listed")).toBeHidden();

    // And it SEARCHES, not merely opens (TWO-25326 §7.1 follow-up). The
    // control opened correctly while being completely unable to search: on
    // this step PrestaShop renders an address selector rather than the address
    // form, so `select[name='id_country']` - the only country source the
    // browser side had - does not exist, and every keystroke resolved no
    // country and declined to search. The buyer-visible symptom is this row,
    // pointing at a country control that is not on the page.
    //
    // Asserted as the absence of that row rather than on results, so it needs
    // no live company register and cannot flake on one: typing enough
    // characters to pass the search threshold has to get past the "no country"
    // state, whatever the register then answers.
    const queryField = page.locator(".two-company-dropdown__query");
    await queryField.fill("Example");
    await expect(
      page.locator(".two-company-dropdown__results", {
        hasText: "Select your country above to search for your company."
      })
    ).toHaveCount(0, { timeout: 10_000 });
  });
});
