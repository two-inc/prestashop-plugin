# Two Payment Integration Matrix (PrestaShop Real Engine)

This folder documents the real-engine integration matrix required for order build parity validation across PrestaShop versions.

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
