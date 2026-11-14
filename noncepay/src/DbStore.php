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
 * Store on the PrestaShop database: links in the noncepay_link table, cached values in the global configuration.
 */
final class DbStore implements Store
{
    const TABLE = 'noncepay_link';

    public static function createTableSql(): string
    {
        return 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE . '` (
            `id_noncepay_link` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_order` INT UNSIGNED NOT NULL,
            `link_id` VARCHAR(64) NOT NULL,
            `payment_url` VARCHAR(255) NOT NULL,
            `success_token` VARCHAR(128) NOT NULL,
            `amount` VARCHAR(40) NOT NULL,
            `currency` CHAR(3) NOT NULL,
            `tolerance` VARCHAR(10) NOT NULL,
            `expires_at` INT UNSIGNED NOT NULL,
            `status` VARCHAR(10) NOT NULL DEFAULT \'open\',
            `created_at` INT UNSIGNED NOT NULL,
            `paid_at` INT UNSIGNED NULL,
            PRIMARY KEY (`id_noncepay_link`),
            UNIQUE KEY `link_id` (`link_id`),
            KEY `id_order` (`id_order`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4';
    }

    public function reusableLink(int $orderId, string $amount, string $currency, int $validUntil): ?array
    {
        $row = \Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '`
            WHERE `id_order` = ' . (int) $orderId . "
            AND `amount` = '" . pSQL($amount) . "'
            AND `currency` = '" . pSQL($currency) . "'
            AND `status` = 'open'
            AND `expires_at` > " . (int) $validUntil . '
            ORDER BY `id_noncepay_link` DESC', false);
        return $row ?: null;
    }

    public function findLink(string $linkId): ?array
    {
        $row = \Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . "`
            WHERE `link_id` = '" . pSQL($linkId) . "'", false);
        return $row ?: null;
    }

    public function insertLink(array $row): void
    {
        $data = [];
        foreach ($row as $column => $value) {
            $data[$column] = is_int($value) ? $value : pSQL((string) $value);
        }
        if (!\Db::getInstance()->insert(self::TABLE, $data)) {
            throw new \RuntimeException('Could not store the NoncePay link');
        }
    }

    public function markPaid(string $linkId, int $now): void
    {
        \Db::getInstance()->update(
            self::TABLE,
            ['status' => 'paid', 'paid_at' => (int) $now],
            "`link_id` = '" . pSQL($linkId) . "' AND `status` = 'open'"
        );
    }

    public function getValue(string $name): ?string
    {
        $value = \Configuration::getGlobalValue(self::key($name));
        return $value === false || $value === null ? null : (string) $value;
    }

    public function setValue(string $name, string $value): void
    {
        \Configuration::updateGlobalValue(self::key($name), $value);
    }

    private static function key(string $name): string
    {
        return 'NONCEPAY_CACHE_' . strtoupper($name);
    }
}
