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
        {* Order Details Section *}
        <div class="two-details-section">
            {if $twopaymentdata.two_order_id}
            <div class="two-info-item">
                <span class="two-info-label">{l s='Two Order ID' mod='twopayment'}</span>
                <span class="two-info-value two-order-id">{$twopaymentdata.two_order_id|escape:'html':'UTF-8'}</span>
            </div>
            {/if}
            {if $twopaymentdata.two_order_reference}
            <div class="two-info-item">
                <span class="two-info-label">{l s='Reference' mod='twopayment'}</span>
                <span class="two-info-value">{$twopaymentdata.two_order_reference|escape:'html':'UTF-8'}</span>
            </div>
            {/if}
            {if $twopaymentdata.two_day_on_invoice}
            <div class="two-info-item">
                <span class="two-info-label">{l s='Payment Terms' mod='twopayment'}</span>
                <span class="two-info-value two-admin-payment-terms">
                    {if $twopaymentdata.two_payment_term_type == 'EOM'}
                        {l s='End of Month' mod='twopayment'} + {$twopaymentdata.two_day_on_invoice|escape:'html':'UTF-8'} {l s='days' mod='twopayment'}
                        <span class="two-term-type-badge">EOM</span>
                    {else}
                        {$twopaymentdata.two_day_on_invoice|escape:'html':'UTF-8'} {l s='days' mod='twopayment'}
                    {/if}
                </span>
            </div>
            {else}
            <div class="two-info-item">
                <span class="two-info-label">{l s='Payment Terms' mod='twopayment'}</span>
                <span class="two-info-value text-muted">{l s='Not recorded for this order' mod='twopayment'}</span>
            </div>
            {/if}
            {if $twopaymentdata.two_order_state}
            <div class="two-info-item">
                <span class="two-info-label">{l s='Two State' mod='twopayment'}</span>
                <span class="two-info-value">{$twopaymentdata.two_order_state|escape:'html':'UTF-8'}</span>
            </div>
            {/if}
            {if $twopaymentdata.two_order_status}
            <div class="two-info-item">
                <span class="two-info-label">{l s='Two Status' mod='twopayment'}</span>
                <span class="two-info-value">{$twopaymentdata.two_order_status|escape:'html':'UTF-8'}</span>
            </div>
            {/if}
        </div>

        {* Action Links Section *}
        <div class="two-actions-section">
            {if $twopaymentdata.two_order_id && $two_portal_url}
            <a href="{$two_portal_url|escape:'html':'UTF-8'}/merchant/orders/{$twopaymentdata.two_order_id|escape:'url':'UTF-8'}" target="_blank" rel="noopener noreferrer" class="two-action-link two-action-primary">
                <i class="icon-external-link"></i> {l s='View in Portal' mod='twopayment'}
            </a>
            {/if}
            {if $two_invoice_actions_available && $two_pdf_url}
            <a href="{$two_pdf_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener noreferrer" class="two-action-link two-action-secondary">
                <i class="icon-download"></i> {l s='Download Invoice' mod='twopayment'}
            </a>
            {/if}
            {if $two_invoice_actions_available && $twopaymentdata.two_invoice_url}
            <a href="{$twopaymentdata.two_invoice_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener noreferrer" class="two-action-link two-action-secondary">
                <i class="icon-link"></i> {l s='Invoice URL' mod='twopayment'}
            </a>
            {/if}
            {if !$two_invoice_actions_available}
            <div class="two-info-value text-muted">
                <i class="icon-info-circle"></i> {l s='Invoice links become available after the Two order is fulfilled.' mod='twopayment'}
            </div>
            {/if}
        </div>
    </div>
</div>
