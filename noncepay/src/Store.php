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
 * Persistence for the links created per order and the cached public key.
 *
 * A link row: id_order, link_id, payment_url, success_token, amount, currency, tolerance,
 * expires_at, status (open, paid), created_at, paid_at.
 */
interface Store
{
    /** The newest open link of an order for this amount and currency, still valid at $validUntil. */
    public function reusableLink(int $orderId, string $amount, string $currency, int $validUntil): ?array;

    public function findLink(string $linkId): ?array;

    public function insertLink(array $row): void;

    public function markPaid(string $linkId, int $now): void;

    public function getValue(string $name): ?string;

    public function setValue(string $name, string $value): void;
}
