# Two Payment Integration Matrix (PrestaShop Real Engine)

This folder documents the real-engine integration matrix required for order build parity validation across PrestaShop versions.

## Implemented probes

These run in CI on every pull request — `.github/workflows/integration.yml`, PrestaShop 8 and 9 — and locally against the dev shop with `make test-integration`. Each is a plain PHP script executed inside the PrestaShop container by `dev/ci/run-integration-probes.sh`, and each is hermetic: no browser, no network, no Two credentials.

| Probe | What it pins down |
| --- | --- |
| `default-shipping-tax-code.php` | The optional **Default shipping tax code** (TWO-25200) on a cart whose shipping is priced but whose delivery option belongs to no carrier. Asserts the real order-intent `SHIPPING_FEE` line (`gross_amount` / `net_amount` / `tax_amount` / `tax_rate` / `tax_class_name`) and the log severity, across four states: unset → refuse at severity 3; group declared → that group's rate relayed at severity 2; core's "No tax" sentinel → 0%; a since-deleted group → refuse at severity 3. |

### Why a probe and not another unit spec

`tests/DefaultShippingTaxCodeSpec.php` already proves the decision logic, but against a hand-rolled core stub — so it can only prove the logic is right *about a cart shape it asserts into existence*. Verified on PrestaShop 8.2.7, that shape does not arise from a broken carrier setup: core's `Cart::getDeliveryOptionList()` discards the entire delivery-option list on its no-carrier sentinel (`Cart.php:2921`) and `Cart::getOrderTotal(*, ONLY_SHIPPING)` derives from that same list, so a coverage gap yields shipping of `0.00` and exercises nothing at all.

It takes a module that **injects** a delivery option belonging to no carrier, through `actionFilterDeliveryOptionList` (`Cart.php:3163`) — which fires *after* that sentinel, so carrier coverage has to stay **intact** for the injection to run. `tests/integration/fixtures/twocarrierlesstest` is that module, and it ships inert until armed. `dev/ci/seed-carrierless-cart.sh` installs it and builds the customer, address, tax rules group and cart, entirely through ObjectModel rather than SQL.

### Adding a probe

Drop a `*.php` file directly under `tests/integration/`; `run-integration-probes.sh` discovers it, and a non-zero exit fails the job. Add it to the `php -l` list in `.github/workflows/tests.yml` too, so a syntax error is caught without booting a container.

## Target versions

- PrestaShop `1.7.8`
- PrestaShop `8.x`
- PrestaShop `9.x`

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
