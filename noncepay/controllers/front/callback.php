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

use NoncePay\PrestaShop\Api;
use NoncePay\PrestaShop\Callback;
use NoncePay\PrestaShop\DbStore;
use NoncePay\PrestaShop\PublicKey;

/**
 * NoncePay callback: verifies x-ca-signature and marks the order of a paid link as paid.
 * Called server to server, so it takes no form token; the signature is the authentication.
 */
class NoncepayCallbackModuleFrontController extends ModuleFrontController
{
    /** @var Noncepay */
    public $module;

    public function postProcess()
    {
        $settings = $this->module->settings();
        $store = new DbStore();
        $callback = new Callback(
            $store,
            $this->module->shop(),
            new PublicKey(new Api($settings->apiBase), $store, $settings->publicKey),
            time()
        );

        $signature = isset($_SERVER['HTTP_X_CA_SIGNATURE']) ? (string) $_SERVER['HTTP_X_CA_SIGNATURE'] : '';
        $query = [
            'id_order' => (string) Tools::getValue('id_order'),
            'noncepay_link' => (string) Tools::getValue('noncepay_link'),
            'noncepay_ticker' => (string) Tools::getValue('noncepay_ticker'),
        ];
        list($status, $body) = $callback->handle((string) Tools::file_get_contents('php://input'), $signature, $query);

        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo $body;
        exit;
    }
}
