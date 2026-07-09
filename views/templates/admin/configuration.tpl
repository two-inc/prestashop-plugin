{*
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
*}

<div class="row">
    <div id="two-tabs" class="col-lg-2 col-md-3">
        <div class="list-group">
            <a class="list-group-item {if $twotabvalue == 1}active{/if}" href="#general-settings" aria-controls="general-settings" role="tab" data-toggle="tab">{l s='General Settings' mod='twopayment'}</a>
            <a class="list-group-item {if $twotabvalue == 5}active{/if}" href="#payment-settings" aria-controls="payment-settings" role="tab" data-toggle="tab">{l s='Payment Settings' mod='twopayment'}</a>
            <a class="list-group-item {if $twotabvalue == 2}active{/if}" href="#other-settings" aria-controls="other-settings" role="tab" data-toggle="tab">{l s='Other Settings' mod='twopayment'}</a>
            <a class="list-group-item {if $twotabvalue == 3}active{/if}" href="#order-status-settings" aria-controls="order-status-settings" role="tab" data-toggle="tab">{l s='Order Status Settings' mod='twopayment'}</a>
            <a class="list-group-item {if $twotabvalue == 4}active{/if}" href="#plugin-info" aria-controls="plugin-info" role="tab" data-toggle="tab">{l s='Plugin Information' mod='twopayment'}</a>
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
            <div id="payment-settings" role="tabpanel" class="tab-pane {if $twotabvalue == 5}active{/if}">
                {$renderTwoPaymentSettingsForm nofilter}
            </div>
            <div id="other-settings" role="tabpanel" class="tab-pane {if $twotabvalue == 2}active{/if}">
                {$renderTwoOtherForm nofilter}
            </div>
            <div id="order-status-settings" role="tabpanel" class="tab-pane {if $twotabvalue == 3}active{/if}">
                {$renderTwoOrderStatusForm nofilter}
            </div>
            <div id="plugin-info" role="tabpanel" class="tab-pane {if $twotabvalue == 4}active{/if}">
                {$renderTwoPluginInfo nofilter}
            </div>
        </div>
    </div>
    <div class="clearfix"></div>
</div>
{literal}
    <script type="text/javascript">
        $(document).ready(function () {
            $('#two-tabs a').click(function () {
                $('#two-tabs a').removeClass('active');
                $(this).addClass('active');
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
        });
    </script>
{/literal}