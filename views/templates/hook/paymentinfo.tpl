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
    
    {* Header Section *}
    <div class="two-header">
        <div class="two-logo-container">
            <img src="{$module_dir}views/img/TwoLogo.svg" alt="Two" class="two-logo" />
            <p class="two-tagline">
                Business payments made simple 
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
            <span>No upfront costs</span>
        </div>
        <div class="two-benefit-item">
            <span class="two-benefit-icon">✓</span>
            <span>Instant approval check</span>
        </div>
    </div>
    
    {* Loading State - Initially Hidden *}
    <div class="two-loading-container" style="display: none;">
        <div class="two-loading-spinner"></div>
        <span class="two-loading-text">Checking availability...</span>
    </div>
    
    {* Payment Info Section - Dynamically populated by JavaScript *}
    <section class="two-payment-info" style="display: none;">
        <p class="two-subtitle">{$subtitle}</p>
        <p class="two-payment-message"></p>
    </section>
    
    {* Payment Terms Selector - Shows after approval *}
    <div class="two-payment-terms" id="two-payment-terms" style="display: none;">
        <div class="two-terms-header">
            <h4 class="two-terms-title">{l s='Choose the Buy Now, Pay Later option that works best for you' mod='twopayment'}</h4>
            <p class="two-terms-description">{l s=' Your payment period starts when your order is fulfilled, along with your invoice from Two' mod='twopayment'}</p>
        </div>
        <div class="two-terms-slider-container">
            <div class="two-terms-slider" id="two-terms-slider">
                {* Terms will be populated by JavaScript *}
            </div>
            <div class="two-terms-selected">
                <span class="two-terms-selected-text">{l s='Pay in' mod='twopayment'}</span>
                <span class="two-terms-selected-days" id="two-selected-days">30</span>
                <span class="two-terms-selected-unit">{l s='days' mod='twopayment'}</span>
            </div>
        </div>
    </div>
</div>
