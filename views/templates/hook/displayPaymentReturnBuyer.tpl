{*
 * Buyer-facing confirmation card for Two payment
 * Shows invoice terms and a link to Two Buyer Portal only
 *}

<div id="two-payment-info" class="box">
    <h4>{l s='Two Payment' mod='twopayment'}</h4>
    <div class="table-responsive">
        <table class="table table-bordered table-striped">
            <tbody>
                {if $twopaymentdata.two_day_on_invoice}
                    <tr>
                        <td><strong>{l s='Invoice Terms' mod='twopayment'}</strong></td>
                        <td>
                            {if isset($twopaymentdata.two_payment_term_type) && $twopaymentdata.two_payment_term_type == 'EOM'}
                                {l s='End of Month + %d days' sprintf=[$twopaymentdata.two_day_on_invoice|escape:'html':'UTF-8'] mod='twopayment'}
                            {else}
                                {l s='Standard + %d days' sprintf=[$twopaymentdata.two_day_on_invoice|escape:'html':'UTF-8'] mod='twopayment'}
                            {/if}
                        </td>
                    </tr>
                {/if}
                {if $two_buyer_portal_url}
                    <tr>
                        <td><strong>{l s='Two Buyer Portal' mod='twopayment'}</strong></td>
                        <td><a href="{$two_buyer_portal_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener noreferrer">{l s='Access your Two buyer portal to view this order once fulfilled' mod='twopayment'}</a></td>
                    </tr>
                {/if}
            </tbody>
        </table>
    </div>
</div>
