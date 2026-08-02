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

    {* Sole trader flow (TWO-24755) - TwoSoleTrader.js renders the
       Business / Sole trader toggle into .two-sole-trader__toggle when the
       billing country supports sole traders and the merchant enabled it. *}
    <div class="two-sole-trader" style="display: none;">
        <div class="two-sole-trader__toggle"></div>
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

    {* Captured company, read-only (TWO-25288; reshaped by TWO-25326 §7).

       §7 requires the captured company to appear in the payment tile as a
       single text label in the form "<name> (<number>)", positioned between
       the term chips and the intent message / optional fields - which is
       exactly where this sits.

       Deliberately <span>s and not inputs: the values are not editable and not
       removable here. Populated by TwoCompanySummary.js, which reads them from
       the address form and never writes to it - the hidden `companyid` input
       remains the sole carrier of the organisation number into the submission.

       The number and its parentheses are one unit, hidden together when there
       is no number: a manual-entry buyer supplies a name only (§5), and
       "Example Ltd ()" is worse than "Example Ltd". *}
    <div class="two-company-summary" id="two-company-summary" style="display: none;">
        <span class="two-company-summary__value" data-two-company-summary="name"></span><span class="two-company-summary__number-wrap"> (<span class="two-company-summary__value" data-two-company-summary="number"></span>)</span>
    </div>

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
