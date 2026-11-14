<?php
/**
 * NoncePay for PrestaShop
 *
 * @author    NoncePay <contact@noncepay.app>
 * @copyright 2026 NoncePay
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Places the order in "Awaiting NoncePay payment" and sends the customer to its payment page.
 * With id_order and key, sends the customer back to the payment page of an order placed earlier.
 */
class NoncepayValidationModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    /** @var Noncepay */
    public $module;

    /** @var Order|null */
    private $order;

    public function postProcess()
    {
        $this->order = Tools::getValue('id_order') ? $this->existingOrder() : $this->placeOrder();

        if ((int) $this->order->current_state !== $this->module->awaitingState()) {
            Tools::redirect($this->module->confirmationUrl($this->order));
        }
        if (Tools::getValue('unavailable')) {
            return;
        }
        try {
            $url = $this->module->paymentUrl($this->order);
        } catch (Exception $e) {
            $this->module->shop()->log(['error' => $e->getMessage()], 'Link creation failed', (int) $this->order->id);
            // Rendered on a fresh request: validateOrder() left this one's context with a logged-out customer.
            $url = $this->module->payUrl($this->order, ['unavailable' => 1]);
        }
        Tools::redirect($url);
    }

    public function initContent()
    {
        parent::initContent();
        $this->context->smarty->assign([
            'noncepay_retry_url' => $this->module->payUrl($this->order),
            'noncepay_order_url' => $this->module->confirmationUrl($this->order),
            'noncepay_reference' => $this->order->reference,
        ]);
        $this->setTemplate('module:noncepay/views/templates/front/unavailable.tpl');
    }

    private function existingOrder()
    {
        $order = new Order((int) Tools::getValue('id_order'));
        if (!Validate::isLoadedObject($order) || $order->module !== $this->module->name
            || !hash_equals((string) $order->secure_key, (string) Tools::getValue('key'))) {
            Tools::redirect('index.php');
        }

        return $order;
    }

    private function placeOrder()
    {
        $cart = $this->context->cart;
        if (!Validate::isLoadedObject($cart) || !$cart->id_customer || !$cart->id_address_delivery || !$cart->id_address_invoice) {
            Tools::redirect($this->context->link->getPageLink('order', true, null, ['step' => 1]));
        }
        $customer = new Customer((int) $cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect($this->context->link->getPageLink('order', true, null, ['step' => 1]));
        }

        // A second submit of the same checkout continues with the order the first one placed.
        if ($cart->orderExists()) {
            $order = new Order((int) Order::getIdByCartId((int) $cart->id));
            if (!Validate::isLoadedObject($order) || (int) $order->id_customer !== (int) $customer->id) {
                Tools::redirect('index.php');
            }

            return $order;
        }

        if (!$this->module->isAvailableFor($cart)) {
            Tools::redirect($this->context->link->getPageLink('order', true, null, ['step' => 3]));
        }

        $this->module->validateOrder(
            (int) $cart->id,
            $this->module->awaitingState(),
            (float) $cart->getOrderTotal(true, Cart::BOTH),
            $this->module->displayName,
            null,
            [],
            (int) $cart->id_currency,
            false,
            $customer->secure_key
        );

        return new Order((int) $this->module->currentOrder);
    }
}
