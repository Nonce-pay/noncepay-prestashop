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
 * The signed NoncePay callback. Links are created with json=1, so the signature covers the raw body.
 *
 * Answers *ok* (HTTP 200) to every signed notification it has handled or can never handle, so NoncePay
 * stops retrying; anything else (bad signature, busy, an error) is retried by NoncePay.
 */
final class Callback
{
    public const OK = [200, '*ok*'];

    /** @var Store */
    private $store;
    /** @var Shop */
    private $shop;
    /** @var PublicKey */
    private $key;
    /** @var int */
    private $now;

    public function __construct(Store $store, Shop $shop, PublicKey $key, int $now)
    {
        $this->store = $store;
        $this->shop = $shop;
        $this->key = $key;
        $this->now = $now;
    }

    /**
     * @return array{0:int,1:string} HTTP status and body
     */
    public function handle(string $body, string $signature, array $query): array
    {
        if (!$this->key->verify($body, $signature)) {
            $this->shop->log(['query' => self::scalars($query), 'signature' => $signature === '' ? 'missing' : 'invalid'], 'Invalid signature');

            return [401, 'Bad signature'];
        }

        $fields = json_decode($body, true);
        if (!is_array($fields)) {
            $this->shop->log(['query' => self::scalars($query), 'body' => substr($body, 0, 500)], 'Unreadable callback');

            return self::OK;
        }
        $fields = self::scalars($fields);

        if (($fields['type'] ?? '') !== 'payment') {
            $orderId = (int) ($query['id_order'] ?? 0);
            $this->shop->log($fields, 'Deposit notification', $orderId);

            return self::OK;
        }

        $linkId = (string) ($fields['link_id'] ?? '');
        $row = preg_match('/^[A-Za-z0-9-]{1,64}$/', $linkId) ? $this->store->findLink($linkId) : null;
        if ($row === null || ($fields['is_paid'] ?? '') !== '1') {
            $this->shop->log($fields, 'Unknown link, ignored');

            return self::OK;
        }

        $orderId = (int) $row['id_order'];
        if (!$this->shop->lock('order_' . $orderId)) {
            return [503, 'Busy, retry later'];
        }
        try {
            return $this->settle($row, $fields, $orderId);
        } catch (\Throwable $e) {
            $this->shop->log(array_merge($fields, ['error' => $e->getMessage()]), 'Error, NoncePay will retry', $orderId);

            return [503, 'Error, retry later'];
        } finally {
            $this->shop->unlock('order_' . $orderId);
        }
    }

    private function settle(array $row, array $fields, int $orderId): array
    {
        $linkId = (string) $row['link_id'];
        $order = $this->shop->order($orderId);

        // A payment recorded before a failed state change stays payable, so its retry completes it.
        if ($order !== null && !$order['payable'] && $this->shop->transactionExists($linkId)) {
            $this->store->markPaid($linkId, $this->now);
            $this->shop->log($fields, 'Duplicate, already recorded', $orderId);

            return self::OK;
        }

        $value = Decimal::parse($fields['value'] ?? null);
        $paid = Decimal::parse($fields['paid_amount_fiat'] ?? null);
        $sameLink = $value !== null && Decimal::cmp($value, (string) $row['amount']) === 0
            && strtoupper((string) ($fields['currency'] ?? '')) === strtoupper((string) $row['currency']);
        if (!$sameLink || $paid === null || !Decimal::covers($paid, (string) $row['amount'], (string) $row['tolerance'])) {
            $this->shop->log($fields, 'Amount mismatch, review manually', $orderId);

            return self::OK;
        }

        if ($order === null || !$order['payable']) {
            $this->store->markPaid($linkId, $this->now);
            $state = $order === null ? 'a missing order' : 'an order in state "' . $order['state'] . '"';
            $this->shop->log($fields, 'Paid link on ' . $state . ', review manually', $orderId);

            return self::OK;
        }

        // What was paid, raised to the link amount when short within the tolerance; an overpayment is kept as is.
        $amount = Decimal::max($paid, (string) $row['amount']);
        if (!$this->shop->addPayment($orderId, $linkId, $amount)) {
            throw new \RuntimeException('PrestaShop refused the payment');
        }
        $this->store->markPaid($linkId, $this->now);
        $this->shop->log(array_merge($fields, [
            'recorded_amount' => $amount,
            'note' => 'Fee recorded as 0: the NoncePay fee is deducted from the crypto forwarded to your wallet.',
        ]), 'Success', $orderId);

        return self::OK;
    }

    /** Only string fields, so nothing nested or odd reaches the log or the checks. */
    private static function scalars(array $fields): array
    {
        $out = [];
        foreach ($fields as $key => $value) {
            if (is_scalar($value)) {
                $out[(string) $key] = substr((string) $value, 0, 2000);
            }
        }

        return $out;
    }
}
