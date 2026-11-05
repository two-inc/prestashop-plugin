<div id="two-payment-info" class="box">
    <h4>{l s='%s Payment' mod='twopayment' sprintf=[$two_product_name]}</h4>
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
            </tbody>
        </table>
    </div>
</div>
