<?php
/**
 * NoncePay for PrestaShop
 *
 * @author    NoncePay <contact@noncepay.app>
 * @copyright 2026 NoncePay
 * @license   https://opensource.org/licenses/MIT MIT License
 */

namespace NoncePay\PrestaShop;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Shop on the real PrestaShop classes (Order, OrderHistory, PrestaShopLogger).
 */
final class PrestaShopShop implements Shop
{
    /** @var int The "Awaiting NoncePay payment" order state. */
    private $awaitingState;
    /** @var string Payment method shown on the order. */
    private $paymentMethod;

    public function __construct(int $awaitingState, string $paymentMethod)
    {
        $this->awaitingState = $awaitingState;
        $this->paymentMethod = $paymentMethod;
    }

    public function order(int $orderId): ?array
    {
        $order = new \Order($orderId);
        if (!\Validate::isLoadedObject($order) || $order->module !== 'noncepay') {
            return null;
        }
        // Read as a DECIMAL string: the total never goes through a float.
        $total = \Db::getInstance()->getValue('SELECT SUM(`total_paid_tax_incl`) FROM `' . _DB_PREFIX_ . "orders`
            WHERE `reference` = '" . pSQL($order->reference) . "'", false);
        $currency = new \Currency((int) $order->id_currency);
        $state = new \OrderState((int) $order->current_state, (int) \Configuration::get('PS_LANG_DEFAULT'));
        return [
            'reference' => (string) $order->reference,
            'total' => (string) $total,
            'currency' => (string) $currency->iso_code,
            'state' => is_string($state->name) ? $state->name : (string) $order->current_state,
            'payable' => (int) $order->current_state === $this->awaitingState,
        ];
    }

    public function transactionExists(string $transactionId): bool
    {
        return (bool) \Db::getInstance()->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . "order_payment`
            WHERE `transaction_id` = '" . pSQL($transactionId) . "'", false);
    }

    public function addPayment(int $orderId, string $transactionId, string $amount): bool
    {
        $order = new \Order($orderId);
        if (!\Validate::isLoadedObject($order)) {
            return false;
        }
        if (!$this->transactionExists($transactionId)) {
            $currency = new \Currency((int) $order->id_currency);
            if (!$order->addOrderPayment((float) $amount, $this->paymentMethod, $transactionId, $currency)) {
                return false;
            }
        }
        $paid = (int) \Configuration::get('PS_OS_PAYMENT');
        foreach (\Order::getByReference($order->reference) as $sibling) {
            if ((int) $sibling->current_state !== $this->awaitingState) {
                continue;
            }
            $history = new \OrderHistory();
            $history->id_order = (int) $sibling->id;
            // true: the payment above is linked to the invoice instead of a second one being created.
            $history->changeIdOrderState($paid, (int) $sibling->id, true);
            if (!$history->add()) {
                return false;
            }
            \Order::cleanHistoryCache();
            // As addWithemail(), but a mail that cannot be sent does not fail the recorded payment.
            $history->sendEmail(new \Order((int) $sibling->id));
        }
        return true;
    }

    public function log(array $data, string $status, int $orderId = 0): void
    {
        $parts = [];
        foreach ($data as $key => $value) {
            $parts[] = $key . '=' . (is_array($value) ? json_encode($value) : (string) $value);
        }
        // PrestaShopLogger refuses a message containing < > { }.
        $message = str_replace(['<', '>', '{', '}'], ['', '', '(', ')'], 'NoncePay: ' . $status . ' | ' . implode('; ', $parts));
        $severity = 1;
        if (strpos($status, 'review manually') !== false || strpos($status, 'Error') === 0) {
            $severity = 3;
        } elseif (in_array($status, ['Invalid signature', 'Unreadable callback', 'Link creation failed'], true)) {
            $severity = 2;
        }
        \PrestaShopLogger::addLog($message, $severity, null, $orderId > 0 ? 'Order' : null, $orderId > 0 ? $orderId : null, true);
    }

    public function lock(string $name): bool
    {
        return (string) \Db::getInstance()->getValue("SELECT GET_LOCK('" . pSQL($this->lockName($name)) . "', 10)", false) === '1';
    }

    public function unlock(string $name): void
    {
        \Db::getInstance()->getValue("SELECT RELEASE_LOCK('" . pSQL($this->lockName($name)) . "')", false);
    }

    private function lockName(string $name): string
    {
        return 'noncepay_' . substr(md5(_DB_NAME_ . _DB_PREFIX_), 0, 12) . '_' . $name;
    }
}
