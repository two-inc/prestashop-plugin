{*
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 *
 * TWO-25800: the product-page buy button.
 *
 * A button, never a nested form: this hook renders INSIDE the theme's
 * add-to-cart form, and a form inside a form is dropped by the parser, so the
 * button would simply not be there. It carries no destination either - the
 * form it sits in already knows where the shop's own add-to-cart posts, and a
 * second copy of that could only ever disagree with it.
 *
 * The mark and the label both come from the active brand, so the button always
 * names the method it commits the buyer to. The mark is decorative, because
 * the label beside it already carries that name in text.
 *}

<div class="two-product-button">
    <button type="button"
            class="btn two-product-button__cta"
            data-two-buy-now
            data-two-checkout-url="{$two_button_checkout_url|escape:'html':'UTF-8'}"
            data-two-unreachable="{$two_button_unreachable|escape:'html':'UTF-8'}">
        {* The mark REPLACES the brand word, matching the express buttons this
           was asked to sit beside, so it is not decorative: it carries the
           brand name as its alternative text and the control still announces
           "Buy with <brand>".

           This reverses an earlier decision to hide the mark from the
           accessibility tree. That was right only while the text named the
           brand; with the word gone, a decorative mark would leave the button
           announcing "Buy with" and no brand at all. *}
        {if $two_button_logo}
            <span class="two-product-button__label">{$two_button_label_lead|escape:'html':'UTF-8'}</span>
            <img src="{$module_dir|escape:'html':'UTF-8'}{$two_button_logo|escape:'html':'UTF-8'}"
                 alt="{$two_button_brand|escape:'html':'UTF-8'}"
                 class="two-product-button__mark" />
        {else}
            {* No mark to carry the name, so the text keeps it. No storefront
               may end up with a button that names no brand. *}
            <span class="two-product-button__label">{$two_button_label|escape:'html':'UTF-8'}</span>
        {/if}
    </button>
    {* Whatever core said when it refused the add, in core's own words. Empty
       until then, and announced when it is filled. *}
    <p class="two-product-button__error" role="alert" aria-live="polite"></p>
</div>
