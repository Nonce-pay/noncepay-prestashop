<?php

namespace NoncePay\PrestaShop\Tests\Support;

use NoncePay\PrestaShop\Shop;

/**
 * Mimics the PrestaShop order operations the module calls, with order_payment as a list of payments.
 */
final class FakeShop implements Shop
{
    const AWAITING = 'Awaiting NoncePay payment';

    /** @var array<int,array{reference:string,total:string,currency:string,state:string}> */
    public $orders = [];
    /** @var array[] id_order, transaction_id, amount */
    public $payments = [];
    /** @var array[] data, status, id_order */
    public $logs = [];
    /** @var bool */
    public $lockFree = true;
    /** @var bool Refuses to record a payment. */
    public $refusePayment = false;
    /** @var bool Records the payment, then fails to change the state (a crash between the two). */
    public $failStateChange = false;

    public function order(int $orderId): ?array
    {
        if (!isset($this->orders[$orderId])) {
            return null;
        }
        $order = $this->orders[$orderId];
        $order['payable'] = $order['state'] === self::AWAITING;
        return $order;
    }

    public function transactionExists(string $transactionId): bool
    {
        foreach ($this->payments as $payment) {
            if ($payment['transaction_id'] === $transactionId) {
                return true;
            }
        }
        return false;
    }

    public function addPayment(int $orderId, string $transactionId, string $amount): bool
    {
        if ($this->refusePayment) {
            return false;
        }
        if (!$this->transactionExists($transactionId)) {
            $this->payments[] = ['id_order' => $orderId, 'transaction_id' => $transactionId, 'amount' => $amount];
        }
        if ($this->failStateChange) {
            return false;
        }
        $this->orders[$orderId]['state'] = 'Payment accepted';
        return true;
    }

    public function log(array $data, string $status, int $orderId = 0): void
    {
        $this->logs[] = ['data' => $data, 'status' => $status, 'id_order' => $orderId];
    }

    public function lock(string $name): bool
    {
        return $this->lockFree;
    }

    public function unlock(string $name): void
    {
    }

    public function lastStatus(): string
    {
        return $this->logs ? $this->logs[count($this->logs) - 1]['status'] : '';
    }
}
