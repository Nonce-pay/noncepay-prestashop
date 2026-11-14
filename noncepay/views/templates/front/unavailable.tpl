{**
 * NoncePay for PrestaShop
 *
 * @author    NoncePay <contact@noncepay.app>
 * @copyright 2026 NoncePay
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
{extends file='page.tpl'}

{block name='page_content'}
  <section class="noncepay-unavailable">
    <p class="alert alert-warning">{l s='Crypto payment is temporarily unavailable. Your order %s is saved: please try again in a few minutes.' sprintf=[$noncepay_reference|escape:'htmlall':'UTF-8'] mod='noncepay'}</p>
    <p>
      <a class="btn btn-primary" href="{$noncepay_retry_url|escape:'htmlall':'UTF-8'}">{l s='Try again' mod='noncepay'}</a>
      <a class="btn btn-secondary" href="{$noncepay_order_url|escape:'htmlall':'UTF-8'}">{l s='View my order' mod='noncepay'}</a>
    </p>
  </section>
{/block}
