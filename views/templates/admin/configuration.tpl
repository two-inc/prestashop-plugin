{*
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
*}

<div class="row">
    <div id="two-tabs" class="col-lg-2 col-md-3">
        <div class="list-group">
            <a class="list-group-item {if $twotabvalue == 1}active{/if}" href="#general-settings" aria-controls="general-settings" role="tab" data-toggle="tab">{l s='General' mod='twopayment'}</a>
            <a class="list-group-item {if $twotabvalue == 2 || $twotabvalue == 3}active{/if}" href="#checkout-fields-settings" aria-controls="checkout-fields-settings" role="tab" data-toggle="tab">{l s='Checkout fields' mod='twopayment'}</a>
            <a class="list-group-item {if $twotabvalue == 4}active{/if}" href="#payment-terms-settings" aria-controls="payment-terms-settings" role="tab" data-toggle="tab">{l s='Payment terms' mod='twopayment'}</a>
            <a class="list-group-item {if $twotabvalue == 5}active{/if}" href="#order-management-settings" aria-controls="order-management-settings" role="tab" data-toggle="tab">{l s='Order management' mod='twopayment'}</a>
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
            <div id="checkout-fields-settings" role="tabpanel" class="tab-pane {if $twotabvalue == 2 || $twotabvalue == 3}active{/if}">
                {$renderTwoCheckoutFieldsForm nofilter}
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
    // Assigned outside the literal block so Smarty expands the URL.
    var twoMerchantFeeRatesUrl = '{$two_fee_rates_url|escape:'javascript':'UTF-8'}';
    var twoVerifyApiKeyUrl = '{$two_verify_api_key_url|escape:'javascript':'UTF-8'}';
    var twoApiKeyCheckingText = '{l s='Checking API key…' mod='twopayment'|escape:'javascript':'UTF-8'}';
    var twoApiKeyVerifiedText = '{l s='API key verified' mod='twopayment'|escape:'javascript':'UTF-8'}';
    var twoApiKeyFailedText = '{l s='API key could not be verified' mod='twopayment'|escape:'javascript':'UTF-8'}';
    var twoRefreshMerchantUrl = '{$two_refresh_merchant_url|escape:'javascript':'UTF-8'}';
    var twoRefreshMerchantBusyText = '{l s='Refreshing…' mod='twopayment'|escape:'javascript':'UTF-8'}';
    var twoRefreshMerchantFailedText = '{l s='Could not reach the server. Try again.' mod='twopayment'|escape:'javascript':'UTF-8'}';
    var twoFeesStaleText = '{l s='Fees could not be refreshed, so the figures last retrieved are shown.' mod='twopayment'|escape:'javascript':'UTF-8'}';
    var twoFeesStaleDatedText = '{l s='Fees could not be refreshed, so the figures retrieved on %s are shown.' mod='twopayment'|escape:'javascript':'UTF-8'}';
    var twoFeesUnavailableText = '{l s='Fees could not be loaded because the pricing service could not be reached. The figures beside each term are missing, not zero.' mod='twopayment'|escape:'javascript':'UTF-8'}';
    var twoFeesNoApiKeyText = '{l s='Fees cannot be shown until an API key is saved on the General tab.' mod='twopayment'|escape:'javascript':'UTF-8'}';
    var twoFeeNoFigureText = '{l s='no figure' mod='twopayment'|escape:'javascript':'UTF-8'}';
    var twoEomTermDays = {$two_eom_term_days nofilter};
    var twoCustomTermDays = {$two_custom_term_days|intval};
</script>
{literal}
    <script type="text/javascript">
        $(document).ready(function () {
            $('#two-tabs a').click(function () {
                $('#two-tabs a').removeClass('active');
                $(this).addClass('active');
            });

            $('#two-refresh-merchant-record').on('click', function () {
                var button = $(this);
                var result = $('#two-refresh-merchant-record-result');
                button.prop('disabled', true);
                result.removeClass('text-success text-danger').addClass('text-muted').text(twoRefreshMerchantBusyText);
                $.post(twoRefreshMerchantUrl, {}, null, 'json')
                    .done(function (response) {
                        var ok = !!(response && response.success);
                        result
                            .removeClass('text-muted text-success text-danger')
                            .addClass(ok ? 'text-success' : 'text-danger')
                            .text(response && response.message ? response.message : '');
                    })
                    .fail(function () {
                        result.removeClass('text-muted text-success').addClass('text-danger').text(twoRefreshMerchantFailedText);
                    })
                    .always(function () {
                        button.prop('disabled', false);
                    });
            });
            
            // Address lookup is only meaningful while the company search is in
            // the address area (TWO-25326 follow-up): with the search in
            // the payment tile there is no address-area lookup left to govern,
            // so the switch is unchecked and disabled rather than left
            // independently settable.
            //
            // Server-side counterpart in twopayment.php
            // (isAddressLookupSettingAvailable): a disabled radio posts
            // nothing, but a hand-crafted POST can still carry a ticked box
            // and the save refuses it there. This half is presentation only.
            // `isUserToggle` distinguishes the initial page-load render (the
            // stored PS_TWO_ADDRESS_LOOKUP position must be respected as-is)
            // from the admin actually flipping the switch just now (TWO-25326):
            // re-enabling the row must also turn the auto-fill switch ON, not
            // merely stop greying it out. Leaving it enabled-but-unchecked
            // reads as "on" at a glance but posts '0' on save.
            function updateAddressLookupAvailability(isUserToggle) {
                var inAddressArea = $('input[name="PS_ENABLE_COMPANY_SEARCH_IN_ADDRESS"]:checked').val();
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
                // The merchant must not read a ticked box the module is
                // ignoring. This is exactly what the server will store.
                lookupInputs.prop('disabled', true);
                lookupInputs.filter('[value="0"]').prop('checked', true);
                lookupInputs.filter('[value="1"]').prop('checked', false);
                row.addClass('two-setting-unavailable');
            }
            updateAddressLookupAvailability(false);
            $('input[name="PS_ENABLE_COMPANY_SEARCH_IN_ADDRESS"]').on('change', function () {
                updateAddressLookupAvailability(true);
            });

            // ES5 syntax only (no arrow functions).
            function updatePaymentTermsVisibility() {
                var termType = $('input[name="PS_TWO_PAYMENT_TERM_TYPE"]:checked').val();
                
                if (termType === 'EOM') {
                    $('.two-term-standard').closest('.form-group').hide();
                    $('.two-term-both').closest('.form-group').show();
                    $('#two-payment-terms-desc-standard').hide();
                    $('#two-payment-terms-desc-eom').show();
                } else {
                    $('.two-term-standard').closest('.form-group').show();
                    $('.two-term-both').closest('.form-group').show();
                    $('#two-payment-terms-desc-standard').show();
                    $('#two-payment-terms-desc-eom').hide();
                }
            }
            
            updatePaymentTermsVisibility();
            
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
            // and hide the columns that don't apply to the selected method.
            // Null until the first row pass; the column pass decides on the
            // surcharge type alone until then.
            var twoVisibleSurchargeRows = null;

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
                // across PrestaShop majors.
                // With no row left, the cap help text below the grid would
                // otherwise describe cells nobody can see - the same reason the
                // columns carry it.
                var hasRows = twoVisibleSurchargeRows === null || twoVisibleSurchargeRows > 0;
                var scope = gridGroup.length ? gridGroup : grid;
                scope.find('.two-col-percentage').toggle(showPercentage && hasRows);
                scope.find('.two-col-fixed').toggle(showFixed && hasRows);
                scope.find('.two-col-cap').toggle(showCap && hasRows);
            }
            updateSurchargeGridVisibility();
            $('select[name="PS_TWO_SURCHARGE_TYPE"]').on('change', updateSurchargeGridVisibility);

            // The ticked terms, narrowed by the term type. Narrower than
            // narrowOfferedTerms(), which also unions the deprecated custom
            // term - no live tick governs that one, so it is handled where it
            // matters, in the default-term options below.
            function twoOfferedTermDays() {
                var termType = $('input[name="PS_TWO_PAYMENT_TERM_TYPE"]:checked').val();
                // An absent list means no narrowing rather than no term.
                var eomDays = (typeof twoEomTermDays !== 'undefined' && twoEomTermDays) ? twoEomTermDays : [];
                var offered = [];
                $('input[name^="PS_TWO_PAYMENT_TERMS_"]').each(function () {
                    var $box = $(this);
                    var match = String($box.attr('name') || '').match(/_(\d+)$/);
                    var days = match ? parseInt(match[1], 10) : 0;
                    if (!days || !$box.is(':checked')) {
                        return;
                    }
                    if (termType === 'EOM' && eomDays.length && eomDays.indexOf(days) === -1) {
                        return;
                    }
                    offered.push(days);
                });
                return offered;
            }

            // Surcharge grid ROWS - one row is server-rendered per offerable
            // term; show a row only while its term is offered. Orthogonal to
            // updateSurchargeGridVisibility(), which toggles COLUMNS: a cell is
            // visible only when both its row and its column are, so the two
            // functions compose without coordination.
            //
            // With no offered term the grid has nothing to configure, so the
            // headings give way to an instruction to offer a term first.
            function updateSurchargeGridRows() {
                var offered = twoOfferedTermDays();
                var visible = 0;
                $('#two-surcharge-grid .two-surcharge-row').each(function () {
                    var $row = $(this);
                    var isOffered = offered.indexOf(parseInt($row.data('term'), 10)) !== -1;
                    $row.toggle(isOffered);
                    if (isOffered) {
                        visible += 1;
                    }
                });
                twoVisibleSurchargeRows = visible;
                $('#two-surcharge-grid').toggle(visible > 0);
                $('#two-surcharge-empty').toggle(visible === 0);
                updateSurchargeGridVisibility();
                updateTwoDefaultTermOptions(offered);
            }

            // Default-term dropdown. The pass WITHDRAWS an option whose term
            // stops being offered and restores it if the term comes back; it
            // never adds one, because the server rendered the option list. An
            // explicit default whose term is withdrawn falls back to Automatic,
            // which is what the save would store for it anyway.
            var twoDefaultTermWanted = null;

            function updateTwoDefaultTermOptions(offered) {
                var $select = $('select[name="PS_TWO_DEFAULT_PAYMENT_TERM"]');
                if (!$select.length) {
                    return;
                }
                if (twoDefaultTermWanted === null) {
                    twoDefaultTermWanted = String($select.val() || '');
                }
                var custom = (typeof twoCustomTermDays !== 'undefined') ? parseInt(twoCustomTermDays, 10) : 0;
                // Removing the custom term withdraws it from the offered set
                // on save, so the option it kept alive goes with it.
                var $custom = $('select[name="PS_TWO_PAYMENT_TERMS_CUSTOM_DAYS"]');
                if ($custom.length && String($custom.val() || '') === '') {
                    custom = 0;
                }
                var selectable = custom > 0 && offered.indexOf(custom) === -1 ? offered.concat([custom]) : offered;
                // getConfigurableTermSet() substitutes a fallback term when the
                // narrowing leaves nothing, so the server renders an option
                // here that narrowing away would withdraw from a save that
                // accepts it.
                if (!selectable.length) {
                    $select.find('option').prop('disabled', false).show();
                    $select.val(twoDefaultTermWanted);

                    return;
                }
                $select.find('option').each(function () {
                    var $option = $(this);
                    var value = String($option.attr('value') || '');
                    var isOffered = value === '' || selectable.indexOf(parseInt(value, 10)) !== -1;
                    $option.prop('disabled', !isOffered).toggle(isOffered);
                });
                var wantedOffered = twoDefaultTermWanted === ''
                    || selectable.indexOf(parseInt(twoDefaultTermWanted, 10)) !== -1;
                $select.val(wantedOffered ? twoDefaultTermWanted : '');
            }

            $('select[name="PS_TWO_DEFAULT_PAYMENT_TERM"]').on('change', function () {
                twoDefaultTermWanted = String($(this).val() || '');
            });
            $('select[name="PS_TWO_PAYMENT_TERMS_CUSTOM_DAYS"]').on('change', updateSurchargeGridRows);
            $('input[name^="PS_TWO_PAYMENT_TERMS_"]').on('change', updateSurchargeGridRows);
            $('input[name="PS_TWO_PAYMENT_TERM_TYPE"]').on('change', updateSurchargeGridRows);
            // Run after the checkbox-group and column-visibility passes above,
            // so row state always derives from the live checkbox DOM even when
            // it disagrees with the server-rendered initial state (e.g. a
            // failed-validation re-render with POSTed values).
            updateSurchargeGridRows();

            // Inline merchant fee beside each "Available Payment Terms"
            // checkbox, fetched from the module's admin AJAX endpoint. An
            // empty span reads as "this term carries no fee", so an answer
            // that cannot be drawn says so in the notice instead (ABN-541).
            var lastFeesKey = null;

            function formatTwoFeeAmount(n) {
                return Number(n).toFixed(2);
            }

            function setTwoFeeNotice(text) {
                var $first = $('input[name^="PS_TWO_PAYMENT_TERMS_"]').first();
                // The notice needs somewhere to go on a core version that does
                // not wrap the checkbox group in .form-group.
                var $anchor = $first.closest('.form-group');
                if (!$anchor.length) {
                    $anchor = $first.parent();
                }
                if (!$anchor.length) {
                    return;
                }
                var $notice = $anchor.find('.two-term-fee-notice');
                if (!$notice.length) {
                    if (!text) {
                        return;
                    }
                    $notice = $('<div class="two-term-fee-notice help-block"></div>').appendTo($anchor);
                }
                $notice.text(text || '');
            }

            function showTwoFeesUnavailable(error) {
                $('.two-term-fee').text('');
                setTwoFeeNotice(error === 'not_configured' ? twoFeesNoApiKeyText : twoFeesUnavailableText);
            }

            function loadTwoMerchantFees() {
                if (typeof twoMerchantFeeRatesUrl === 'undefined' || !twoMerchantFeeRatesUrl) {
                    return;
                }
                // Fees render beside EVERY rendered term option regardless of
                // checked state, so collect all term inputs.
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
                    return;
                }
                lastFeesKey = key;
                $.ajax({
                    url: twoMerchantFeeRatesUrl,
                    type: 'POST',
                    dataType: 'json',
                    data: { terms: JSON.stringify(terms) }
                }).done(function (response) {
                    var terminal = response && response.error === 'not_configured';
                    // Anything but a fresh renderable set may be asked again
                    // for the same terms - the server's own cooldown, not this
                    // key, is what bounds an outage. An unsaved key is
                    // terminal, so it keeps the key.
                    if (!terminal && (!response || !response.success || !response.fees || response.stale)) {
                        lastFeesKey = null;
                    }
                    if (!response || !response.success || !response.fees) {
                        showTwoFeesUnavailable(response && response.error);
                        return;
                    }
                    if (response.stale) {
                        var retrieved = String(response.fetched_at_display || '');
                        setTwoFeeNotice(
                            retrieved === ''
                                ? twoFeesStaleText
                                : twoFeesStaleDatedText.replace('%s', retrieved)
                        );
                    } else {
                        setTwoFeeNotice('');
                    }
                    // Currency comes from the API response, never guessed: the
                    // fee amounts are its too. A set without one is refused
                    // server-side rather than drawn.
                    var currency = String(response.currency || '').toUpperCase().replace(/^\s+|\s+$/g, '');
                    var suffix = currency !== '' ? ' ' + currency : '';
                    $('.two-term-fee').each(function () {
                        var $span = $(this);
                        var fee = response.fees[String($span.data('term'))];
                        if (!fee) {
                            // An empty span reads as "no fee for this term",
                            // so a term the answer did not price says so.
                            $span.text('(' + twoFeeNoFigureText + ')');
                            return;
                        }
                        var pctStr = formatTwoFeeAmount(fee.percentage || 0);
                        var fixedStr = formatTwoFeeAmount(fee.fixed || 0);
                        var zero = formatTwoFeeAmount(0);
                        var pctZero = pctStr === zero;
                        var fixedZero = fixedStr === zero;
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
                    // error.
                    lastFeesKey = null;
                    showTwoFeesUnavailable();
                });
            }

            $('input[name^="PS_TWO_PAYMENT_TERMS_"]').on('change', loadTwoMerchantFees);
            loadTwoMerchantFees();

            // Custom request headers: add/remove rows in the
            // Diagnostics table. Each row's three inputs share one array
            // index, so a new row takes an index past every existing one -
            // reusing an index would merge two rows on save.
            function twoNextCustomHeaderIndex() {
                var next = 0;
                $('#two-custom-headers tbody tr.two-custom-header-row').each(function () {
                    next = Math.max(next, parseInt($(this).data('index'), 10) + 1 || 0);
                });
                return next;
            }

            $('#two-custom-headers-add').on('click', function () {
                var template = document.getElementById('two-custom-headers-template');
                if (!template) {
                    return;
                }
                var index = twoNextCustomHeaderIndex();
                var $row = $(template.innerHTML).filter('tr').first();
                if (!$row.length) {
                    return;
                }
                $row.attr('data-index', index).data('index', index);
                $row.find('input[name]').each(function () {
                    $(this).attr('name', String($(this).attr('name')).replace(/\[\d+\]$/, '[' + index + ']'));
                });
                $('#two-custom-headers tbody').append($row);
            });

            $('#two-custom-headers').on('click', '.two-custom-header-remove', function () {
                $(this).closest('tr.two-custom-header-row').remove();
            });

            // Inline API-key live check (TWO-25386): fires on blur AND on
            // a debounced keystroke, so a merchant sees the verdict before
            // ever reaching Save. Never touches Configuration - see
            // ajaxProcessVerifyApiKeyLive().
            var $twoApiKeyInput = $('input[name="PS_TWO_MERCHANT_API_KEY"]');
            var twoApiKeyDebounce = null;
            var twoApiKeyRequestSeq = 0;

            function twoApiKeyStatusEl() {
                var $existing = $twoApiKeyInput.siblings('.two-api-key-live-status');
                if ($existing.length) {
                    return $existing;
                }
                var $el = $('<div class="two-api-key-live-status help-block"></div>');
                $twoApiKeyInput.after($el);
                return $el;
            }

            function runTwoApiKeyLiveCheck() {
                var apiKey = $.trim($twoApiKeyInput.val() || '');
                var environment = $('select[name="PS_TWO_ENVIRONMENT"]').val();
                var $status = twoApiKeyStatusEl();
                if (!apiKey || !environment || typeof twoVerifyApiKeyUrl === 'undefined' || !twoVerifyApiKeyUrl) {
                    $status.text('');
                    return;
                }
                var seq = ++twoApiKeyRequestSeq;
                $status.removeClass('text-success text-danger').text(twoApiKeyCheckingText);
                $.ajax({
                    url: twoVerifyApiKeyUrl,
                    type: 'POST',
                    dataType: 'json',
                    data: { api_key: apiKey, environment: environment }
                }).done(function (response) {
                    if (seq !== twoApiKeyRequestSeq) {
                        return; // superseded by a later keystroke/blur
                    }
                    if (response && response.ok) {
                        $status.removeClass('text-danger').addClass('text-success').text(twoApiKeyVerifiedText);
                    } else {
                        var message = (response && response.message) || twoApiKeyFailedText;
                        $status.removeClass('text-success').addClass('text-danger').text(message);
                    }
                }).fail(function () {
                    if (seq !== twoApiKeyRequestSeq) {
                        return;
                    }
                    $status.removeClass('text-success').addClass('text-danger').text(twoApiKeyFailedText);
                });
            }

            $twoApiKeyInput.on('blur', runTwoApiKeyLiveCheck);
            $twoApiKeyInput.on('keyup', function () {
                clearTimeout(twoApiKeyDebounce);
                twoApiKeyDebounce = setTimeout(runTwoApiKeyLiveCheck, 600);
            });
        });
    </script>
{/literal}