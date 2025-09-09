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
        {* State & Status Section *}
        {if $twopaymentdata.two_order_state || $twopaymentdata.two_order_status}
        <div class="two-section">
            <h4 class="two-section-title">{l s='State & Status' mod='twopayment'}</h4>
            <div class="two-status-cards">
                {if $twopaymentdata.two_order_state}
                <div class="two-status-card">
                    <span class="two-status-label">{l s='State' mod='twopayment'}</span>
                    <span class="two-order-state two-order-state-{$twopaymentdata.two_order_state|lower} two-status-highlight">{$twopaymentdata.two_order_state}</span>
                </div>
                {/if}
                {if $twopaymentdata.two_order_status}
                <div class="two-status-card">
                    <span class="two-status-label">{l s='Status' mod='twopayment'}</span>
                    <span class="two-order-status two-status-highlight">{$twopaymentdata.two_order_status}</span>
                </div>
                {/if}
            </div>
        </div>
        {/if}

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

        {* Actions Section *}
        <div class="two-section">
            <h4 class="two-section-title">{l s='Actions' mod='twopayment'}</h4>
            <div class="two-actions-grid">
                {if $twopaymentdata.two_order_id && $two_portal_url}
                <a href="{$two_portal_url}/merchant/orders/{$twopaymentdata.two_order_id}" target="_blank" rel="noopener noreferrer" class="two-action-card two-action-primary">
                    <i class="icon-external-link"></i>
                    <span class="two-action-title">{l s='View in Two Portal' mod='twopayment'}</span>
                    <span class="two-action-desc">{l s='Manage order in Two merchant portal' mod='twopayment'}</span>
                </a>
                {/if}
                {if $two_pdf_url}
                <a href="{$two_pdf_url}" target="_blank" rel="noopener noreferrer" class="two-action-card two-action-secondary">
                    <i class="icon-download"></i>
                    <span class="two-action-title">{l s='Download Invoice PDF' mod='twopayment'}</span>
                    <span class="two-action-desc">{l s='Get the Two-generated invoice' mod='twopayment'}</span>
                </a>
                {/if}
                {if $twopaymentdata.two_invoice_url}
                <a href="{$twopaymentdata.two_invoice_url}" target="_blank" class="two-action-card two-action-secondary">
                    <i class="icon-link"></i>
                    <span class="two-action-title">{l s='Invoice URL' mod='twopayment'}</span>
                    <span class="two-action-desc">{l s='Direct link to invoice' mod='twopayment'}</span>
                </a>
                {/if}
                {if $two_portal_url}
                <a href="{$two_portal_url}" target="_blank" rel="noopener noreferrer" class="two-action-card two-action-tertiary">
                    <i class="icon-cog"></i>
                    <span class="two-action-title">{l s='Two Portal' mod='twopayment'}</span>
                    <span class="two-action-desc">{l s='Manage your Two account' mod='twopayment'}</span>
                </a>
                {/if}
            </div>
        </div>
    </div>
</div>


