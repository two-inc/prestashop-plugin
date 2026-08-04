import { execFileSync } from "node:child_process";

import { test, expect } from "@playwright/test";

import { addFirstProductToCartAndGoToCheckout } from "../pages/store.js";
import { completeGuestStep } from "../pages/checkout.js";

/**
 * TWO-25326 §7.1 (2026-08-03 design ruling): PrestaShop had no
 * "company search location" setting before this ticket - the control was
 * always in the address area. This suite drives both settings for real,
 * against a real running checkout: the admin toggle is flipped directly via
 * Configuration (same hermetic pattern as seed-two-config.sh - no live Two
 * API dependency), not through the back office UI, since the UI itself is
 * exercised by CompanySearchTileConfigSpec (offline) and this suite exists
 * to prove the CHECKOUT reads that setting correctly.
 *
 * The toggle itself runs via `docker exec`, from the test process, against
 * whichever container this job already booted - same PS_CONTAINER/SFX
 * convention as dev/ci/seed-carrierless-cart.sh and
 * run-integration-probes.sh (PS_CONTAINER overrides for a shop this harness
 * did not boot; ps-$SFX is how the e2e.yml/boot-prestashop.sh CI job names
 * it). There is no per-test hook in the e2e.yml workflow to run a shell step
 * between tests, so the spec has to be able to reach the container itself.
 */
function setCompanySearchTileEnabled(enabled: boolean): void {
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
    `require "/var/www/html/config/config.inc.php"; Configuration::updateValue("PS_TWO_COMPANY_SEARCH_TILE", ${enabled ? 1 : 0});`
  ]);
  execFileSync("docker", ["exec", container, "bash", "-c", "rm -rf /var/www/html/var/cache/*"]);
}

test.describe("TWO-25326 §7.1 company-search location", () => {
  test("address area (default): company field renders in the address step, no tile mount", async ({
    page
  }) => {
    setCompanySearchTileEnabled(false);
    await addFirstProductToCartAndGoToCheckout(page);
    await completeGuestStep(page, `tile-off-${Date.now()}@example.com`);

    const addr = page.locator("#checkout-addresses-step");
    const companyField = addr.locator('input[name="company"]');
    await expect(companyField).toBeVisible();

    // The tile mount only ever renders when the setting is on - it must not
    // exist in the DOM at all with the default (address-area) setting.
    await expect(page.locator("#two-tile-company-search")).toHaveCount(0);

    // Same TwoCompanySearch behaviour as before this ticket (§1): clicking
    // the field opens the anchored dropdown panel.
    await companyField.click();
    await expect(page.locator(".two-company-dropdown")).toBeVisible({ timeout: 10_000 });
  });

  test("payment tile: address-area company field is hidden, tile mount renders and is interactive", async ({
    page
  }) => {
    setCompanySearchTileEnabled(true);
    await addFirstProductToCartAndGoToCheckout(page);
    await completeGuestStep(page, `tile-on-${Date.now()}@example.com`);

    const addr = page.locator("#checkout-addresses-step");
    const companyField = addr.locator('input[name="company"]');

    // The field (or its wrapper) still exists in the DOM - PrestaShop's own
    // address form still declares it - but TwoCheckoutManager's
    // hideAddressAreaCompanyField() must have hidden it, since the control
    // has relocated to the tile.
    if ((await companyField.count()) > 0) {
      await expect(companyField).not.toBeVisible();
    }

    // Fill the rest of the address without a company (manual entry / no
    // control here) and advance.
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

    // The tile mount: rendered only because PS_TWO_COMPANY_SEARCH_TILE=1,
    // per paymentinfo.tpl's {if $company_search_tile}.
    const tileField = page.locator("#two_tile_company");
    await expect(tileField).toBeVisible({ timeout: 15_000 });

    // Same TwoCompanySearch.js control, just mounted here (§7.1: "genuinely
    // move/reuse the component"): clicking it must open the same dropdown
    // behaviour as the address-area control does.
    await tileField.click();
    await expect(page.locator(".two-company-dropdown")).toBeVisible({ timeout: 10_000 });
    await expect(page.locator("button.two-company-not-listed")).toBeVisible();
  });
});
