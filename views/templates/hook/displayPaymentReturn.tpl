{*
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 *}

<div id="two-payment-info" class="box">
    <h4>{l s='%s Payment Info' mod='twopayment' sprintf=[$two_product_name]}</h4>
    <div class="table-responsive">
        <table class="table table-bordered table-striped">
            <tbody>
                {if $twopaymentdata.two_order_id}
                    <tr><td><strong>{l s='%s Order ID' mod='twopayment' sprintf=[$two_product_name]}</strong></td> <td>{$twopaymentdata.two_order_id|escape:'html':'UTF-8'}</td></tr>
                {/if}
                {if $twopaymentdata.two_order_reference}
                    <tr><td><strong>{l s='%s Order Reference' mod='twopayment' sprintf=[$two_product_name]}</strong></td> <td>{$twopaymentdata.two_order_reference|escape:'html':'UTF-8'}</td></tr>
                {/if}
                {if $twopaymentdata.two_day_on_invoice}
                    <tr><td><strong>{l s='%s Day On Invoice' mod='twopayment' sprintf=[$two_product_name]}</strong></td> <td>{$twopaymentdata.two_day_on_invoice|escape:'html':'UTF-8'}</td></tr>
                {/if}
                {if $twopaymentdata.two_invoice_url}
                    <tr><td><strong>{l s='%s Invoice Url' mod='twopayment' sprintf=[$two_product_name]}</strong></td> <td><a href="{$twopaymentdata.two_invoice_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener noreferrer">{$twopaymentdata.two_invoice_url|escape:'html':'UTF-8'}</a></td></tr>
                {/if}
                {if $twopaymentdata.two_order_id && $two_portal_url}
                    <tr><td><strong>{l s='View Order on Portal' mod='twopayment'}</strong></td> <td><a href="{$two_portal_url|escape:'html':'UTF-8'}/merchant/orders/{$twopaymentdata.two_order_id|escape:'url':'UTF-8'}" target="_blank" rel="noopener noreferrer">{l s='View order details' mod='twopayment'}</a></td></tr>
                {/if}
                {if $two_pdf_url}
                    <tr><td><strong>{l s='Download PDF Invoice' mod='twopayment'}</strong></td> <td><a href="{$two_pdf_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener noreferrer">{l s='Download invoice PDF' mod='twopayment'}</a></td></tr>
                {/if}
                {if $two_portal_url}
                    <tr><td><strong>{l s='%s Portal' mod='twopayment' sprintf=[$two_product_name]}</strong></td> <td><a href="{$two_portal_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener noreferrer">{l s='Manage your %s account' mod='twopayment' sprintf=[$two_product_name]}</a></td></tr>
                {/if}
            </tbody>
        </table>
    </div>
</div>
