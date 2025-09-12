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
                        <td>{l s='%d days' sprintf=[$twopaymentdata.two_day_on_invoice] mod='twopayment'}</td>
                    </tr>
                {/if}
                {if $two_buyer_portal_url}
                    <tr>
                        <td><strong>{l s='Two Buyer Portal' mod='twopayment'}</strong></td>
                        <td><a href="{$two_buyer_portal_url}" target="_blank" rel="noopener noreferrer">{l s='Access your Two buyer portal to view this order once fulfilled' mod='twopayment'}</a></td>
                    </tr>
                {/if}
            </tbody>
        </table>
    </div>
</div>


