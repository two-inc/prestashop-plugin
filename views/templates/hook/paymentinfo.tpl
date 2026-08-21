{*
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 *}

<div class="two-payment-container">
    <div class="two-result-status" style="display: none;">
        <span class="two-result-icon"></span>
        <span class="two-result-text"></span>
    </div>

    {* Sole trader flow (TWO-24755). Enrolment is entered from a row inside the
       company search dropdown (TwoCompanySearch.js); this container only hosts
       the prompt/status/error messaging TwoSoleTrader.js shows once enrolment
       starts, so it has no visible content, and no visible size, until then.

       The two data- attributes are the handover that lets the browser skip its
       own availability round trip: TwoSoleTrader.adoptServerRenderedToggle()
       takes them as its settled availability cache, which the dropdown's
       "I'm a sole trader" row reads to decide whether to show itself. Resolved
       for the cart's own billing country, so markup and JS cannot disagree.

       `$sole_trader_answer` is '1', '0', or EMPTY when the registry did not
       answer at all. Empty is not the same as '0' and must not be rendered as
       one: the browser reads it as "no answer" and keeps its own retrying
       request path, which stops a single registry blip from becoming a cached
       "business only" for the rest of the page's life. *}
    <div class="two-sole-trader"
         data-two-country="{$sole_trader_country|escape:'html':'UTF-8'}"
         data-two-available="{$sole_trader_answer|escape:'html':'UTF-8'}">
        <a href="#" class="two-sole-trader__prompt" style="display: none;">{l s='Click here to log in or sign up as a sole trader with %s.' mod='twopayment' sprintf=[$two_product_name]}</a>
        <span class="two-sole-trader__status" style="display: none;"></span>
        <span class="two-sole-trader__error" style="display: none;">{l s='Something went wrong setting up sole trader checkout. Please try again.' mod='twopayment'}</span>
    </div>

    <div class="two-header">
        <div class="two-logo-container">
            <img src="{$module_dir|escape:'html':'UTF-8'}views/img/TwoLogo.svg" alt="{$two_product_name|escape:'html':'UTF-8'}" class="two-logo" />
            <p class="two-tagline">
                {l s='Business payments made simple' mod='twopayment'}
                {* "What is Two" explainer link (TWO-25386 #2, ported from
                   woocommerce-plugin's `show_abt_link`). Default ON. *}
                {if $show_about_link}
                <span class="two-info-tooltip">
                    <span class="two-info-icon">?</span>
                    <span class="two-tooltip-content">
                        <span class="two-tooltip-title">{l s='What is %s?' mod='twopayment' sprintf=[$two_product_name]}</span>
                        <span class="two-tooltip-text">{l s='%s provides instant trade credit for B2B purchases. Buy now, pay later with no interest or fees.' mod='twopayment' sprintf=[$two_product_name]}</span>
                        <a href="https://www.two.inc/resources/buyers" target="_blank" rel="noopener noreferrer" class="two-tooltip-link">
                            {l s='Learn more about %s' mod='twopayment' sprintf=[$two_product_name]} →
                        </a>
                    </span>
                </span>
                {/if}
            </p>
        </div>
    </div>
    
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
    
    <div class="two-loading-container" style="display: none;">
        <div class="two-loading-spinner"></div>
        <span class="two-loading-text">{l s='Checking availability...' mod='twopayment'}</span>
    </div>
    
    <section class="two-payment-info" style="display: none;">
        <p class="two-subtitle">{$subtitle|escape:'html':'UTF-8'}</p>
        <p class="two-payment-message"></p>
    </section>
    
    <div class="two-payment-terms" id="two-payment-terms" style="display: none;">
        <div class="two-terms-header">
            <h4 class="two-terms-title">{l s='Choose the Buy Now, Pay Later option that works best for you' mod='twopayment'}</h4>
            <p class="two-terms-description" id="two-terms-description" data-standard-text="{l s='Your payment period starts when your order is fulfilled' mod='twopayment'}" data-eom-text="{l s='Payment due at the end of the current month plus the selected days from when your order is fulfilled' mod='twopayment'}">
                {l s='Your payment period starts when your order is fulfilled' mod='twopayment'}
            </p>
        </div>
        <div class="two-term-chips">
            <div class="two-term-chips__container" id="two-terms-chips">
            </div>
            <div class="two-terms-selected">
                <span class="two-terms-selected-days" id="two-selected-days">30</span>
            </div>
        </div>
    </div>

    {* Company search, payment-tile location (TWO-25326 §7.1). The
       "Enable company search in address entry" switch
       (PS_ENABLE_COMPANY_SEARCH_IN_ADDRESS) picks WHERE the one shared control
       (TwoCompanySearch.js, never a second implementation) renders: address
       area (default, switch = Yes) or here (switch = No), so this block is only
       rendered when the switch is "No".

       TwoCheckoutManager.js mounts TwoCompanySearch on this field instead of
       the address form's. The selection is persisted the same way either way
       (TwoCompanySearch.persistCompanyToCookie -> the session company both the
       order-intent check and order creation read ahead of the address), so
       relocating the visible control does not depend on the address form's own
       `company`/`companyid` inputs at all. *}
    {if $company_search_tile}
    <div class="two-tile-company-search" id="two-tile-company-search">
        <div class="form-group">
            <label for="two_tile_company">{l s='Company' mod='twopayment'}</label>
            <input type="text" class="form-control" id="two_tile_company" name="two_tile_company" autocomplete="off" />
        </div>
    </div>
    {/if}

    {* Optional buyer reference fields. These live in the payment tile
       and not in the billing address block: PrestaShop asks for the shipping
       address first and only shows the billing block when the buyer ticks
       "Billing address differs from shipping address", so a field hosted there
       is invisible to most buyers.

       Each input is mirrored into a hidden twin inside the module's payment
       form by TwoOptionalFields.js; the form is a sibling of this markup, so
       these visible inputs are not themselves submitted. A field whose admin
       switch is off is absent from $two_optional_fields, so it renders no
       element at all rather than a hidden one. *}
    {if isset($two_optional_fields) && $two_optional_fields|@count}
        <div class="two-optional-fields" id="two-optional-fields">
            {foreach from=$two_optional_fields item="field"}
                <div class="two-optional-field two-optional-field--{$field.key|escape:'html':'UTF-8'}">
                    {* Display input tooltips (TWO-25386 #3, ported from
                       woocommerce-plugin's `display_tooltips`). Default OFF. *}
                    <label class="two-optional-field__label" for="two-field-{$field.key|escape:'html':'UTF-8'}"{if $display_tooltips} title="{$field.help|escape:'html':'UTF-8'}"{/if}>
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
