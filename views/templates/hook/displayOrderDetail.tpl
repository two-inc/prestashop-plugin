{*
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 *}

<div id="two-payment-info" class="box">
    <h4>{l s='Two Payment Info' mod='twopayment'}</h4>
    <div class="table-responsive">
        <table class="table table-bordered table-striped">
            <tbody>
                {if $twopaymentdata.two_order_id}
                    <tr><td><strong>{l s='Two Order ID' mod='twopayment'}</strong></td> <td>{$twopaymentdata.two_order_id}</td></tr>
                {/if}
                {if $twopaymentdata.two_order_reference}
                    <tr><td><strong>{l s='Two Order Reference' mod='twopayment'}</strong></td> <td>{$twopaymentdata.two_order_reference}</td></tr>
                {/if}
                {if $twopaymentdata.two_day_on_invoice}
                    <tr><td><strong>{l s='Two Day On Invoice' mod='twopayment'}</strong></td> <td>{$twopaymentdata.two_day_on_invoice}</td></tr>
                {/if}
                {if $twopaymentdata.two_invoice_url}
                    <tr><td><strong>{l s='Two Invoice Url' mod='twopayment'}</strong></td> <td><a href="{$twopaymentdata.two_invoice_url}" target="_blank">{$twopaymentdata.two_invoice_url}</a></td></tr>
                {/if}
                {if $twopaymentdata.two_order_id && $two_portal_url}
                    <tr><td><strong>{l s='View Order on Portal' mod='twopayment'}</strong></td> <td><a href="{$two_portal_url}/merchant/orders/{$twopaymentdata.two_order_id}" target="_blank" rel="noopener noreferrer">{l s='View order details' mod='twopayment'}</a></td></tr>
                {/if}
                {if $two_pdf_url}
                    <tr><td><strong>{l s='Download PDF Invoice' mod='twopayment'}</strong></td> <td><a href="{$two_pdf_url}" target="_blank" rel="noopener noreferrer">{l s='Download invoice PDF' mod='twopayment'}</a></td></tr>
                {/if}
                {if $two_portal_url}
                    <tr><td><strong>{l s='Two Portal' mod='twopayment'}</strong></td> <td><a href="{$two_portal_url}" target="_blank" rel="noopener noreferrer">{l s='Manage your Two account' mod='twopayment'}</a></td></tr>
                {/if}
            </tbody>
        </table>
    </div>
</div>