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
 * The payment link of an order awaiting payment: reused while it is open, created otherwise.
 */
final class Checkout
{
    const LINK_DAYS = 7;
    // A stored link is reused only while it has at least this long left to be paid.
    const REUSE_MARGIN = 3600;
    const MAX_DESCRIPTION = 128;

    /** @var Settings */
    private $settings;
    /** @var Store */
    private $store;
    /** @var Api */
    private $api;
    /** @var Shop */
    private $shop;
    /** @var int */
    private $now;

    public function __construct(Settings $settings, Store $store, Api $api, Shop $shop, int $now)
    {
        $this->settings = $settings;
        $this->store = $store;
        $this->api = $api;
        $this->shop = $shop;
        $this->now = $now;
    }

    /**
     * The payment page of the order's open link for its current total, created when there is none.
     *
     * @param array{redirect_url:string,callback_url:string,description:string} $page
     *
     * @throws ApiException
     */
    public function paymentUrl(int $orderId, array $page): string
    {
        if ($this->settings->errors) {
            throw new ApiException('NoncePay is not configured: ' . implode(' ', $this->settings->errors));
        }
        $order = $this->shop->order($orderId);
        if ($order === null || !$order['payable']) {
            throw new ApiException('Order ' . $orderId . ' is not awaiting a NoncePay payment');
        }
        $amount = Decimal::parse($order['total']);
        if ($amount === null || Decimal::isZero($amount)) {
            throw new ApiException('Order ' . $orderId . ' has no amount to pay');
        }
        $currency = strtoupper($order['currency']);
        if (!Settings::currencySupported($currency)) {
            throw new ApiException('NoncePay cannot price an order in ' . $currency);
        }

        $row = $this->store->reusableLink($orderId, $amount, $currency, $this->now + self::REUSE_MARGIN);
        if ($row) {
            try {
                $status = $this->api->linkStatus((string) $row['link_id']);
            } catch (ApiException $e) {
                $status = 'open';
            }
            if ($status === 'open' || $status === 'paid') {
                return (string) $row['payment_url'];
            }
        }

        $expiresAt = $this->now + self::LINK_DAYS * 86400;
        $link = $this->api->createLink($this->linkQuery($amount, $currency, $expiresAt, $page));
        $this->store->insertLink([
            'id_order' => $orderId,
            'link_id' => $link['link_id'],
            'payment_url' => $link['payment_url'],
            'success_token' => $link['success_token'],
            'amount' => $amount,
            'currency' => $currency,
            'tolerance' => $this->settings->tolerance,
            'expires_at' => $expiresAt,
            'status' => 'open',
            'created_at' => $this->now,
        ]);
        return $link['payment_url'];
    }

    public function linkQuery(string $amount, string $currency, int $expiresAt, array $page): array
    {
        $query = [
            'kind' => 'payment',
            'value' => $amount,
            'currency' => strtolower($currency),
        ];
        foreach ($this->settings->wallets as $family => $wallet) {
            $query['address_' . $family] = $wallet;
        }
        if ($this->settings->coins !== '') {
            $query['coins'] = $this->settings->coins;
        }
        $query['callback'] = (string) $page['callback_url'];
        $query['json'] = 1;
        $query['redirect_url'] = (string) $page['redirect_url'];
        $query['item_description'] = Settings::truncate((string) $page['description'], self::MAX_DESCRIPTION);
        if ($this->settings->merchantName !== '') {
            $query['merchant_name'] = $this->settings->merchantName;
        }
        if ($this->settings->logoUrl !== '') {
            $query['logo_url'] = $this->settings->logoUrl;
        }
        $query['expire_at'] = $expiresAt;
        if (!Decimal::isZero($this->settings->tolerance)) {
            $query['tolerance'] = $this->settings->tolerance;
        }
        return $query;
    }

    /** The link the customer comes back with, when its token matches: proof they reached the paid page. */
    public function returnedFromPaidLink(int $orderId, string $linkId, string $token): bool
    {
        if ($linkId === '' || $token === '') {
            return false;
        }
        $row = $this->store->findLink($linkId);
        return $row !== null && (int) $row['id_order'] === $orderId && hash_equals((string) $row['success_token'], $token);
    }
}
