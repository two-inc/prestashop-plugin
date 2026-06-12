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

    {* Sole trader flow (TWO-24755) - shown by TwoSoleTrader.js when the
       buyer's account type is sole_trader *}
    <div class="two-sole-trader" style="display: none;">
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
        <div class="two-terms-slider-container">
            <div class="two-terms-slider" id="two-terms-slider">
                {* Terms will be populated by JavaScript *}
            </div>
            <div class="two-terms-selected">
                <span class="two-terms-selected-days" id="two-selected-days">30</span>
            </div>
        </div>
    </div>
</div>
