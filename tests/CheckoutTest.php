<?php

namespace NoncePay\PrestaShop\Tests;

use NoncePay\PrestaShop\ApiException;
use NoncePay\PrestaShop\Checkout;
use NoncePay\PrestaShop\Settings;
use NoncePay\PrestaShop\Tests\Support\FakeApi;
use NoncePay\PrestaShop\Tests\Support\FakeShop;
use NoncePay\PrestaShop\Tests\Support\MemoryStore;
use PHPUnit\Framework\TestCase;

final class CheckoutTest extends TestCase
{
    const NOW = 1700000000;

    const PAGE = [
        'redirect_url' => 'https://shop.acme.test/order-confirmation?id_cart=7&id_module=80&id_order=42&key=abc',
        'callback_url' => 'https://shop.acme.test/module/noncepay/callback?id_order=42',
        'description' => 'Order XKBKNABJK',
    ];

    /** @var MemoryStore */
    private $store;
    /** @var FakeApi */
    private $fake;
    /** @var FakeShop */
    private $shop;

    protected function setUp(): void
    {
        $this->store = new MemoryStore();
        $this->fake = new FakeApi();
        $this->shop = new FakeShop();
        $this->shop->orders[42] = ['reference' => 'XKBKNABJK', 'total' => '49.900000', 'currency' => 'USD', 'state' => FakeShop::AWAITING];
    }

    private function checkout(array $settings = [], int $now = self::NOW): Checkout
    {
        $params = array_merge([
            'shop_name' => 'Acme <Store>',
            'address_evm' => '0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266',
            'address_tron' => 'TJzXZQhbhJB3dA2TmxmvyPxk2nKsmZ6yWf',
            'coins' => 'trc20/usdt,bep20/usdt',
            'logo_url' => 'https://acme.test/logo.png',
            'tolerance' => '0.5',
        ], $settings);
        return new Checkout(Settings::fromParams($params), $this->store, $this->fake->api(), $this->shop, $now);
    }

    public function testCreatesAPaymentLinkForTheOrder(): void
    {
        $url = $this->checkout()->paymentUrl(42, self::PAGE);

        $this->assertSame('https://noncepay.app/pay/?id=00000000-0000-4000-8000-000000000001', $url);
        $this->assertSame('/link/create/', parse_url($this->fake->calls[0], PHP_URL_PATH));
        $this->assertSame([
            'kind' => 'payment',
            'value' => '49.9',
            'currency' => 'usd',
            'address_evm' => '0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266',
            'address_tron' => 'TJzXZQhbhJB3dA2TmxmvyPxk2nKsmZ6yWf',
            'coins' => 'trc20/usdt,bep20/usdt',
            'callback' => self::PAGE['callback_url'],
            'json' => '1',
            'redirect_url' => self::PAGE['redirect_url'],
            'item_description' => 'Order XKBKNABJK',
            'merchant_name' => 'Acme <Store>',
            'logo_url' => 'https://acme.test/logo.png',
            'expire_at' => (string) (self::NOW + 7 * 86400),
            'tolerance' => '0.5',
        ], $this->fake->query(0));

        $row = $this->store->links[0];
        $this->assertSame(42, $row['id_order']);
        $this->assertSame('token-1', $row['success_token']);
        $this->assertSame(['49.9', 'USD', '0.5'], [$row['amount'], $row['currency'], $row['tolerance']]);
    }

    public function testOmitsAZeroToleranceAndUsesTheMerchantName(): void
    {
        $this->checkout(['tolerance' => '0', 'merchant_name' => 'Acme'])->paymentUrl(42, self::PAGE);
        $query = $this->fake->query(0);
        $this->assertArrayNotHasKey('tolerance', $query);
        $this->assertSame('Acme', $query['merchant_name']);
    }

    public function testReusesTheOpenLinkOnASecondVisit(): void
    {
        $first = $this->checkout()->paymentUrl(42, self::PAGE);
        $second = $this->checkout([], self::NOW + 3600)->paymentUrl(42, self::PAGE);
        $this->assertSame($first, $second);
        $this->assertSame(1, $this->fake->count('/link/create/'));
    }

    public function testCreatesANewLinkWhenTheTotalChanges(): void
    {
        $this->checkout()->paymentUrl(42, self::PAGE);
        $this->shop->orders[42]['total'] = '59.900000';
        $second = $this->checkout()->paymentUrl(42, self::PAGE);
        $this->assertStringEndsWith('000000000002', $second);
        $this->assertSame('59.9', $this->fake->query(1)['value']);
    }

    public function testCreatesANewLinkWhenTheOldOneIsAboutToExpireOrWasDisabled(): void
    {
        $this->checkout()->paymentUrl(42, self::PAGE);
        $this->checkout([], self::NOW + 7 * 86400 - 1800)->paymentUrl(42, self::PAGE);
        $this->assertSame(2, $this->fake->count('/link/create/'));

        $this->fake->responses['/link/0'] = [200, json_encode(['status' => 'success', 'link_status' => 'disabled'])];
        $this->checkout([], self::NOW + 7 * 86400 - 1800)->paymentUrl(42, self::PAGE);
        $this->assertSame(3, $this->fake->count('/link/create/'));
    }

    /** @dataProvider refusals */
    public function testRefusesWithoutCallingTheApi(callable $arrange, string $message): void
    {
        $settings = $arrange($this->shop);
        try {
            $this->checkout($settings)->paymentUrl(42, self::PAGE);
            $this->fail('expected an ApiException');
        } catch (ApiException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
        $this->assertSame([], $this->fake->calls);
        $this->assertSame([], $this->store->links);
    }

    public function refusals(): array
    {
        return [
            'unsupported currency' => [function (FakeShop $shop) {
                $shop->orders[42]['currency'] = 'XAF';
                return [];
            }, 'cannot price an order in XAF'],
            'order no longer awaiting' => [function (FakeShop $shop) {
                $shop->orders[42]['state'] = 'Payment accepted';
                return [];
            }, 'not awaiting'],
            'zero total' => [function (FakeShop $shop) {
                $shop->orders[42]['total'] = '0.000000';
                return [];
            }, 'no amount'],
            'no wallet' => [function () {
                return ['address_evm' => '', 'address_tron' => ''];
            }, 'Enter at least one receiving wallet'],
        ];
    }

    public function testStoresNothingWhenTheApiRefuses(): void
    {
        $this->fake->responses['/link/create/'] = [400, json_encode(['status' => 'error', 'error' => 'You must provide a valid currency'])];
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('You must provide a valid currency');
        try {
            $this->checkout()->paymentUrl(42, self::PAGE);
        } finally {
            $this->assertSame([], $this->store->links);
        }
    }

    public function testRecognisesTheReturnFromAPaidLinkOnlyWithItsToken(): void
    {
        $checkout = $this->checkout();
        $checkout->paymentUrl(42, self::PAGE);
        $linkId = $this->store->links[0]['link_id'];

        $this->assertTrue($checkout->returnedFromPaidLink(42, $linkId, 'token-1'));
        $this->assertFalse($checkout->returnedFromPaidLink(42, $linkId, 'forged'));
        $this->assertFalse($checkout->returnedFromPaidLink(43, $linkId, 'token-1'));
        $this->assertFalse($checkout->returnedFromPaidLink(42, '', ''));
        $this->assertSame([], $this->shop->payments, 'the redirect never records a payment');
    }
}
