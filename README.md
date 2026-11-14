# NoncePay for PrestaShop

Accept crypto payments in your PrestaShop store: USDT, USDC, Bitcoin, Ethereum, Solana and more, forwarded straight to
your own wallets. NoncePay is non-custodial: there is no account, no API key, no monthly fee and no withdrawal step.

The module adds a **Pay with crypto** payment option to the checkout. The customer places the order, is sent to the
NoncePay hosted payment page, picks a coin, pays, and comes back to the order confirmation page. The order moves to
**Payment accepted** as soon as NoncePay confirms the payment with a signed callback.

## Requirements

- PrestaShop 1.7.8, 8.x or 9.x
- PHP 7.4 to 8.4 with the `curl`, `json` and `openssl` extensions (`bcmath` is used when present, not required)
- MySQL or MariaDB (the module uses `GET_LOCK` so two callbacks never record one payment twice)
- Your store reachable over the internet and not in maintenance mode, so NoncePay can call
  `https://your-store/module/noncepay/callback` (or `index.php?fc=module&module=noncepay&controller=callback` without
  friendly URLs)

## Install

1. Download `noncepay.zip` from the [latest release](https://github.com/Nonce-pay/noncepay-prestashop/releases/latest).
   Do not unzip it.
2. In the Back Office, go to **Modules > Module Manager**, click **Upload a module** and drop the zip.
3. Click **Configure**, enter a receiving wallet for each family of coins you want to accept, then **Save**.

Installing creates the **Awaiting NoncePay payment** order status and the `noncepay_link` table, and enables the
module for every currency. Restrict currencies, countries or carriers in **Payment > Preferences** if needed.

## Settings

| Setting                                                         | Default                    | What it does                                                                                           |
| --------------------------------------------------------------- | -------------------------- | ------------------------------------------------------------------------------------------------------ |
| EVM wallet                                                      | empty                      | One `0x…` address for Ethereum, BNB Smart Chain, Polygon, Base, Arbitrum, Optimism and Avalanche coins |
| Tron, Bitcoin, Litecoin, Bitcoin Cash, Dogecoin, Solana wallets | empty                      | One address per chain family; a family without a wallet is not offered                                 |
| Coins offered                                                   | every coin of your wallets | Optional comma-separated tickers such as `trc20/usdt,bep20/usdt,btc`                                   |
| Merchant name                                                   | your shop name             | Shown on the payment page, 64 characters max                                                           |
| Logo URL                                                        | none                       | An `https` image shown beside your name, on a dark background, at most 40 px tall                      |
| Underpayment tolerance (%)                                      | 0                          | 0 to 1. With 0.5, an order of 100 counts as paid from 99.50, which covers exchange withdrawal fees     |
| API base URL                                                    | `https://api.noncepay.app` | Change only to test against another NoncePay instance                                                  |
| Pinned public key                                               | empty                      | Optional PEM key that signs callbacks; empty fetches it from `/pubkey/` and caches it                  |
| Test mode                                                       | off                        | Allows an `http` API base URL (a local or testnet instance)                                            |

Every wallet accepts the split syntax `0.7@addressA|0.3@addressB`. Wallets are checked when you save and again by
NoncePay when the first link is created. The settings follow the shop context, so each shop of a multistore can use
its own wallets.

## How it works

1. At checkout the customer picks **Pay with crypto** and places the order. It is created in the **Awaiting NoncePay
   payment** status, which sends no email of its own and does not count as paid.
2. The module creates a NoncePay [payment link](https://noncepay.app/docs/guides/payment-links/) for the order total in
   the order currency, valid 7 days, and redirects the customer to its payment page. Coming back (a reload, the
   **Pay with crypto** button on the confirmation page or in the customer's order history) reuses the same link; a
   new one is created only when the order total changes or the link is about to expire.
3. The customer picks a coin and pays. Each payment is forwarded to your wallet as soon as it confirms, minus the
   NoncePay fee (1.25%, lower with volume) and the network cost.
4. Once the confirmed payments cover the order, NoncePay sends a signed callback. The module verifies its
   `x-ca-signature` (RSA-SHA256) against the NoncePay public key, checks the link and the amount against what it
   stored, records the payment with the link id as transaction id and moves the order to **Payment accepted** (which
   sends the usual payment email and creates the invoice). A repeated callback is recorded once.
5. The customer is sent back to the order confirmation page, which says the payment is being confirmed. The order is
   never marked paid from this redirect, only from the signed callback.

Every callback, accepted or not, is in **Advanced Parameters > Logs** (messages starting with `NoncePay:`, attached
to the order when it is known). Per-deposit notifications are logged as "Deposit notification".

## Amounts

- **Underpayment within the tolerance**: the order total is recorded and the order is paid; the log shows what was
  actually received.
- **Underpayment beyond the tolerance**: the payment page asks the customer for the rest; the order stays awaiting
  payment until the link is paid.
- **Overpayment**: the full amount received is recorded as the payment, so the order shows the surplus.
- **Fees**: the NoncePay fee is deducted from the crypto forwarded to your wallet, not charged separately.

## FAQ

**Which currencies can a cart be in?** Those NoncePay prices: USD, EUR, GBP, CAD, AUD, CHF, JPY, BRL, INR and about
thirty others. For a cart in another currency the option is hidden, and the configuration page lists your active
currencies that NoncePay cannot price.

**A customer closed the payment page before paying.** The order stays in **Awaiting NoncePay payment**. The order
confirmation page and the order details in the customer's account show a **Pay with crypto** button that brings back
the same payment page. Cancel the order by hand if it is never paid.

**A customer paid after the link expired.** The payment is still forwarded to your wallet and reported by a
per-deposit callback ("Deposit notification" in the logs), but it does not mark the order paid: add the payment by
hand on the order page.

**The callback is rejected with "Invalid signature".** Check that nothing in front of PrestaShop (a firewall, a
security module) rewrites the request body. If your server cannot reach `api.noncepay.app` to fetch the public key,
paste the key from [`/pubkey/`](https://api.noncepay.app/pubkey/) into **Pinned public key**.

**The order was cancelled or paid another way before the crypto payment arrived.** The payment is not recorded; the
log shows "review manually" with the transaction ids, and the funds are in your wallet.

**My store was in maintenance mode when the customer paid.** NoncePay retries the callback for 3 days; the order is
marked paid on the first retry after maintenance ends.

**Refunds?** NoncePay never holds funds, so there is nothing to refund from: send a refund from your wallet.

**Uninstalling.** The module removes its settings and hides the **Awaiting NoncePay payment** status, but keeps the
status for the orders that use it and keeps the `noncepay_link` table, so reinstalling resumes the orders still
awaiting payment. Drop `ps_noncepay_link` by hand to remove everything.

## Development

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer install
docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit
./bin/build.sh   # dist/noncepay.zip
```

The tests run the link and callback flows against in-memory fakes of PrestaShop and the NoncePay API, with a test RSA
key in `tests/fixtures/`, and check that every translatable string has its French translation in
`noncepay/translations/fr.php`.

## Links

- [NoncePay](https://noncepay.app/) and its [documentation](https://noncepay.app/docs/)
- [Payment links](https://noncepay.app/docs/guides/payment-links/) and [callbacks](https://noncepay.app/docs/callbacks/)

## License

MIT, see [LICENSE](LICENSE).
