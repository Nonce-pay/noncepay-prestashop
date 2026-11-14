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
 * The PrestaShop operations the module needs, behind an interface so the flows run in tests.
 */
interface Shop
{
    /**
     * An order with the orders sharing its reference (one cart split by carrier is paid once).
     *
     * @return array{reference:string,total:string,currency:string,state:string,payable:bool}|null
     */
    public function order(int $orderId): ?array;

    public function transactionExists(string $transactionId): bool;

    /** Records the payment once per transaction id and moves the orders still awaiting it to "Payment accepted". */
    public function addPayment(int $orderId, string $transactionId, string $amount): bool;

    public function log(array $data, string $status, int $orderId = 0): void;

    public function lock(string $name): bool;

    public function unlock(string $name): void;
}
