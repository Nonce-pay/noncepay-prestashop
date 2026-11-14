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

spl_autoload_register(function ($class) {
    $prefix = 'NoncePay\\PrestaShop\\';
    if (strpos($class, $prefix) === 0) {
        $file = __DIR__ . '/src/' . substr($class, strlen($prefix)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

use NoncePay\PrestaShop\Api;
use NoncePay\PrestaShop\Checkout;
use NoncePay\PrestaShop\DbStore;
use NoncePay\PrestaShop\PrestaShopShop;
use NoncePay\PrestaShop\Settings;
use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

class Noncepay extends PaymentModule
{
    const OS_AWAITING = 'NONCEPAY_OS_AWAITING';

    // Configuration key => setting name.
    const FIELDS = [
        'NONCEPAY_ADDRESS_EVM' => 'address_evm',
        'NONCEPAY_ADDRESS_TRON' => 'address_tron',
        'NONCEPAY_ADDRESS_BTC' => 'address_btc',
        'NONCEPAY_ADDRESS_LTC' => 'address_ltc',
        'NONCEPAY_ADDRESS_BCH' => 'address_bch',
        'NONCEPAY_ADDRESS_DOGE' => 'address_doge',
        'NONCEPAY_ADDRESS_SOL' => 'address_sol',
        'NONCEPAY_COINS' => 'coins',
        'NONCEPAY_MERCHANT_NAME' => 'merchant_name',
        'NONCEPAY_LOGO_URL' => 'logo_url',
        'NONCEPAY_TOLERANCE' => 'tolerance',
        'NONCEPAY_API_BASE' => 'api_base',
        'NONCEPAY_PUBLIC_KEY' => 'public_key',
        'NONCEPAY_TEST_MODE' => 'test_mode',
    ];

    public function __construct()
    {
        $this->name = 'noncepay';
        $this->tab = 'payments_gateways';
        $this->version = '0.1.0';
        $this->author = 'NoncePay';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.7.8.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;
        $this->controllers = ['validation', 'callback'];
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';

        parent::__construct();

        $this->displayName = $this->l('NoncePay (crypto)');
        $this->description = $this->l('Accept USDT, USDC, Bitcoin, Ethereum, Solana and more, forwarded straight to your own wallets. Non-custodial: no account, no API key.');
        $this->confirmUninstall = $this->l('Orders awaiting a crypto payment will no longer be marked paid automatically. Uninstall?');
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('paymentOptions')
            && $this->registerHook('displayPaymentReturn')
            && $this->registerHook('displayOrderDetail')
            && Db::getInstance()->execute(DbStore::createTableSql())
            && $this->installOrderState()
            && Configuration::updateValue('NONCEPAY_API_BASE', Settings::DEFAULT_API)
            && Configuration::updateValue('NONCEPAY_TOLERANCE', '0')
            && Configuration::updateValue('NONCEPAY_TEST_MODE', '0');
    }

    /**
     * Keeps the link table and soft-deletes the order state, so the orders that use it keep their history
     * and a reinstall picks up the orders still awaiting payment.
     */
    public function uninstall()
    {
        $state = new OrderState((int) Configuration::getGlobalValue(self::OS_AWAITING));
        if (Validate::isLoadedObject($state)) {
            $state->deleted = true;
            $state->unremovable = false;
            $state->update();
        }
        foreach (array_keys(self::FIELDS) as $key) {
            Configuration::deleteByName($key);
        }
        Configuration::deleteByName(self::OS_AWAITING);
        Configuration::deleteByName('NONCEPAY_CACHE_PUBKEY');
        Configuration::deleteByName('NONCEPAY_CACHE_PUBKEY_RETRY_AFTER');
        return parent::uninstall();
    }

    private function installOrderState()
    {
        $id = (int) Db::getInstance()->getValue('SELECT `id_order_state` FROM `' . _DB_PREFIX_ . "order_state`
            WHERE `module_name` = '" . pSQL($this->name) . "' ORDER BY `id_order_state` DESC");
        $state = new OrderState($id ?: null);
        $state->name = [];
        foreach (Language::getLanguages(false) as $language) {
            $state->name[(int) $language['id_lang']] = $language['iso_code'] === 'fr'
                ? 'En attente de paiement NoncePay'
                : 'Awaiting NoncePay payment';
        }
        $state->module_name = $this->name;
        $state->color = '#4169E1';
        $state->send_email = false;
        $state->logable = false;
        $state->invoice = false;
        $state->paid = false;
        $state->shipped = false;
        $state->delivery = false;
        $state->hidden = false;
        $state->unremovable = true;
        $state->deleted = false;
        if (!($id ? $state->update() : $state->add())) {
            return false;
        }
        return Configuration::updateGlobalValue(self::OS_AWAITING, (int) $state->id);
    }

    public function awaitingState()
    {
        return (int) Configuration::getGlobalValue(self::OS_AWAITING);
    }

    public function settings()
    {
        $params = ['shop_name' => (string) Configuration::get('PS_SHOP_NAME')];
        foreach (self::FIELDS as $key => $name) {
            $params[$name] = (string) Configuration::get($key);
        }
        return Settings::fromParams($params, [$this, 'translateSetting']);
    }

    public function translateSetting($text)
    {
        return html_entity_decode($this->l($text, 'settings'), ENT_QUOTES, 'UTF-8');
    }

    public function shop()
    {
        return new PrestaShopShop($this->awaitingState(), $this->displayName);
    }

    /** Whether the cart can be paid with NoncePay: an allowed currency NoncePay prices, and a usable configuration. */
    public function isAvailableFor(Cart $cart)
    {
        if (!$this->active || !$this->awaitingState()) {
            return false;
        }
        $currency = new Currency((int) $cart->id_currency);
        $allowed = false;
        foreach ((array) $this->getCurrency((int) $cart->id_currency) as $moduleCurrency) {
            if ((int) $moduleCurrency['id_currency'] === (int) $currency->id) {
                $allowed = true;
            }
        }
        return $allowed && Settings::currencySupported((string) $currency->iso_code) && !$this->settings()->errors;
    }

    /**
     * The NoncePay payment page of an order awaiting payment.
     *
     * @throws NoncePay\PrestaShop\ApiException
     */
    public function paymentUrl(Order $order)
    {
        $settings = $this->settings();
        $shop = $this->shop();
        $logger = function ($action, $url, $response) use ($shop, $order) {
            if (is_array($response) && isset($response['success_token'])) {
                $response['success_token'] = '(hidden)';
            }
            $shop->log(['request' => $action, 'response' => is_array($response) ? json_encode($response) : substr((string) $response, 0, 500)], 'API call', (int) $order->id);
        };
        $checkout = new Checkout($settings, new DbStore(), new Api($settings->apiBase, null, $logger), $shop, time());
        return $checkout->paymentUrl((int) $order->id, [
            'redirect_url' => $this->confirmationUrl($order),
            'callback_url' => $this->context->link->getModuleLink($this->name, 'callback', ['id_order' => (int) $order->id], true, null, (int) $order->id_shop),
            'description' => html_entity_decode(sprintf($this->l('Order %s'), $order->reference), ENT_QUOTES, 'UTF-8'),
        ]);
    }

    public function confirmationUrl(Order $order)
    {
        return $this->context->link->getPageLink('order-confirmation', true, (int) $order->id_lang, [
            'id_cart' => (int) $order->id_cart,
            'id_module' => (int) $this->id,
            'id_order' => (int) $order->id,
            'key' => $order->secure_key,
        ], false, (int) $order->id_shop);
    }

    /** Sends the customer to the payment page of an order placed earlier (from the confirmation page or their account). */
    public function payUrl(Order $order, array $extra = [])
    {
        return $this->context->link->getModuleLink($this->name, 'validation', array_merge([
            'id_order' => (int) $order->id,
            'key' => $order->secure_key,
        ], $extra), true);
    }

    public function hookPaymentOptions($params)
    {
        if (empty($params['cart']) || !$this->isAvailableFor($params['cart'])) {
            return [];
        }
        $option = new PaymentOption();
        $option->setModuleName($this->name)
            ->setCallToActionText($this->l('Pay with crypto'))
            ->setAction($this->context->link->getModuleLink($this->name, 'validation', [], true))
            ->setAdditionalInformation($this->fetch('module:noncepay/views/templates/hook/payment_option.tpl'));
        return [$option];
    }

    public function hookDisplayPaymentReturn($params)
    {
        $order = isset($params['order']) ? $params['order'] : null;
        if (!$order instanceof Order || $order->module !== $this->name) {
            return '';
        }
        $status = $this->orderStatus($order);
        if ($status === 'awaiting') {
            $settings = $this->settings();
            $checkout = new Checkout($settings, new DbStore(), new Api($settings->apiBase), $this->shop(), time());
            if ($checkout->returnedFromPaidLink((int) $order->id, (string) Tools::getValue('link_id'), (string) Tools::getValue('success_token'))) {
                $status = 'confirming';
            }
        }
        return $this->renderStatus($order, $status);
    }

    public function hookDisplayOrderDetail($params)
    {
        $order = isset($params['order']) ? $params['order'] : null;
        if (!$order instanceof Order || $order->module !== $this->name || $this->orderStatus($order) !== 'awaiting') {
            return '';
        }
        return $this->renderStatus($order, 'awaiting');
    }

    private function orderStatus(Order $order)
    {
        if ((int) $order->current_state === $this->awaitingState()) {
            return 'awaiting';
        }
        return $order->hasBeenPaid() ? 'paid' : 'other';
    }

    private function renderStatus(Order $order, $status)
    {
        $this->context->smarty->assign([
            'noncepay_status' => $status,
            'noncepay_reference' => $order->reference,
            'noncepay_total' => $this->context->getCurrentLocale()->formatPrice(
                $order->total_paid,
                (new Currency((int) $order->id_currency))->iso_code
            ),
            'noncepay_pay_url' => $this->payUrl($order),
        ]);
        return $this->fetch('module:noncepay/views/templates/hook/payment_status.tpl');
    }

    public function getContent()
    {
        $output = '';
        $values = [];
        foreach (array_keys(self::FIELDS) as $key) {
            $values[$key] = (string) Configuration::get($key);
        }

        if (Tools::isSubmit('submitNoncepay')) {
            $params = ['shop_name' => (string) Configuration::get('PS_SHOP_NAME')];
            foreach (self::FIELDS as $key => $name) {
                $values[$key] = trim((string) Tools::getValue($key));
                $params[$name] = $values[$key];
            }
            $settings = Settings::fromParams($params, [$this, 'translateSetting']);
            if ($settings->errors) {
                $output .= $this->displayError(array_map([$this, 'escape'], $settings->errors));
            } else {
                foreach ($values as $key => $value) {
                    Configuration::updateValue($key, $value);
                }
                $output .= $this->displayConfirmation($this->l('Settings updated.'));
            }
        }

        $unsupported = [];
        foreach (Currency::getCurrencies(true, true) as $currency) {
            if (!Settings::currencySupported((string) $currency->iso_code)) {
                $unsupported[] = $currency->iso_code;
            }
        }
        if ($unsupported) {
            $output .= $this->displayWarning($this->escape(sprintf(
                $this->l('NoncePay cannot price orders in %s: the crypto option is hidden for carts in these currencies.'),
                implode(', ', $unsupported)
            )));
        }
        $output .= $this->displayInformation($this->escape(
            $this->l('NoncePay calls your shop when a payment is confirmed. Your shop must be reachable from the internet and not in maintenance mode.')
        ));

        return $output . $this->renderForm($values);
    }

    private function renderForm(array $values)
    {
        $wallet = function ($key, $label, $example) {
            return [
                'type' => 'text',
                'name' => $key,
                'label' => $label,
                'desc' => sprintf($this->l('Leave empty to not offer these coins. Example: %s'), $example),
            ];
        };
        $form = [
            'form' => [
                'legend' => ['title' => $this->l('Settings'), 'icon' => 'icon-cogs'],
                'description' => $this->l('Payments are forwarded to these wallets as soon as they confirm, minus the NoncePay fee and the network cost. Every wallet accepts the split syntax 0.7@addressA|0.3@addressB.'),
                'input' => [
                    $wallet('NONCEPAY_ADDRESS_EVM', $this->l('EVM wallet'), '0x… (ETH, USDT/USDC on Ethereum, BNB Smart Chain, Polygon, Base, Arbitrum…)'),
                    $wallet('NONCEPAY_ADDRESS_TRON', $this->l('Tron wallet'), 'T… (TRX, USDT TRC-20)'),
                    $wallet('NONCEPAY_ADDRESS_BTC', $this->l('Bitcoin wallet'), 'bc1…'),
                    $wallet('NONCEPAY_ADDRESS_LTC', $this->l('Litecoin wallet'), 'ltc1…'),
                    $wallet('NONCEPAY_ADDRESS_BCH', $this->l('Bitcoin Cash wallet'), 'bitcoincash:q…'),
                    $wallet('NONCEPAY_ADDRESS_DOGE', $this->l('Dogecoin wallet'), 'D…'),
                    $wallet('NONCEPAY_ADDRESS_SOL', $this->l('Solana wallet'), $this->l('a base58 address (SOL, USDC/USDT on Solana)')),
                    [
                        'type' => 'text',
                        'name' => 'NONCEPAY_COINS',
                        'label' => $this->l('Coins offered'),
                        'desc' => $this->l('Optional comma-separated tickers, e.g. trc20/usdt,bep20/usdt,btc. Empty offers every coin of the wallets above.'),
                    ],
                    [
                        'type' => 'text',
                        'name' => 'NONCEPAY_MERCHANT_NAME',
                        'label' => $this->l('Merchant name'),
                        'desc' => $this->l('Shown on the payment page (64 characters max). Defaults to your shop name.'),
                    ],
                    [
                        'type' => 'text',
                        'name' => 'NONCEPAY_LOGO_URL',
                        'label' => $this->l('Logo URL'),
                        'desc' => $this->l('Optional https image shown beside your name on a dark background, at most 40 px tall.'),
                    ],
                    [
                        'type' => 'text',
                        'name' => 'NONCEPAY_TOLERANCE',
                        'label' => $this->l('Underpayment tolerance'),
                        'suffix' => '%',
                        'class' => 'fixed-width-sm',
                        'desc' => $this->l('From 0 to 1. With 0.5, an order of 100 counts as paid from 99.50 (covers exchange withdrawal fees).'),
                    ],
                    [
                        'type' => 'text',
                        'name' => 'NONCEPAY_API_BASE',
                        'label' => $this->l('API base URL'),
                        'desc' => $this->l('Leave as is unless testing against another NoncePay instance.'),
                    ],
                    [
                        'type' => 'textarea',
                        'name' => 'NONCEPAY_PUBLIC_KEY',
                        'label' => $this->l('Pinned public key'),
                        'rows' => 4,
                        'desc' => $this->l('Optional. The PEM key from /pubkey/ that signs callbacks. Empty fetches and caches it automatically.'),
                    ],
                    [
                        'type' => 'switch',
                        'name' => 'NONCEPAY_TEST_MODE',
                        'label' => $this->l('Test mode'),
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'test_mode_on', 'value' => 1, 'label' => $this->l('Yes')],
                            ['id' => 'test_mode_off', 'value' => 0, 'label' => $this->l('No')],
                        ],
                        'desc' => $this->l('Allows an http API base URL (a local or testnet instance). Keep off in production.'),
                    ],
                ],
                'submit' => ['title' => $this->l('Save')],
            ],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->submit_action = 'submitNoncepay';
        $helper->show_toolbar = false;
        $helper->tpl_vars = ['fields_value' => $values];
        return $helper->generateForm([$form]);
    }

    public function escape($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}
