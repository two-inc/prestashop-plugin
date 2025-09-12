{*
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 *}

<div class="tab-pane two-admin-tab" id="two-payment-info">
    <div class="two-tab-header">
        <div class="two-admin-header">
            <h3 class="two-admin-title">{l s='Two Payment Details' mod='twopayment'}</h3>
        </div>
    </div>
    
    <div class="two-tab-content">
        {* Order Information Section *}
        <div class="two-section">
            <h4 class="two-section-title">{l s='Order Information' mod='twopayment'}</h4>
            <div class="two-info-cards">
                {if $twopaymentdata.two_order_id}
                <div class="two-info-card">
                    <span class="two-info-label">{l s='Two Order ID' mod='twopayment'}</span>
                    <span class="two-info-value two-order-id">{$twopaymentdata.two_order_id}</span>
                </div>
                {/if}
                {if $twopaymentdata.two_order_reference}
                <div class="two-info-card">
                    <span class="two-info-label">{l s='Order Reference' mod='twopayment'}</span>
                    <span class="two-info-value">{$twopaymentdata.two_order_reference}</span>
                </div>
                {/if}
                {if $twopaymentdata.two_day_on_invoice}
                <div class="two-info-card">
                    <span class="two-info-label">{l s='Payment Terms' mod='twopayment'}</span>
                    <span class="two-info-value two-payment-terms">{$twopaymentdata.two_day_on_invoice} {l s='days' mod='twopayment'}</span>
                </div>
                {/if}
            </div>
        </div>

        {* Actions Section - now styled like info cards *}
        <div class="two-section">
            <h4 class="two-section-title">{l s='Actions' mod='twopayment'}</h4>
            <div class="two-info-cards">
                {if $twopaymentdata.two_order_id && $two_portal_url}
                <div class="two-info-card">
                    <span class="two-info-label">{l s='View in Two Portal' mod='twopayment'}</span>
                    <span class="two-info-value"><a href="{$two_portal_url}/merchant/orders/{$twopaymentdata.two_order_id}" target="_blank" rel="noopener noreferrer">{l s='Open' mod='twopayment'}</a></span>
                </div>
                {/if}
                {if $two_pdf_url}
                <div class="two-info-card">
                    <span class="two-info-label">{l s='Invoice PDF' mod='twopayment'}</span>
                    <span class="two-info-value"><a href="{$two_pdf_url}" target="_blank" rel="noopener noreferrer">{l s='Download' mod='twopayment'}</a></span>
                </div>
                {/if}
                {if $twopaymentdata.two_invoice_url}
                <div class="two-info-card">
                    <span class="two-info-label">{l s='Invoice URL' mod='twopayment'}</span>
                    <span class="two-info-value"><a href="{$twopaymentdata.two_invoice_url}" target="_blank" rel="noopener noreferrer">{l s='Open link' mod='twopayment'}</a></span>
                </div>
                {/if}
                {if $two_portal_url}
                <div class="two-info-card">
                    <span class="two-info-label">{l s='Two Portal' mod='twopayment'}</span>
                    <span class="two-info-value"><a href="{$two_portal_url}" target="_blank" rel="noopener noreferrer">{l s='Open' mod='twopayment'}</a></span>
                </div>
                {/if}
            </div>
        </div>
    </div>
</div>


