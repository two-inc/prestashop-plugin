{*
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 *}

<div class="panel two-admin-panel">
    <div class="panel-heading two-panel-heading">
        <div class="two-admin-header">
            <h3 class="two-admin-title">{l s='Two Payment Information' mod='twopayment'}</h3>
        </div>
    </div>
    <div class="panel-body two-admin-content">
        {* State & Status Section *}
        {if $twopaymentdata.two_order_state || $twopaymentdata.two_order_status}
        <div class="two-status-section">
            <h4 class="two-section-subtitle">{l s='State & Status' mod='twopayment'}</h4>
            <div class="two-status-items">
                {if $twopaymentdata.two_order_state}
                <div class="two-info-item">
                    <span class="two-info-label">{l s='State' mod='twopayment'}</span>
                    <span class="two-order-state two-order-state-{$twopaymentdata.two_order_state|lower} two-status-highlight">{$twopaymentdata.two_order_state}</span>
                </div>
                {/if}
                {if $twopaymentdata.two_order_status}
                <div class="two-info-item">
                    <span class="two-info-label">{l s='Status' mod='twopayment'}</span>
                    <span class="two-order-status two-status-highlight">{$twopaymentdata.two_order_status}</span>
                </div>
                {/if}
            </div>
        </div>
        {/if}

        {* Order Details Section *}
        <div class="two-details-section">
            {if $twopaymentdata.two_order_id}
            <div class="two-info-item">
                <span class="two-info-label">{l s='Two Order ID' mod='twopayment'}</span>
                <span class="two-info-value two-order-id">{$twopaymentdata.two_order_id}</span>
            </div>
            {/if}
            {if $twopaymentdata.two_order_reference}
            <div class="two-info-item">
                <span class="two-info-label">{l s='Reference' mod='twopayment'}</span>
                <span class="two-info-value">{$twopaymentdata.two_order_reference}</span>
            </div>
            {/if}
            {if $twopaymentdata.two_day_on_invoice}
            <div class="two-info-item">
                <span class="two-info-label">{l s='Payment Terms' mod='twopayment'}</span>
                <span class="two-info-value two-payment-terms">{$twopaymentdata.two_day_on_invoice} {l s='days' mod='twopayment'}</span>
            </div>
            {/if}
        </div>

        {* Action Links Section *}
        <div class="two-actions-section">
            {if $twopaymentdata.two_order_id && $two_portal_url}
            <a href="{$two_portal_url}/merchant/orders/{$twopaymentdata.two_order_id}" target="_blank" rel="noopener noreferrer" class="two-action-link two-action-primary">
                <i class="icon-external-link"></i> {l s='View in Portal' mod='twopayment'}
            </a>
            {/if}
            {if $two_pdf_url}
            <a href="{$two_pdf_url}" target="_blank" rel="noopener noreferrer" class="two-action-link two-action-secondary">
                <i class="icon-download"></i> {l s='Download Invoice' mod='twopayment'}
            </a>
            {/if}
            {if $twopaymentdata.two_invoice_url}
            <a href="{$twopaymentdata.two_invoice_url}" target="_blank" class="two-action-link two-action-secondary">
                <i class="icon-link"></i> {l s='Invoice URL' mod='twopayment'}
            </a>
            {/if}
        </div>
    </div>
</div>

