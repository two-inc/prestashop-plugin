{*
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 *
 * TWO-25799: the product-page promotional message.
 *
 * The mark is resolved from the active brand, never hardcoded, so it always
 * identifies the same brand as the name beside it — a hardcoded asset would
 * put Two's logo on an overlay's storefront the day one exists, and would
 * disagree with the configurable product name today.
 *
 * A brand shipping no mark renders its name as visible text instead, so no
 * storefront ever carries an unattributed offer.
 *}

<div class="two-product-promo">
    {if $two_product_logo}
        <img src="{$module_dir|escape:'html':'UTF-8'}{$two_product_logo|escape:'html':'UTF-8'}"
             alt="{$two_product_name|escape:'html':'UTF-8'}"
             class="two-product-promo__mark" />
    {elseif $two_product_name}
        <span class="two-product-promo__brand">{$two_product_name|escape:'html':'UTF-8'}</span>
    {/if}
    <span class="two-product-promo__text">{$two_product_message|escape:'html':'UTF-8'}</span>
</div>
