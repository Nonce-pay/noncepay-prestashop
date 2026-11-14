# Changelog

## 0.1.0

First release.

- "Pay with crypto" payment option at checkout through a NoncePay payment link and its hosted payment page.
- Orders placed in a new "Awaiting NoncePay payment" status; one link per order and total, reused when the customer
  comes back from the confirmation page or their order history.
- Signed callback (`x-ca-signature`, RSA-SHA256) moves the order to "Payment accepted" once, with the link id as
  transaction id.
- Underpayment tolerance (0 to 1%), overpayments recorded as received, option hidden for currencies NoncePay does not
  price.
- English and French.
