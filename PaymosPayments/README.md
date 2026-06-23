# Paymos Crypto Payments for Shopware 6

Accept USDT and USDC on your Shopware 6 store. Customers choose crypto at
checkout, get redirected to the secure Paymos hosted payment page, and the order
transaction is marked **paid** the moment the payment confirms on-chain — driven
by a signed, reverse-verified webhook, never by an unauthenticated browser
return.

## Supported versions

- **Shopware 6.5.5+ and 6.6.** This artifact uses the asynchronous payment
  handler (`AsynchronousPaymentHandlerInterface` + the
  `shopware.payment.method.async` service tag) that is valid across the whole
  6.5 and 6.6 line. The 6.5.5 floor (enforced by `composer.json`) is because the
  unified `PaymentException` factory the handler throws was introduced in
  6.5.4.0; earlier 6.5 patches are long out of support.
- **Shopware 6.7 is not supported by this artifact.** 6.7 replaced the async
  handler with a single `AbstractPaymentHandler` and the `shopware.payment.method`
  tag; a separate 6.7 build is required.
- **PHP 8.2+** (the floor shared by 6.5 and 6.6 — Shopware 6.6 requires 8.2).

## How it works

1. **Checkout** — when the buyer picks "Pay with crypto (Paymos)", the plugin
   creates a Paymos invoice for the order total and redirects to the hosted
   `payment_url`. The invoice amount and currency are snapshotted on the order so
   a later edit cannot change what was actually billed.
2. **Webhook** — Paymos POSTs a signed webhook to `/paymos/webhook`. The plugin
   verifies the HMAC signature, re-pulls the live invoice from the API to confirm
   the terminal status (anti-spoofing), checks the amount against the snapshot,
   then transitions the order transaction (paid / failed / cancelled). Delivery
   is deduplicated by event id, so a retried webhook never double-processes.
   **The webhook is the source of truth for the order state** — never the
   unauthenticated browser return.
3. **Return** — after paying, the buyer comes back to the store via the Paymos
   "Back to store" button (see *Buyer return* below). The plugin replays the
   Shopware return token so Shopware runs the payment handler's `finalize()` and
   shows the order-confirmation page. `finalize()` only reconciles the return
   against the state the webhook already set; it never marks the order paid by
   itself.
4. **Recovery** — if a webhook is ever missed, `bin/console paymos:reconcile`
   re-pulls open invoices and re-applies them through the same verified path.
   Shopware's server already retries delivery, so this is a safety net.

The signing, retry, signature verification, status mapping, amount guarding and
reverse verification all live in the bundled Paymos PHP SDK — the plugin never
reimplements them.

## Installation

1. In the Paymos dashboard, open **Integrations -> Shopware**, pick your project,
   and download the generated plugin ZIP. The ZIP already contains your
   read-only sandbox and live credentials (`paymos-config.php`); you never type a
   secret into Shopware.
2. In Shopware admin, go to **Extensions -> My extensions -> Upload extension**,
   upload the ZIP, then **Install** and **Activate** it.
3. Open the plugin config and choose the **Mode** (Sandbox while testing, Live
   for production). That is the only required setting.
4. Add **Pay with crypto (Paymos)** to the payment methods of each sales channel
   that should offer it (Sales Channels -> your channel -> Payment methods).

## Configuration

The plugin reads its credentials from the dashboard-generated `paymos-config.php`
bundled in the ZIP — it overrides everything and keeps secrets out of the admin
UI. The Shopware admin only exposes:

- **Mode** — `sandbox` or `live`. Switches which credential set is used.
- **Debug logging** — logs routine webhook diagnostics (duplicates, out-of-order
  events) to the Shopware log. Operational errors are always logged.

`paymos-config.example.php` shows the generated file's shape for reference; do not
edit the real file by hand.

## Webhook endpoint

Paymos delivers to `https://<your-store>/paymos/webhook`. The endpoint is
registered automatically by the plugin and configured per project in the Paymos
dashboard — there is nothing to set up in Shopware.

## Buyer return

Shopware's asynchronous checkout expects the buyer to be sent back to the store
after paying, so it can show the order-confirmation page. The Paymos hosted
checkout returns the buyer to your **project-level** success/fail URLs (it does
not take a per-order return URL), so point those at this plugin's return bridge:

- In the Paymos dashboard, set your project **Success URL** to
  `https://<your-store>/paymos/return`
- and your project **Fail/Cancel URL** to
  `https://<your-store>/paymos/return?cancel=1`.

When the buyer clicks **Back to store** on the hosted page, the bridge matches
them to their pending order (via their Shopware session) and hands them back into
Shopware's `finalize()` flow, landing them on the order-confirmation (or, on
cancel, the payment-retry) page.

This return is **best-effort UX, not the source of truth**: the order is marked
paid by the signed webhook regardless of whether the buyer clicks the button. If
the buyer closes the tab without returning, the order still completes when the
webhook arrives; it simply stays "awaiting payment" until then. Because the
project success URL is static, the bridge can only match a logged-in buyer to a
specific order via their Shopware session; a guest (or a buyer whose session has
expired) falls back to the storefront home page rather than a specific order.

## Uninstalling

Uninstalling **deactivates** the payment method rather than deleting it, so
historical orders that used it stay intact. Choose "keep user data" during
uninstall to retain the plugin's snapshot and event tables.

## Support

Docs: https://paymos.io/docs/cms-shopware — Dashboard: https://paymos.io
