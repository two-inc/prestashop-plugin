{*
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
*}

<div class="row">
    <div id="two-tabs" class="col-lg-2 col-md-3">
        <div class="list-group">
            <a class="list-group-item {if $twotabvalue == 1}active{/if}" href="#general-settings" aria-controls="general-settings" role="tab" data-toggle="tab">{l s='General' mod='twopayment'}</a>
            <a class="list-group-item {if $twotabvalue == 2}active{/if}" href="#checkout-fields-settings" aria-controls="checkout-fields-settings" role="tab" data-toggle="tab">{l s='Checkout Fields' mod='twopayment'}</a>
            <a class="list-group-item {if $twotabvalue == 3}active{/if}" href="#company-lookup-settings" aria-controls="company-lookup-settings" role="tab" data-toggle="tab">{l s='Company Lookup' mod='twopayment'}</a>
            <a class="list-group-item {if $twotabvalue == 4}active{/if}" href="#payment-terms-settings" aria-controls="payment-terms-settings" role="tab" data-toggle="tab">{l s='Payment Terms' mod='twopayment'}</a>
            <a class="list-group-item {if $twotabvalue == 5}active{/if}" href="#order-management-settings" aria-controls="order-management-settings" role="tab" data-toggle="tab">{l s='Order Management' mod='twopayment'}</a>
            <a class="list-group-item {if $twotabvalue == 6}active{/if}" href="#diagnostics-settings" aria-controls="diagnostics-settings" role="tab" data-toggle="tab">{l s='Diagnostics' mod='twopayment'}</a>
        </div>
    </div>
    <div class="col-lg-10 col-md-9">
        <div class="tab-content">
            <div id="general-settings" role="tabpanel" class="tab-pane {if $twotabvalue == 1}active{/if}">
                {if $two_api_verified}
                <div class="panel" style="border-left:4px solid #4CAF50;">
                    <div class="panel-heading" style="display:flex;align-items:center;gap:8px;">
                        <span class="badge" style="background:#4CAF50;">{l s='Verified' mod='twopayment'}</span>
                        <span>{l s='API key verified successfully' mod='twopayment'}</span>
                    </div>
                    <div class="panel-body">
                        <div style="display:flex;gap:24px;flex-wrap:wrap;">
                            <div>
                                <div style="font-weight:600;">{l s='Merchant ID' mod='twopayment'}</div>
                                <div>{$two_merchant_id|escape:'htmlall':'UTF-8'}</div>
                            </div>
                            <div>
                                <div style="font-weight:600;">{l s='Merchant short name' mod='twopayment'}</div>
                                <div>{$two_merchant_short_name|escape:'htmlall':'UTF-8'}</div>
                            </div>
                            <div>
                                <div style="font-weight:600;">{l s='Environment' mod='twopayment'}</div>
                                <div>{$two_env|escape:'htmlall':'UTF-8'}</div>
                            </div>
                        </div>
                    </div>
                </div>
                {/if}
                {$renderTwoGeneralForm nofilter}
            </div>
            <div id="checkout-fields-settings" role="tabpanel" class="tab-pane {if $twotabvalue == 2}active{/if}">
                {$renderTwoCheckoutFieldsForm nofilter}
            </div>
            <div id="company-lookup-settings" role="tabpanel" class="tab-pane {if $twotabvalue == 3}active{/if}">
                {$renderTwoCompanyLookupForm nofilter}
            </div>
            <div id="payment-terms-settings" role="tabpanel" class="tab-pane {if $twotabvalue == 4}active{/if}">
                {$renderTwoPaymentTermsForm nofilter}
            </div>
            <div id="order-management-settings" role="tabpanel" class="tab-pane {if $twotabvalue == 5}active{/if}">
                {$renderTwoOrderManagementForm nofilter}
                {$renderTwoOrderStatusForm nofilter}
            </div>
            <div id="diagnostics-settings" role="tabpanel" class="tab-pane {if $twotabvalue == 6}active{/if}">
                {$renderTwoDiagnosticsForm nofilter}
                {$renderTwoPluginInfo nofilter}
            </div>
        </div>
    </div>
    <div class="clearfix"></div>
</div>
<script type="text/javascript">
    // Module admin AJAX endpoint for the inline merchant-fee display
    // (assigned outside the literal block so Smarty expands the URL).
    var twoMerchantFeeRatesUrl = '{$two_fee_rates_url|escape:'javascript':'UTF-8'}';
</script>
{literal}
    <script type="text/javascript">
        $(document).ready(function () {
            $('#two-tabs a').click(function () {
                $('#two-tabs a').removeClass('active');
                $(this).addClass('active');
            });
            
            // Address lookup is only meaningful while the company search is in
            // the address area (TWO-25326 §7.1 follow-up). When "Enable
            // company search in address entry" is "No" the search has moved
            // into the payment tile and there is no address-area lookup left
            // to govern, so the switch is unchecked and disabled rather than
            // left independently settable. Mirrors woocommerce-plugin's
            // admin.js toggleChildrenFields() for the same pair of settings.
            //
            // Server-side counterpart in twopayment.php
            // (isAddressLookupSettingAvailable): a disabled radio posts
            // nothing, but a hand-crafted POST can still carry a ticked box
            // and the save refuses it there. This half is presentation only.
            // `isUserToggle` distinguishes the initial page-load render (the
            // stored PS_TWO_ADDRESS_LOOKUP position must be respected as-is)
            // from the admin actually flipping the switch just now (bug
            // report, TWO-25326): re-enabling the row must also turn the
            // auto-fill switch ON, not merely stop greying it out. Leaving it
            // enabled-but-unchecked reads as "on" at a glance but posts '0'
            // on save, silently keeping auto-fill off after the merchant just
            // turned company search back on.
            function updateAddressLookupAvailability(isUserToggle) {
                var inAddressArea = $('input[name="PS_TWO_ENABLE_COMPANY_NAME"]:checked').val();
                var lookupInputs = $('input[name="PS_TWO_ADDRESS_LOOKUP"]');
                if (!lookupInputs.length) {
                    return;
                }
                // The whole row, so the label and the help text grey out with
                // the control rather than the control alone looking broken.
                var row = lookupInputs.closest('.form-group');
                if (String(inAddressArea) === '1') {
                    lookupInputs.prop('disabled', false);
                    if (isUserToggle) {
                        lookupInputs.filter('[value="1"]').prop('checked', true);
                        lookupInputs.filter('[value="0"]').prop('checked', false);
                    }
                    row.removeClass('two-setting-unavailable');
                    return;
                }
                // Force the "No" position on screen as well as disabling it:
                // the merchant must not read a ticked box the module is
                // ignoring. This is exactly what the server will store.
                lookupInputs.prop('disabled', true);
                lookupInputs.filter('[value="0"]').prop('checked', true);
                lookupInputs.filter('[value="1"]').prop('checked', false);
                row.addClass('two-setting-unavailable');
            }
            updateAddressLookupAvailability(false);
            $('input[name="PS_TWO_ENABLE_COMPANY_NAME"]').on('change', function () {
                updateAddressLookupAvailability(true);
            });

            // Payment Term Type - Dynamic show/hide of term options
            // PHP 7.1+ compatible: Using ES5 syntax (no arrow functions)
            function updatePaymentTermsVisibility() {
                var termType = $('input[name="PS_TWO_PAYMENT_TERM_TYPE"]:checked').val();
                
                if (termType === 'EOM') {
                    // EOM: Only show 30, 45, 60 day options
                    $('.two-term-standard').closest('.form-group').hide();
                    $('.two-term-both').closest('.form-group').show();
                    $('#two-payment-terms-desc-standard').hide();
                    $('#two-payment-terms-desc-eom').show();
                } else {
                    // STANDARD: Show all options (7, 15, 20, 30, 45, 60, 90)
                    $('.two-term-standard').closest('.form-group').show();
                    $('.two-term-both').closest('.form-group').show();
                    $('#two-payment-terms-desc-standard').show();
                    $('#two-payment-terms-desc-eom').hide();
                }
            }
            
            // Run on page load
            updatePaymentTermsVisibility();
            
            // Run when term type changes
            $('input[name="PS_TWO_PAYMENT_TERM_TYPE"]').on('change', function() {
                updatePaymentTermsVisibility();
            });

            // Surcharge Rounding Step - hide the step selector when the rounding
            // basis is None (no rounding direction means the step is irrelevant).
            function updateRoundingStepVisibility() {
                var basis = $('select[name="PS_TWO_SURCHARGE_ROUNDING_BASIS"]').val();
                var stepGroup = $('select[name="PS_TWO_SURCHARGE_ROUNDING_STEP"]').closest('.form-group');
                if (!basis || basis === 'none') {
                    stepGroup.hide();
                } else {
                    stepGroup.show();
                }
            }
            updateRoundingStepVisibility();
            $('select[name="PS_TWO_SURCHARGE_ROUNDING_BASIS"]').on('change', updateRoundingStepVisibility);

            // Surcharge grid - hide the whole grid when no surcharge is applied,
            // and hide the columns that don't apply to the selected method:
            //   none                 -> hide entire grid
            //   percentage           -> Percentage + Cap (hide Fixed fee)
            //   fixed                -> Fixed fee only (hide Percentage + Cap)
            //   fixed_and_percentage -> all columns
            function updateSurchargeGridVisibility() {
                var type = $('select[name="PS_TWO_SURCHARGE_TYPE"]').val();
                var grid = $('#two-surcharge-grid');
                var gridGroup = grid.closest('.form-group');
                if (!type || type === 'none') {
                    gridGroup.hide();
                    return;
                }
                gridGroup.show();
                var showPercentage = (type === 'percentage' || type === 'fixed_and_percentage');
                var showFixed = (type === 'fixed' || type === 'fixed_and_percentage');
                var showCap = (type === 'percentage' || type === 'fixed_and_percentage');
                // Scoped to the whole form-group rather than the table, so the
                // cap help text BELOW the grid is hidden with the cap column
                // rather than left on screen describing a field the merchant
                // cannot see (TWO-25289). Falls back to the table if the
                // form-group does not resolve - the markup nests differently
                // across PrestaShop majors, and before this rescoping an
                // unresolved gridGroup only broke the whole-grid hide/show;
                // it must not now also break the column toggles.
                var scope = gridGroup.length ? gridGroup : grid;
                scope.find('.two-col-percentage').toggle(showPercentage);
                scope.find('.two-col-fixed').toggle(showFixed);
                scope.find('.two-col-cap').toggle(showCap);
            }
            updateSurchargeGridVisibility();
            $('select[name="PS_TWO_SURCHARGE_TYPE"]').on('change', updateSurchargeGridVisibility);

            // Surcharge grid ROWS - one row is server-rendered per offerable
            // term; show a row only while its "Available Payment Terms"
            // checkbox is ticked AND the term is valid for the selected term
            // type (EOM only allows the .two-term-both terms - same split the
            // checkboxes use). Orthogonal to updateSurchargeGridVisibility(),
            // which toggles COLUMNS by surcharge type: a cell is visible only
            // when both its row and its column are (display:none on either
            // axis wins), so the two functions compose without coordination.
            function updateSurchargeGridRows() {
                var termType = $('input[name="PS_TWO_PAYMENT_TERM_TYPE"]:checked').val();
                $('#two-surcharge-grid .two-surcharge-row').each(function () {
                    var $row = $(this);
                    var term = parseInt($row.data('term'), 10);
                    var checked = $('input[name="PS_TWO_PAYMENT_TERMS_' + term + '"]').is(':checked');
                    var validForType = termType !== 'EOM' || $row.hasClass('two-term-both');
                    $row.toggle(checked && validForType);
                });
            }
            $('input[name^="PS_TWO_PAYMENT_TERMS_"]').on('change', updateSurchargeGridRows);
            $('input[name="PS_TWO_PAYMENT_TERM_TYPE"]').on('change', updateSurchargeGridRows);
            // Run once on load (after the checkbox-group and column-visibility
            // passes above) so row state always derives from the live checkbox
            // DOM, even if it disagrees with the server-rendered initial state
            // (e.g. a failed-validation re-render with POSTed values).
            updateSurchargeGridRows();

            // Inline merchant fee beside each "Available Payment Terms"
            // checkbox - the fee Two charges the merchant per term, fetched
            // live from the module's admin AJAX endpoint (which proxies
            // POST /pricing/v1/merchant/rates). Mirrors magento-plugin's
            // payment-terms-config.js loadFees(): fetch on page load and on
            // any term-checkbox change, dedupe identical term-set requests,
            // and on failure blank the fee spans silently - the config page
            // must never break on an API outage.
            var lastFeesKey = null;

            function formatTwoFeeAmount(n) {
                return Number(n).toFixed(2);
            }

            function loadTwoMerchantFees() {
                if (typeof twoMerchantFeeRatesUrl === 'undefined' || !twoMerchantFeeRatesUrl) {
                    return;
                }
                // Fees render beside EVERY rendered term option regardless of
                // checked state (Magento parity), so collect all term inputs.
                var terms = [];
                $('input[name^="PS_TWO_PAYMENT_TERMS_"]').each(function () {
                    var match = String($(this).attr('name') || '').match(/_(\d+)$/);
                    var days = match ? parseInt(match[1], 10) : 0;
                    if (days > 0 && terms.indexOf(days) === -1) {
                        terms.push(days);
                    }
                });
                terms.sort(function (a, b) { return a - b; });
                if (!terms.length) {
                    return;
                }
                var key = terms.join(',');
                if (key === lastFeesKey) {
                    return; // identical term set already requested
                }
                lastFeesKey = key;
                $.ajax({
                    url: twoMerchantFeeRatesUrl,
                    type: 'POST',
                    dataType: 'json',
                    data: { terms: JSON.stringify(terms) }
                }).done(function (response) {
                    if (!response || !response.success || !response.fees) {
                        $('.two-term-fee').text('');
                        return;
                    }
                    // Currency must come from the API response - the fee
                    // amounts do too. Without it, any fixed amount would be
                    // ambiguous, so only the percentage is shown then.
                    var currency = String(response.currency || '').toUpperCase().replace(/^\s+|\s+$/g, '');
                    var suffix = currency !== '' ? ' ' + currency : '';
                    $('.two-term-fee').each(function () {
                        var $span = $(this);
                        var fee = response.fees[String($span.data('term'))];
                        if (!fee) {
                            $span.text('');
                            return;
                        }
                        var pctStr = formatTwoFeeAmount(fee.percentage || 0);
                        var fixedStr = formatTwoFeeAmount(fee.fixed || 0);
                        var zero = formatTwoFeeAmount(0);
                        var pctZero = pctStr === zero;
                        var fixedZero = fixedStr === zero;
                        if (currency === '') {
                            $span.text(pctZero ? '' : '(' + pctStr + '%)');
                            return;
                        }
                        var inner;
                        if (pctZero && fixedZero) {
                            inner = zero + suffix;
                        } else if (pctZero) {
                            inner = fixedStr + suffix;
                        } else if (fixedZero) {
                            inner = pctStr + '%';
                        } else {
                            inner = pctStr + '% + ' + fixedStr + suffix;
                        }
                        $span.text('(' + inner + ')');
                    });
                }).fail(function () {
                    // Allow a retry on the same term set after a transient
                    // error, and clear any half-populated spans.
                    lastFeesKey = null;
                    $('.two-term-fee').text('');
                });
            }

            $('input[name^="PS_TWO_PAYMENT_TERMS_"]').on('change', loadTwoMerchantFees);
            loadTwoMerchantFees();
        });
    </script>
{/literal}