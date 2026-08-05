{*
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 *}

{* Two Payment - Modern Card Design *}
<div class="two-payment-container">
    {* Result Status Indicator - Initially Hidden *}
    <div class="two-result-status" style="display: none;">
        <span class="two-result-icon"></span>
        <span class="two-result-text"></span>
    </div>

    {* Sole trader flow (TWO-24755), rendered SERVER-side (TWO-25326 bug 9,
       round 3). It used to be an empty, hidden container that TwoSoleTrader.js
       filled in after an availability round trip - which meant the chips were
       missing from every first paint and appeared a few hundred milliseconds
       later. Harmless on a fresh arrival, plainly visible as a flicker once the
       surcharge cart-line sync reloads the page under the buyer, which it does
       on every payment-option change.

       `$sole_trader_available` is the same registry answer the module's
       soleTraderAvailability endpoint returns (TwoSoleTrader::isAvailable),
       resolved for the cart's own billing country, so the markup and the JS
       cannot disagree. The two data- attributes are the handover:
       TwoSoleTrader.adoptServerRenderedToggle() takes this as its settled state
       and issues no request at all, and still re-resolves normally if the buyer
       changes country. An older cached template with no attributes reads as "no
       answer" there and falls back to the client fetch. *}
    <div class="two-sole-trader"
         data-two-country="{$sole_trader_country|escape:'html':'UTF-8'}"
         data-two-available="{if $sole_trader_available}1{else}0{/if}"
         style="display: {if $sole_trader_available}block{else}none{/if};">
        <div class="two-sole-trader__toggle"{if $sole_trader_available} data-two-built="1"{/if}>
            {if $sole_trader_available}
            <span class="two-sole-trader__mode two-sole-trader__mode--selected" role="button" tabindex="0" data-mode="business">{l s='Registered business' mod='twopayment'}</span>
            <span class="two-sole-trader__mode" role="button" tabindex="0" data-mode="sole_trader">{l s='Sole trader' mod='twopayment'}</span>
            {/if}
        </div>
        <a href="#" class="two-sole-trader__prompt" style="display: none;">{l s='Click here to log in or sign up as a sole trader with Two.' mod='twopayment'}</a>
        <span class="two-sole-trader__status" style="display: none;"></span>
        <span class="two-sole-trader__error" style="display: none;">{l s='Something went wrong setting up sole trader checkout. Please try again.' mod='twopayment'}</span>
    </div>

    {* Header Section *}
    <div class="two-header">
        <div class="two-logo-container">
            <img src="{$module_dir|escape:'html':'UTF-8'}views/img/TwoLogo.svg" alt="Two" class="two-logo" />
            <p class="two-tagline">
                {l s='Business payments made simple' mod='twopayment'} 
                <span class="two-info-tooltip">
                    <span class="two-info-icon">?</span>
                    <span class="two-tooltip-content">
                        <span class="two-tooltip-title">{l s='What is Two?' mod='twopayment'}</span>
                        <span class="two-tooltip-text">{l s='Two provides instant trade credit for B2B purchases. Buy now, pay later with no interest or fees.' mod='twopayment'}</span>
                        <a href="https://www.two.inc/resources/buyers" target="_blank" rel="noopener noreferrer" class="two-tooltip-link">
                            {l s='Learn more about Two' mod='twopayment'} →
                        </a>
                    </span>
                </span>
            </p>
        </div>
    </div>
    
    {* Benefits Section *}
    <div class="two-benefits">
        <div class="two-benefit-item">
            <span class="two-benefit-icon">✓</span>
            <span>{l s='No upfront costs' mod='twopayment'}</span>
        </div>
        <div class="two-benefit-item">
            <span class="two-benefit-icon">✓</span>
            <span>{l s='Instant approval check' mod='twopayment'}</span>
        </div>
    </div>
    
    {* Loading State - Initially Hidden *}
    <div class="two-loading-container" style="display: none;">
        <div class="two-loading-spinner"></div>
        <span class="two-loading-text">{l s='Checking availability...' mod='twopayment'}</span>
    </div>
    
    {* Payment Info Section - Dynamically populated by JavaScript *}
    <section class="two-payment-info" style="display: none;">
        <p class="two-subtitle">{$subtitle|escape:'html':'UTF-8'}</p>
        <p class="two-payment-message"></p>
    </section>
    
    {* Payment Terms Selector - Shows after approval *}
    <div class="two-payment-terms" id="two-payment-terms" style="display: none;">
        <div class="two-terms-header">
            <h4 class="two-terms-title">{l s='Choose the Buy Now, Pay Later option that works best for you' mod='twopayment'}</h4>
            <p class="two-terms-description" id="two-terms-description" data-standard-text="{l s='Your payment period starts when your order is fulfilled' mod='twopayment'}" data-eom-text="{l s='Payment due at the end of the current month plus the selected days from when your order is fulfilled' mod='twopayment'}">
                {l s='Your payment period starts when your order is fulfilled' mod='twopayment'}
            </p>
        </div>
        <div class="two-term-chips">
            <div class="two-term-chips__container" id="two-terms-chips">
                {* Chips will be populated by JavaScript *}
            </div>
            <div class="two-terms-selected">
                <span class="two-terms-selected-days" id="two-selected-days">30</span>
            </div>
        </div>
    </div>

    {* Company search, payment-tile location (TWO-25326 §7.1, 2026-08-03
       design ruling). The EXISTING "Enable company search in address entry"
       switch (PS_TWO_ENABLE_COMPANY_NAME) now picks WHERE the one shared
       control (TwoCompanySearch.js - same dropdown / query-field /
       manual-entry code as the address-area control, never a second
       implementation) renders: address area (default, switch = Yes) or
       here (switch = No). No new setting was added for this.

       Only rendered at all when the switch is "No" - the address-area
       control stays exactly as-is (§1-§5) when it is "Yes".
       TwoCheckoutManager.js mounts TwoCompanySearch on this field instead of
       the address form's, and the selection is persisted the same way
       either way (TwoCompanySearch.persistCompanyToCookie -> the session
       company both the order-intent check and order creation already read
       ahead of the address, so relocating the visible control does not
       depend on the address form's own `company`/`companyid` inputs at all). *}
    {if $company_search_tile}
    <div class="two-tile-company-search" id="two-tile-company-search">
        <div class="form-group">
            <label for="two_tile_company">{l s='Company' mod='twopayment'}</label>
            <input type="text" class="form-control" id="two_tile_company" name="two_tile_company" autocomplete="off" />
        </div>
    </div>
    {/if}

    {* Captured company name/number are NOT shown as a separate label here
       (TWO-25326 §7.3, 2026-08-03 ruling supersedes the earlier standalone
       label this module used to render via TwoCompanySummary.js). They are
       folded directly into the intent-message sentence in `.two-payment-message`
       above instead - see TwoOrderIntent.buildCompanyIntentMessage(). *}

    {* Optional buyer reference fields (ABN-472). These live HERE, in the
       payment tile, and not in the billing address block: PrestaShop asks for
       the shipping address first and only shows the billing block when the
       buyer ticks "Billing address differs from shipping address", so a field
       hosted there is invisible to most buyers. The invoice email in
       particular has to be visible when billing and shipping match - that is
       the case where the buyer should be prompted to consider a dedicated
       invoicing address.

       Each input is mirrored into a hidden twin inside the module's payment
       form by TwoOptionalFields.js; the form is a sibling of this markup, so
       these visible inputs are not themselves submitted. A field whose admin
       switch is off is absent from $two_optional_fields, so it renders no
       element at all rather than a hidden one. *}
    {if isset($two_optional_fields) && $two_optional_fields|@count}
        <div class="two-optional-fields" id="two-optional-fields">
            {foreach from=$two_optional_fields item="field"}
                <div class="two-optional-field two-optional-field--{$field.key|escape:'html':'UTF-8'}">
                    <label class="two-optional-field__label" for="two-field-{$field.key|escape:'html':'UTF-8'}">
                        {$field.label|escape:'html':'UTF-8'}
                    </label>
                    <input
                        class="two-optional-field__input form-control"
                        id="two-field-{$field.key|escape:'html':'UTF-8'}"
                        type="{$field.type|escape:'html':'UTF-8'}"
                        maxlength="{$field.max_length|escape:'html':'UTF-8'}"
                        autocomplete="off"
                        data-two-optional-field="{$field.key|escape:'html':'UTF-8'}"
                        data-two-optional-target="{$field.input_name|escape:'html':'UTF-8'}"
                        {if $field.placeholder} placeholder="{$field.placeholder|escape:'html':'UTF-8'}"{/if}
                    />
                    <span class="two-optional-field__error" style="display: none;"></span>
                </div>
            {/foreach}
        </div>
    {/if}
</div>
