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
                    <span class="two-info-value two-payment-terms">
                        {if $twopaymentdata.two_payment_term_type == 'EOM'}
                            {l s='End of Month' mod='twopayment'} + {$twopaymentdata.two_day_on_invoice} {l s='days' mod='twopayment'}
                            <span class="two-term-type-badge" title="{l s='Payment due: end of current month at fulfillment + payment term days' mod='twopayment'}">EOM</span>
                        {else}
                            {$twopaymentdata.two_day_on_invoice} {l s='days' mod='twopayment'}
                        {/if}
                    </span>
                </div>
                {/if}
            </div>
        </div>

        {* Invoice Upload Status Section *}
        {if $use_own_invoices}
        <div class="two-section">
            <h4 class="two-section-title">{l s='Invoice Upload Status' mod='twopayment'}</h4>
            <div class="two-info-cards">
                {if $twopaymentdata.two_invoice_upload_status}
                <div class="two-info-card">
                    <span class="two-info-label">{l s='Upload Status' mod='twopayment'}</span>
                    <span class="two-info-value">
                        {if $twopaymentdata.two_invoice_upload_status == 'UPLOADED'}
                            <span class="badge badge-success">{l s='Uploaded' mod='twopayment'}</span>
                        {elseif $twopaymentdata.two_invoice_upload_status == 'UPLOADING'}
                            <span class="badge badge-info">{l s='Uploading...' mod='twopayment'}</span>
                        {elseif $twopaymentdata.two_invoice_upload_status == 'PENDING'}
                            <span class="badge badge-warning">{l s='Pending' mod='twopayment'}</span>
                        {elseif $twopaymentdata.two_invoice_upload_status == 'FAILED'}
                            <span class="badge badge-danger">{l s='Failed' mod='twopayment'}</span>
                        {elseif $twopaymentdata.two_invoice_upload_status == 'NOT_APPLICABLE'}
                            <span class="badge badge-secondary">{l s='N/A' mod='twopayment'}</span>
                        {else}
                            <span class="badge badge-secondary">{$twopaymentdata.two_invoice_upload_status}</span>
                        {/if}
                    </span>
                </div>
                {/if}
                {if $twopaymentdata.two_invoice_uploaded_at}
                <div class="two-info-card">
                    <span class="two-info-label">{l s='Uploaded At' mod='twopayment'}</span>
                    <span class="two-info-value">{$twopaymentdata.two_invoice_uploaded_at}</span>
                </div>
                {/if}
                {if $twopaymentdata.two_invoice_upload_reference}
                <div class="two-info-card">
                    <span class="two-info-label">{l s='Upload Reference' mod='twopayment'}</span>
                    <span class="two-info-value two-monospace">{$twopaymentdata.two_invoice_upload_reference}</span>
                </div>
                {/if}
                {if $twopaymentdata.two_invoice_upload_error}
                <div class="two-info-card two-error-card">
                    <span class="two-info-label">{l s='Error Message' mod='twopayment'}</span>
                    <span class="two-info-value two-error-message">{$twopaymentdata.two_invoice_upload_error}</span>
                </div>
                {/if}
            </div>
        </div>
        {/if}

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


