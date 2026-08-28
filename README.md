# Paymos for Shopware 6

Official Paymos payment plugin for Shopware 6. Installing it adds a single payment method —
**Pay with crypto (Paymos)** — that hands the buyer to the Paymos hosted checkout, where they choose
a stablecoin and a network and pay from their own wallet. The order transaction reaches `paid` on a
signed webhook once the transfer confirms on-chain. The buyer's return to the shop never decides it.

## Requirements

Declared in `PaymosPayments/composer.json`:

- `shopware/core`: `~6.5.7 || ~6.6.0 || ~6.7.0` — Shopware 6.5.7 and later 6.5 patches, the whole
  6.6 line, and 6.7
- `php`: `>=8.2`
- A storefront on HTTPS; connecting is refused on a plain-HTTP base URL
- A Paymos account; the dashboard should be sitting on the project you intend to bind

The archive vendors the Paymos PHP SDK inside the plugin, because Shopware does not run Composer for
a plugin's own requirements on a ZIP upload. It also ships the compiled Administration module in
both layouts Shopware looks for — webpack for 6.5 and 6.6, Vite for 6.7 — so there is no admin build
step.

## Install

Download `paymos-shopware6-<version>.zip` from
[Releases](https://github.com/Paymos-labs/shopware6/releases/latest), or from the **CMS integration**
panel in the Paymos dashboard. Its root entry is `PaymosPayments/`, the technical folder name
Shopware expects.

From the Administration: **Extensions → My extensions → Upload extension**, choose the file, then
install and activate it.

From the shell, with the archive extracted into `custom/plugins/`:

```bash
bin/console plugin:refresh
bin/console plugin:install --activate PaymosPayments
bin/console cache:clear
```

Activation creates the payment method and switches it on. Add it to every sales channel that should
offer it, under **Sales Channels → your channel → Payment methods**. The method stays selectable
once the order exists, so a buyer whose payment failed can retry from the order rather than start
over.

Published archives are identical for every merchant and hold no API key, API secret, project id,
webhook secret, OAuth token or device code. Installing one connects nothing.

## Connect

The plugin registers its own Administration module, and connecting lives there rather than on the
extension's configuration card.

1. Select the project the shop should bill through in the Paymos dashboard. Whatever is open there
   is what gets bound; the plugin offers no second choice.
2. In Shopware go to **Settings → Plugins → Paymos** and press **Connect Paymos**.
3. Approve the shop URL and project in the tab that opens. A blocked tab is not a dead end — the
   page then prints the approval link and the user code.
4. Wait for `Paymos connected.`

One approval covers Sandbox and Live. Your single active Payment key is reused, or created when none
exists, and an Invoice webhook is registered at

```text
https://your-store.example/paymos/webhook
```

An existing webhook is reused only when its callback URL, category and project all match; nothing at
that address is silently overwritten.

Credentials are sealed in an AES-256-GCM envelope inside `SystemConfigService`, keyed from the
installation's `kernel.secret`, and are never returned to the browser. There is no field to type a
secret into.

## Per sales channel

The plugin's configuration card is read with the sales channel id, so its two fields are per
channel:

| Field | Default | Effect |
|---|---|---|
| Mode | `sandbox` | Which credential set that channel's checkout uses |
| Debug logging | off | Routine webhook diagnostics into the Shopware log; operational errors are logged either way |

One channel can still be testing in Sandbox while another already takes live payments. The
credentials themselves are installation-wide, so connect once and move each channel with **Mode**
alone. The webhook endpoint belongs to no single channel and reads the global value.

## Order transaction states

| Paymos event | Order transaction |
|---|---|
| Buyer redirected to the hosted checkout | stays `open` |
| `invoice.confirming` | `open`, reopened if it had moved off it |
| `invoice.underpaid_waiting` | `open`; the buyer still owes the remainder |
| `invoice.awaiting_payment` | `open`; a reorg removed a payment that had been counted |
| `invoice.paid`, `invoice.paid_over` | `paid` |
| `invoice.underpaid` | `failed` |
| `invoice.expired`, `invoice.cancelled` | `cancelled` |

Shopware's state machine has no transition between two terminal states, and the plugin never asks
for one. A transaction already `cancelled` is left alone by a later `underpaid`; a `cancelled` or
`failed` transaction that turns out to have been paid is reopened first and then paid. Nothing
downgrades a transaction that has reached `paid`, `refunded` or `refunded_partially`.

When the buyer returns from the hosted checkout, the handler reads the transaction rather than the
URL. If the webhook has not landed yet, the transaction is left as it is — throwing there would send
the buyer to the error page and cancel a payment that is about to confirm. A cancel link pressed
after the payment already completed does not undo it.

## Test before going live

1. Leave **Mode** on Sandbox for the channel you are testing and place an order.
2. Open that invoice in the Paymos dashboard, still in Sandbox, and press **Pay Full**, **Pay 50%**,
   **Pay 150%** or **Cancel**. Each emits the real lifecycle events and moves no funds.
3. Check the order's transaction state, and the Shopware log with debug logging on.
4. Switch that channel's **Mode** to Live. The Live credentials came with the same approval, so
   nothing needs reconnecting.

## Webhooks and recovery

`POST /paymos/webhook` is a storefront route, so deliveries arrive on the storefront domain.
Verification is the `X-Webhook-Signature` header — hex HMAC-SHA256 over `{timestamp}.{body}` — and a
bad signature or a stale timestamp answers `401`. `X-Webhook-Id` is stable across retries, so a
repeat is acknowledged without touching the order. Before a terminal event changes anything, the
invoice is read back from the Merchant API and compared with the stored snapshot.

A delivery cycle runs 11 attempts across roughly 16 hours. For whatever outlives that, the plugin
ships a console command:

```bash
bin/console paymos:reconcile
```

It re-pulls the open invoices and re-applies them through the same guarded path the webhook uses.
Run it from cron every few minutes on a live shop.

## Upgrading Shopware

6.5 and 6.6 use the asynchronous payment handler interface; 6.7 replaced it with
`AbstractPaymentHandler`. One archive carries both and binds whichever API the running core exposes,
but the handler stored on the payment method is written at install time. After moving a shop across
a major version, deactivate and reactivate the plugin: activation rewrites that handler so the
method resolves again. The method is always found by its technical name `paymos_crypto`, which does
not change between versions.

## Troubleshooting

**No Paymos entry under Settings.** The Administration module comes from the plugin's compiled
assets. Run `bin/console assets:install` and `bin/console cache:clear`, then reload the admin with a
hard refresh.

**The method is missing at checkout.** It exists and is active, but Shopware only offers a payment
method a sales channel lists. Add it to that channel's payment methods, and check that no rule
excludes it for the cart you are testing.

**Connect fails on the store URL.** Only `https://` is accepted, and the URL is built from the
request Shopware itself sees. A reverse proxy that terminates TLS has to forward the scheme through
Shopware's trusted-proxy configuration, or Shopware still reports `http://` and the approval is
refused.

## Links

- Documentation: [paymos.io/docs/cms-shopware6](https://paymos.io/docs/cms-shopware6)
- Source and releases: [Paymos-labs/shopware6](https://github.com/Paymos-labs/shopware6)
- Support: [support@paymos.io](mailto:support@paymos.io)
