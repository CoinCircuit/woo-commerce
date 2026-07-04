# CoinCircuit for WooCommerce

Accept cryptocurrency payments in your WooCommerce store with CoinCircuit. Shoppers pay on
a secure CoinCircuit checkout page, and the order status updates on its own from signed
CoinCircuit webhooks. No manual reconciliation.

## Requirements

- WordPress 5.8 or newer
- WooCommerce 7.0 or newer (tested up to 9.0), classic checkout and checkout blocks are
  both supported
- PHP 7.4 or newer
- Store currency set to **NGN** or **USD** (the currencies CoinCircuit settles). The
  payment method hides itself automatically for any other currency.
- A public HTTPS site URL. CoinCircuit requires HTTPS for the success, cancel, and webhook
  URLs and does not deliver webhooks to private or localhost hosts, so a local site will
  not receive them. Use a public domain, or a tunnel such as ngrok, when testing.
- A CoinCircuit account with an API key and a webhook signing secret

## How it works

1. A **CoinCircuit** payment method appears at checkout, with the accepted coins shown on
   the option.
2. When the shopper places the order, the plugin creates a CoinCircuit payment session and
   opens the hosted checkout in a secure window layered over the store's own payment page,
   so the shopper pays without leaving the site. If the embedded view cannot load, the
   plugin falls back to a full-page redirect to the same checkout, so payment is always
   possible.
3. As the payment progresses, CoinCircuit sends signed webhooks. The plugin verifies each
   signature and moves the order to the matching status automatically.

## Installation

1. Copy the `coincircuit-woocommerce` folder into `wp-content/plugins/` (or upload a zip
   of it via **Plugins > Add New > Upload Plugin**).
2. Activate **CoinCircuit for WooCommerce** on the Plugins screen.
3. Go to **WooCommerce > Settings > Payments > CoinCircuit** and fill in:
   - **Enable/Disable**: enabled
   - **Environment**: Sandbox while testing, Production when live
   - **API Key**: from your CoinCircuit dashboard, under API settings
   - **Webhook Secret**: from your CoinCircuit dashboard, under Webhook settings
   - Optionally adjust the title, description, and who pays the network fee
4. Add your store's webhook endpoint in the CoinCircuit dashboard:

   ```
   https://your-site.com/wp-json/coincircuit/v1/webhook
   ```

   The plugin also attaches this endpoint to every payment session it creates. Repeat
   deliveries are de-duplicated, so having both does no harm.

## Settings reference

| Setting | Description |
| --- | --- |
| Enable/Disable | Turn the payment method on or off. |
| Title | Method name shown to shoppers at checkout. |
| Description | Short text under the method at checkout. |
| Environment | `Production` (`api.coincircuit.io`) or `Sandbox` (`sandbox-api.coincircuit.io`). |
| API Key | Sent as the `x-api-key` header on API calls. |
| Webhook Secret | Your CoinCircuit webhook signing secret, used to verify incoming webhooks. |
| Network Fee Paid By | Who covers the blockchain network fee: Customer or Merchant. |

## Webhook events and order status

The webhook handler verifies the `X-CoinCircuit-Signature` (an HMAC-SHA256 of
`timestamp.body`) against your Webhook Secret, checks that the timestamp is within five
minutes, matches the order through its stored session records, and de-duplicates repeat
deliveries by hashing the raw body. It handles the `payment.*`, `transaction.*`, and
`refund.*` event families:

| Event | Meaning | Effect on the order |
| --- | --- | --- |
| `payment.completed` | Full payment received. | Marked paid (Processing, or Completed for virtual orders). |
| `payment.partial` | Less than the required amount received, but the session is still open and the customer can pay the remainder. | Order note. The order stays payable, and paying again returns the customer to the same session to top it up. |
| `payment.underpaid` | The session closed with less than the required amount. | On hold, with a note to reopen the payment from the CoinCircuit dashboard or refund the customer. |
| `payment.expired` | The session closed with nothing received. | Failed. The customer can pay again from their account, which starts a fresh session. |
| `payment.failed` | The payment failed. | Failed, with the failure reason. |
| `refund.success` | A refund completed. | Refunded, with the amount and refund ID. |
| `transaction.received`, `transaction.confirmed` | Activity on the blockchain. | Order note only, with the transaction hash and explorer link. |
| Anything else | Not applicable. | Acknowledged and ignored. |

Safeguards applied on every event:

- The plugin keeps a record of every session it created for an order, so a payment made on
  an older session, for example after a shopper placed the order again, still updates the
  order.
- Placing the order again reuses the existing live session when one exists, so a half-paid
  session keeps collecting its funds instead of being replaced.
- A paid order is never downgraded by a late `expired`, `failed`, or `partial` event.
- A cancelled or refunded order never changes status from a webhook. Money arriving on one
  adds a clearly worded order note instead, so you can review and refund.
- A second completed session on an already paid order adds a double-payment note rather
  than passing silently.

## Refunds

Refunds are initiated from your CoinCircuit dashboard, because a crypto refund is sent to
the customer's wallet address and the dashboard flow collects it. When the refund
completes, the `refund.success` webhook moves the WooCommerce order to Refunded and adds a
note with the amount and refund ID. There is no refund button inside the WooCommerce order
screen.

## Rotating your webhook secret

If you rotate the webhook signing secret in your CoinCircuit dashboard, update the
**Webhook Secret** field in the plugin settings immediately. Until the two match, every
webhook delivery is rejected with a signature error, and once CoinCircuit exhausts its
retries those deliveries are not sent again, so order statuses stop updating. Rotate in
this order: generate the new secret, paste it into the plugin settings, save, then confirm
a test event delivers.

## Testing

1. Set Environment to Sandbox and enter your sandbox API key and webhook secret.
2. Make sure the site is reachable over public HTTPS. A tunnel is fine.
3. Place a test order in NGN or USD, complete payment on the CoinCircuit page, and confirm
   the order status updates and the order notes show the CoinCircuit entries.

## File structure

```
coincircuit-for-woocommerce.php            Plugin bootstrap
includes/
  class-coincircuit-api.php                CoinCircuit API client
  class-coincircuit-gateway.php            Payment gateway (sessions, reuse, settings)
  class-coincircuit-webhook.php            Signed webhook handler
  class-coincircuit-blocks-integration.php Checkout blocks integration
assets/
  js/blocks-checkout.js                    Payment method UI for checkout blocks
  js/checkout-embed.js                     Embedded checkout window
  img/                                     CoinCircuit and coin icons
```
