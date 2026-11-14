<?php

namespace NoncePay\PrestaShop\Tests;

use NoncePay\PrestaShop\Api;
use NoncePay\PrestaShop\Callback;
use NoncePay\PrestaShop\PublicKey;
use NoncePay\PrestaShop\Tests\Support\FakeShop;
use NoncePay\PrestaShop\Tests\Support\MemoryStore;
use PHPUnit\Framework\TestCase;

final class CallbackTest extends TestCase
{
    const LINK = '3f6c2a1e-8b4d-4c7a-9e21-5d0f7b9a6c13';

    /** @var MemoryStore */
    private $store;
    /** @var FakeShop */
    private $shop;

    protected function setUp(): void
    {
        $this->store = new MemoryStore();
        $this->store->insertLink($this->row());
        $this->shop = new FakeShop();
        $this->shop->orders[42] = ['reference' => 'XKBKNABJK', 'total' => '49.900000', 'currency' => 'USD', 'state' => FakeShop::AWAITING];
    }

    private function row(array $overrides = []): array
    {
        return array_merge([
            'id_order' => 42,
            'link_id' => self::LINK,
            'payment_url' => 'https://noncepay.app/pay/?id=' . self::LINK,
            'success_token' => 'secret',
            'amount' => '49.9',
            'currency' => 'USD',
            'tolerance' => '0.5',
            'expires_at' => 1800000000,
            'status' => 'open',
            'created_at' => 1700000000,
        ], $overrides);
    }

    private function paid(array $overrides = []): string
    {
        return json_encode(array_merge([
            'type' => 'payment',
            'link_id' => self::LINK,
            'value' => '49.90',
            'currency' => 'USD',
            'paid_amount_fiat' => '49.90',
            'paid_coin' => 'trc20_usdt',
            'paid_amount' => '49.9',
            'exchange_rate' => '1',
            'txids' => 'abc123',
            'address_in' => 'TXyz',
            'is_paid' => '1',
        ], $overrides));
    }

    private function handle(string $body, ?string $signature = null, array $query = ['id_order' => '42']): array
    {
        $api = new Api('https://api.test', function () {
            return [200, json_encode(['status' => 'success', 'pubkey' => PublicKeyTest::pem()])];
        });
        $callback = new Callback($this->store, $this->shop, new PublicKey($api, $this->store, ''), 1700000100);
        return $callback->handle($body, $signature ?? PublicKeyTest::sign($body), $query);
    }

    public function testMarksTheOrderOfAPaidLinkPaid(): void
    {
        $this->assertSame([200, '*ok*'], $this->handle($this->paid()));
        $this->assertSame([['id_order' => 42, 'transaction_id' => self::LINK, 'amount' => '49.9']], $this->shop->payments);
        $this->assertSame('Payment accepted', $this->shop->orders[42]['state']);
        $this->assertSame('paid', $this->store->links[0]['status']);
        $this->assertSame('Success', $this->shop->lastStatus());
    }

    public function testRejectsABadSignatureSoNoncePayRetries(): void
    {
        $this->assertSame([401, 'Bad signature'], $this->handle($this->paid(), PublicKeyTest::sign('something else')));
        $this->assertSame([401, 'Bad signature'], $this->handle($this->paid(), ''));
        $this->assertSame([], $this->shop->payments);
        $this->assertSame('Invalid signature', $this->shop->lastStatus());
    }

    public function testRecordsADuplicateCallbackOnce(): void
    {
        $this->handle($this->paid());
        $this->assertSame([200, '*ok*'], $this->handle($this->paid()));
        $this->assertCount(1, $this->shop->payments);
        $this->assertSame('Duplicate, already recorded', $this->shop->lastStatus());
    }

    public function testARetryCompletesAPaymentRecordedBeforeTheStateChangeFailed(): void
    {
        $this->shop->failStateChange = true;
        $this->assertSame(503, $this->handle($this->paid())[0]);
        $this->assertSame(FakeShop::AWAITING, $this->shop->orders[42]['state']);
        $this->assertSame('open', $this->store->links[0]['status']);

        $this->shop->failStateChange = false;
        $this->assertSame([200, '*ok*'], $this->handle($this->paid()));
        $this->assertCount(1, $this->shop->payments);
        $this->assertSame('Payment accepted', $this->shop->orders[42]['state']);
    }

    public function testTheOrderComesFromTheStoredLinkNotTheUnsignedUrl(): void
    {
        $this->shop->orders[99] = ['reference' => 'OTHER', 'total' => '500', 'currency' => 'USD', 'state' => FakeShop::AWAITING];
        $this->handle($this->paid(), null, ['id_order' => '99']);
        $this->assertSame(42, $this->shop->payments[0]['id_order']);
        $this->assertSame(FakeShop::AWAITING, $this->shop->orders[99]['state']);
    }

    public function testAcknowledgesDepositCallbacksWithoutRecordingAnything(): void
    {
        $body = json_encode(['uuid' => 'd1', 'coin' => 'trc20_usdt', 'value_coin' => '10', 'pending' => '0', 'txid_in' => 'abc']);
        $this->assertSame([200, '*ok*'], $this->handle($body, null, ['id_order' => '42', 'noncepay_link' => self::LINK]));
        $this->assertSame([], $this->shop->payments);
        $this->assertSame('Deposit notification', $this->shop->lastStatus());
        $this->assertSame(42, $this->shop->logs[0]['id_order']);
    }

    public function testIgnoresAnUnknownLinkOrAnUnpaidOne(): void
    {
        $this->assertSame([200, '*ok*'], $this->handle($this->paid(['link_id' => 'ffffffff-0000-4000-8000-000000000000'])));
        $this->assertSame([200, '*ok*'], $this->handle($this->paid(['is_paid' => '0'])));
        $this->assertSame([], $this->shop->payments);
        $this->assertSame('Unknown link, ignored', $this->shop->lastStatus());
    }

    public function testRaisesAShortfallWithinToleranceToTheOrderAmount(): void
    {
        $this->handle($this->paid(['paid_amount_fiat' => '49.66']));
        $this->assertSame('49.9', $this->shop->payments[0]['amount']);
        $this->assertSame('Payment accepted', $this->shop->orders[42]['state']);
    }

    public function testLeavesAnUnderpaymentBeyondTheTolerance(): void
    {
        $this->assertSame([200, '*ok*'], $this->handle($this->paid(['paid_amount_fiat' => '49.65'])));
        $this->assertSame([], $this->shop->payments);
        $this->assertSame(FakeShop::AWAITING, $this->shop->orders[42]['state']);
        $this->assertSame('Amount mismatch, review manually', $this->shop->lastStatus());
    }

    public function testRecordsNothingWhenTheSignedAmountIsNotTheStoredLink(): void
    {
        $this->handle($this->paid(['value' => '4.99', 'paid_amount_fiat' => '4.99']));
        $this->handle($this->paid(['currency' => 'EUR']));
        $this->handle($this->paid(['paid_amount_fiat' => '-60']));
        $this->assertSame([], $this->shop->payments);
    }

    public function testRecordsAnOverpaymentAsIs(): void
    {
        $this->handle($this->paid(['paid_amount_fiat' => '60.12']));
        $this->assertSame('60.12', $this->shop->payments[0]['amount']);
    }

    public function testLeavesAnOrderNoLongerAwaitingPaymentForReview(): void
    {
        $this->shop->orders[42]['state'] = 'Canceled';
        $this->assertSame([200, '*ok*'], $this->handle($this->paid()));
        $this->assertSame([], $this->shop->payments);
        $this->assertSame('Paid link on an order in state "Canceled", review manually', $this->shop->lastStatus());
        $this->assertSame('paid', $this->store->links[0]['status']);
    }

    public function testAsksForARetryWhenBusyOrWhenPrestaShopRefuses(): void
    {
        $this->shop->lockFree = false;
        $this->assertSame(503, $this->handle($this->paid())[0]);
        $this->shop->lockFree = true;
        $this->shop->refusePayment = true;
        $this->assertSame(503, $this->handle($this->paid())[0]);
        $this->assertSame('open', $this->store->links[0]['status']);
        $this->shop->refusePayment = false;
        $this->assertSame([200, '*ok*'], $this->handle($this->paid()));
        $this->assertCount(1, $this->shop->payments);
    }

    public function testNeverLogsTheSuccessToken(): void
    {
        $this->handle($this->paid());
        $this->handle($this->paid(['link_id' => 'nope']));
        $this->assertStringNotContainsString('secret', json_encode($this->shop->logs));
    }
}
