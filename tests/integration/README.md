# Two Payment Integration Matrix (PrestaShop Real Engine)

This folder holds the real-engine integration probes for order-build parity across
PrestaShop versions, plus the still-unbuilt scenario matrix below them.

## Implemented probes

These run in CI on every pull request — `.github/workflows/integration.yml`, PrestaShop 1.7.6, 8 and 9 — and locally against the dev shop with `make test-integration`. Each is a plain PHP script executed inside the PrestaShop container by `dev/ci/run-integration-probes.sh`, and each is hermetic: no browser, no network, no Two credentials.

| Probe | What it pins down |
| --- | --- |
| `default-shipping-tax-code.php` | The optional **Default shipping tax code** on a cart whose shipping is priced but whose delivery option belongs to no carrier. Asserts the real order-intent `SHIPPING_FEE` line (`gross_amount` / `net_amount` / `tax_amount` / `tax_rate` / `tax_class_name`) and the log severity, across four states: unset → refuse at severity 3; group declared → that group's rate relayed at severity 2; core's "No tax" sentinel → 0%; a since-deleted group → refuse at severity 3. |
| `line-item-image.php` | The line-item `image_url` against the cart rows core builds (TWO-26071). A combination with its own image sends that image rather than the product cover, a combination without one sends the cover, and a product with no image sends an empty `image_url`. Builds its own products and cart. |
| `multi-carrier-update.php` | An order update across a multi-carrier split (TWO-26085). Places a cart whose two products can only ship with different carriers through `validateOrder()`, so core splits it into two orders sharing one reference and one payment, records the Two row on the order core returns, then edits the other order. Asserts the update carries both orders' lines and shipping and names the order holding the Two row, and that the shared payment is left at both orders' total. Builds its own taxes, carriers, products, buyer and cart. |
| `credit-slip-refund.php` | Credit slips and the Refunded status reach Two as refunds (TWO-26093). Places a Two order with two tax rates and shipping and one whose product compounds two taxes, then refunds them as the back office does: core's partial-refund command from 1.7.7, `OrderSlip::create()` plus the hook call `AdminOrdersController` makes on 1.7.6. Core fires the hook into the probe's module instance, whose API calls are captured. Asserts a slip for 1 of 3 units, a specific-amount slip and a compound-tax slip each send the amount core refunded with `tax_subtotals` summing to it, that two slips written before either hook call are sent once each, and that marking the order Refunded sends only what is left, once, also after a refund made in the portal. Builds its own taxes, carrier, products, buyer and orders. |
| `discrepancy-snapshot.php` | The discrepancy snapshot (TWO-26064) on the same carrier-less cart: an untaxed and a taxed injected shipping cost are each refused with exactly one snapshot row that classifies as shape A / B, decodes as JSON, fits the size cap and carries no buyer name, email or street; Debug Mode leaves a baseline row for a passing cart, and a passing cart without it leaves none. |
| `order-postprocessing-hook.php` | The order postprocessing hook (TWO-26092) through the module's own dispatch to real modules registered in core, with `tests/integration/fixtures/twoorderpostprocessingtest` subscribed. On its own cart (a 21% product, 29.00 of shipping on a "No tax" carrier) and order it drives every request type (intent pre-check and strict, create, the confirmation hash, update, confirm, capture, full and partial refund, cancel) and asserts each fires exactly once with the contract's context and sends the post-hook payload; the README re-split, a gross change, a line off by a cent, stale totals, stale subtotals and a body on cancel are all sent as the subscriber returned them; a subscriber that throws (including with debug mode forced off, where core 8 and 9 would discard the exception) or leaves a non-array is refused with its code; `shipping_tax_rate` follows the carrier's tax rules group. Runs on 1.7.6 too: it needs no delivery-option injection. `dev/ci/install-order-postprocessing-fixture.sh` installs the fixture. |

### Carrier-less shipping matrix

`make carrierless-matrix` (CI: the "Carrier-less shipping matrix" job) boots a throwaway PrestaShop, installs the module from the working tree, and runs `matrix/carrierless-shipping-cell.php` once per cell, printing a table of the `SHIPPING_FEE` line, order totals and outcome from `getTwoNewOrderData()`. It records rather than asserts: a cell fails only when its cart shape could not be reproduced. Modes, driven through the same fixture module and its Cart override (`getExternalShippingCost()`, `two_test_external_shipping` table):

| Mode | Cart shape |
| --- | --- |
| A | `id_carrier = 0`, no shipping tax rules group; `ONLY_SHIPPING` 29.00 incl == excl (PrestaShop sees 0% shipping VAT) |
| B | `id_carrier = 0`, no shipping tax rules group; `ONLY_SHIPPING` 29.00 incl / 23.97 excl (21%) |
| C | `id_carrier = 0`, no shipping tax rules group; `ONLY_SHIPPING` 0; 29.00 only via `getExternalShippingCost()`, added untaxed to `getOrderTotal(*, BOTH)` |
| D | A real carrier declaring a 21% group; the product line declares 21% but carries a fixed untaxed 120.00 on its net, so its tax covers only the base. Configs 1–3 only |

Configs 1–3 set the Default shipping tax code to unset / a 21% group / "No tax". Configs 4a/4b/5a/5b add a merchant `TwopaymentOverride`, with its tax-rate setting unset / 21, and default code unset (4) or 21% (5). Nothing merchant-specific is committed; those cells need three env vars:

| Env var | Holds |
| --- | --- |
| `MERCHANT_OVERRIDE_PATH` | the `TwopaymentOverride` file |
| `MERCHANT_SHIM_PATH` | a dir with a `Cart.php` that replaces the fixture's Cart override for those cells (adapting it to what the override calls), plus an optional `install.php` run in the shop before them |
| `MERCHANT_RATE_CONFIG_KEY` | the Configuration key the override reads its tax rate from |

The matrix pulls its images on every run; `PULL=0 make carrierless-matrix` reuses locally cached ones.

#### Matrix invariants

- The matrix runs only on a container it created, and removes that container on exit, whether the run passes, fails or is killed. A later run never depends on or inherits a previous run's state. A run killed with SIGKILL cannot clean up, so the next run removes its leftovers before booting.
- Setup is strict: any failure aborts before any cell runs.
- A cell is reported only if its cart-shape checks pass. Plugin gates are never bypassed.
- Merchant-specific identifiers never enter the repo. Merchant-shaped pieces are injected from out-of-tree paths via env vars.

### Why a probe and not another unit spec

`tests/DefaultShippingTaxCodeSpec.php` already proves the decision logic, but against a hand-rolled core stub — so it can only prove the logic is right *about a cart shape it asserts into existence*. Verified on PrestaShop 8.2.7, that shape does not arise from a broken carrier setup: core's `Cart::getDeliveryOptionList()` discards the entire delivery-option list on its no-carrier sentinel (`Cart.php:2921`) and `Cart::getOrderTotal(*, ONLY_SHIPPING)` derives from that same list, so a coverage gap yields shipping of `0.00` and exercises nothing at all.

It takes a module that **injects** a delivery option belonging to no carrier, through `actionFilterDeliveryOptionList` (`Cart.php:3163`) — which fires *after* that sentinel, so carrier coverage has to stay **intact** for the injection to run. `tests/integration/fixtures/twocarrierlesstest` is that module, and it ships inert until armed. `dev/ci/seed-carrierless-cart.sh` installs it and builds the customer, address, tax rules group and cart, entirely through ObjectModel rather than SQL.

### Adding a probe

Drop a `*.php` file directly under `tests/integration/`; `run-integration-probes.sh` discovers it, and a non-zero exit fails the job. Add it to the `php -l` list in `.github/workflows/tests.yml` too, so a syntax error is caught without booting a container.

## Target versions

- PrestaShop `8.x`
- PrestaShop `9.x`
- PrestaShop `1.7.8` — the module's declared floor, but Docker Hub has dropped all
  `prestashop/prestashop` 1.7 tags, so there is no CI image to boot for that line.

## Mandatory scenarios

- Mixed VAT rates + fixed voucher
- Mixed VAT rates + percentage voucher
- Free shipping cart rule only
- Free shipping + additional cart discount
- Gift wrapping (taxed and zero-tax variants)
- Ecotax product in cart (when store config enables ecotax)
- Zero-tax + taxed product mix
- Specific-price reduction + cart-rule discount combination
- Rounding mode variants:
- `ROUND_ITEM`
- `ROUND_LINE`
- `ROUND_TOTAL`

## Assertions per scenario

- Two payload line items reconcile to cart totals:
- `sum(net_amount) == cart net` (tolerance 0.02 unless scenario expects hard-block)
- `sum(tax_amount) == cart tax`
- `sum(gross_amount) == cart gross`
- `gross == net + tax` for each line item
- `tax_amount == net_amount * tax_rate` within formula tolerance
- `tax_subtotals` match line-item tax aggregation
- Payment-submit authoritative order intent uses strict reconciliation gate
- Callback local order creation is race-safe (no duplicate local orders)

## Execution notes

- These are **integration tests** against a running PrestaShop instance, not the offline unit harness in `tests/run.php`.
- Keep provider-first flow intact:
- No local order creation before successful provider verification callback.
- Build payload from actual cart state after cart rules are applied.
- Record scenario evidence (request payload, cart totals, and outcome) for each PrestaShop version.
