{**
 * NoncePay for PrestaShop
 *
 * @author    NoncePay <contact@noncepay.app>
 * @copyright 2026 NoncePay
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
<section class="noncepay-status">
  {if $noncepay_status == 'confirming'}
    <p class="alert alert-success">{l s='Payment received. It is being confirmed, and your order will be marked paid automatically in a moment.' mod='noncepay'}</p>
  {elseif $noncepay_status == 'awaiting'}
    <p class="alert alert-info">{l s='Your order %s of %s is awaiting your crypto payment.' sprintf=[$noncepay_reference|escape:'htmlall':'UTF-8', $noncepay_total|escape:'htmlall':'UTF-8'] mod='noncepay'}</p>
    <p>
      <a class="btn btn-primary" href="{$noncepay_pay_url|escape:'htmlall':'UTF-8'}">{l s='Pay with crypto' mod='noncepay'}</a>
    </p>
    <p class="small">{l s='Already paid? The payment is confirmed automatically, you do not need to pay again.' mod='noncepay'}</p>
  {elseif $noncepay_status == 'paid'}
    <p class="alert alert-success">{l s='Your crypto payment for order %s has been received. Thank you!' sprintf=[$noncepay_reference|escape:'htmlall':'UTF-8'] mod='noncepay'}</p>
  {/if}
</section>
