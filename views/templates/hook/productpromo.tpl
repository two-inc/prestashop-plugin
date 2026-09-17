{*
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 *
 * TWO-25799: the product-page promotional message.
 *
 * The mark is a real <img> carrying the brand's product name as its alt text,
 * so the badge is attributed whether or not the image loads and whatever the
 * shopper is reading it with. A CSS background would carry no alternative text
 * at all, which is the trap the Magento build had to work around.
 *}

<div class="two-product-promo">
    <img src="{$module_dir|escape:'html':'UTF-8'}views/img/TwoLogo.svg"
         alt="{$two_product_name|escape:'html':'UTF-8'}"
         class="two-product-promo__mark" />
    <span class="two-product-promo__text">{$two_product_message|escape:'html':'UTF-8'}</span>
</div>
