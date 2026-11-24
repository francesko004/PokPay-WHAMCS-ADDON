# POK (PokPay) payment gateway for WHMCS

Accept card payments on WHMCS invoices through [POK](https://docs.pokpay.io/) using
POK's **hosted payment page**. Customers click *Pay*, are sent to POK's secure page to
enter their card (with 3-D Secure), and are returned to the invoice. The payment is
applied automatically.

> 🇦🇱 **Shkurtimisht:** Ngarkoni dosjen `modules/` në rrënjën e WHMCS, aktivizoni
> *POK - Pay with card* te **Setup → Payments → Payment Gateways**, vendosni Merchant ID,
> Key ID dhe Key Secret nga paneli POK Business (E-payments → API Keys) dhe testoni fillimisht
> me *Sandbox* të aktivizuar.

## Features

- Hosted checkout, so no card data touches your server (no PCI scope beyond SAQ-A)
- Automatic invoice payment through both the customer redirect **and** the PokPay webhook
- Every payment is **re-verified against the PokPay API** (status, amount, currency and
  invoice reference) before it is applied, so forged callbacks can't mark an invoice paid
- Idempotent: a webhook and a redirect arriving together never double-record a payment
- Reuses a still-valid checkout when the customer clicks *Pay* twice
- ALL and EUR, with optional conversion from any other WHMCS currency
  (*Convert To For Processing*)
- Full refunds, and partial refunds for ALL payments, from the WHMCS admin area
- Staging/sandbox mode, and API calls logged to the WHMCS module log with secrets masked
- No Composer dependencies. Upload it and it works.

## Requirements

- WHMCS 9.x (including 9.11) or 8.x
- PHP 7.4 – 8.3 with `curl` and `json` (tested on PHP 8.3 with strict error reporting, against
  Laravel database components v10 and v11)
- A POK Business merchant account with e-payments enabled (Merchant ID + API key)
- Your WHMCS site reachable over public HTTPS, so PokPay can deliver webhooks

## Installation

1. Copy the `modules` folder into your WHMCS root directory. It merges with the existing one:

   ```
   modules/gateways/pokpay.php
   modules/gateways/pokpay/            (lib/, pay.php)
   modules/gateways/callback/pokpay.php
   ```

   Or build the zip with `./build.sh` (see *Packaging*) and extract it into the WHMCS root.

2. In the WHMCS admin area open **Payment Gateways** (Configuration → System Settings →
   Payment Gateways, or type "Payment Gateways" in the admin search). Under all gateways
   click **POK (PokPay) - Card Payments** to activate it.

3. Fill in the settings:

   | Setting | Value |
   |---|---|
   | **Merchant ID** | From the POK Business dashboard |
   | **Key ID / Key Secret** | POK Business dashboard → *E-payments* → *API Keys* |
   | **Sandbox / Staging** | Tick while testing with **staging** keys, untick for live keys |
   | **Payment link lifetime** | Minutes a checkout stays valid (default 60) |
   | **Order description** | Shown on POK's page. `{company}`, `{invoicenum}`, `{invoiceid}` |
   | **Pay button text** | Default `Paguaj me kartë (POK)` |
   | **Convert To For Processing** | Only if you bill in a currency other than ALL/EUR (e.g. USD): pick **ALL** or **EUR** |
   | **API base URL override** | Leave empty |

4. Save. The module creates its table (`mod_pokpay_orders`) automatically on first use.

You don't need to configure a webhook URL in the POK dashboard, because the module sends it
with every order:
`https://your-whmcs/modules/gateways/callback/pokpay.php?invoiceid=N&action=webhook`.

## Testing (staging)

Tick **Sandbox / Staging** and use your staging keys (staging keys only work against
`api-staging.pokpay.io`, and live keys only against `api.pokpay.io`). Pay an invoice with a POK test card:

| Card | Behaviour |
|---|---|
| `4111 1111 1111 1111` | Visa, no 3DS challenge |
| `4000 0000 0000 1091` | Visa, 3DS challenge |
| `5200 0000 0000 1005` | Mastercard, frictionless 3DS |
| `5200 0000 0000 1096` | Mastercard, 3DS challenge |

Any future expiry and any 3-digit CVV work. See <https://docs.pokpay.io/testing#test-cards>.

Then check:
- the invoice turns **Paid** and the transaction ID is the PokPay order ID
- **Billing → Gateway Log** has a `Success` entry, and **Utilities → Logs → Module Log**
  shows the API calls (enable module logging first)
- a refund from the invoice's *Refund* tab succeeds

When everything checks out, untick Sandbox and enter your live keys.

## How it works

```
Invoice "Pay" ──POST──▶ modules/gateways/pokpay/pay.php
                          │ login (POST /auth/sdk/login)
                          │ create order (POST /merchants/{id}/sdk-orders)
                          ▼
                  POK hosted payment page (sdkOrder._self.confirmUrl)
                          │
       ┌──────────────────┴──────────────────┐
       ▼ redirectUrl / failRedirectUrl        ▼ webhookUrl (server → server)
modules/gateways/callback/pokpay.php  ◀───────┘
   │ GET /merchants/{id}/sdk-orders/{orderId}  (re-verify with PokPay)
   │ isCompleted && amount/currency/reference match?
   ▼
addInvoicePayment(invoice, transid = PokPay order id)
```

The webhook body is treated only as a "something changed" signal. The module checks
only order IDs it created itself (stored in `mod_pokpay_orders`) and trusts only what the
PokPay API returns for them.

## Refunds

- **Full refund** (refund amount = paid amount): works for ALL and EUR payments.
- **Partial refund:** supported for payments charged in **ALL**. PokPay's refund endpoint
  takes the partial amount in the merchant's settlement currency (Lek), so partial refunds
  of EUR payments are refused with a message. Do those from the POK Business dashboard.

## Troubleshooting

| Symptom | Fix |
|---|---|
| "We could not connect to POK" | Check the keys and that *Sandbox* matches the key type. Look in **Gateway Log** for the error |
| Invoice stays Unpaid after paying | Make sure `/modules/gateways/callback/pokpay.php` is publicly reachable (no basic-auth/maintenance mode/firewall blocking PokPay). The customer's return also verifies the payment |
| "POK accepts payments in ALL or EUR only" | Set **Convert To For Processing** to ALL or EUR |
| "Verification Failed" in Gateway Log | PokPay reported a completed payment that doesn't match the invoice amount/currency. Check it manually before applying |

## Development

An end-to-end test suite drives the real module code against a stub WHMCS (Illuminate
Capsule on SQLite) and a mock PokPay API:

```bash
cd tests
composer install
php run-tests.php
```

## Packaging

```bash
./build.sh            # -> dist/pokpay-whmcs-<version>.zip, ready to extract into the WHMCS root
```

## API reference

Built against the PokPay REST API (`https://api.pokpay.io`, staging
`https://api-staging.pokpay.io`) as documented at <https://docs.pokpay.io/rest-api> and
in the official PHP SDK (`rpay/pokpay-payments-sdk`).
