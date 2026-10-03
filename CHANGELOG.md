# Changelog

All notable changes to the Paymos for Shopware 6 plugin are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The public release history also lives at [paymos.io/changelog](https://paymos.io/changelog).

## [Unreleased]

## [1.4.17] - 2026-10-03

- fix: устранить дефекты реестра после повторного аудита

### Fixed
- A blocked invoice replacement asked the buyer to pay again (BUG-189). The
  payment was interrupted with Shopware's stock
  `CHECKOUT__ASYNC_PAYMENT_PROCESS_INTERRUPTED`, and the edit-order page answers
  every error code with "change the payment method or try again" above the
  payment-method picker and the "Complete payment" button, while the old invoice
  may already be paid. The handlers (6.5/6.6 and 6.7) now interrupt this case with
  `PAYMOS__ORDER_NEEDS_REVIEW`, and the plugin's override of
  `storefront/page/account/order/index.html.twig` shows the SDK buyer message
  ("The store needs to review this order before payment can continue. Please
  contact the store.") under the heading "This order needs review", with no
  payment-method picker, terms checkboxes or submit button. Every other error
  code, including a failed invoice creation, still gets the stock page. The
  strings are in the new storefront snippet files
  `Resources/snippet/<locale>/paymos.<locale>.json`; de-DE, ru-RU, es-ES, tr-TR
  and zh-CN carry the English text until they are translated.
- A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the transaction amount, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and the payment is interrupted and the reason is logged under
  `manual_review`. A 404 on the read of the live invoice no longer cuts a new
  one either.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- A returning buyer could be sent to an expired invoice. The checkout reused
  the invoice it had already cut for the order whenever the amount and
  currency still matched, but a Paymos invoice lives 30 minutes from creation
  and may have ended unpaid since. It now reads the live invoice before reusing
  it and cuts a new one when the old one expired, was cancelled or ended
  underpaid. A paid invoice is never replaced.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

### Changed
- Install-time translations are filtered against the shop's own language table.
  Writing a translation for a locale the shop has no language for is not
  something a merchant can fix; a shop with only English and German gets exactly
  those two, and gains Russian the moment the language is installed.

## [1.4.16] - 2026-09-29

- chore: bundle Paymos PHP SDK v1.5.0

### Fixed
- A blocked invoice replacement asked the buyer to pay again (BUG-189). The
  payment was interrupted with Shopware's stock
  `CHECKOUT__ASYNC_PAYMENT_PROCESS_INTERRUPTED`, and the edit-order page answers
  every error code with "change the payment method or try again" above the
  payment-method picker and the "Complete payment" button, while the old invoice
  may already be paid. The handlers (6.5/6.6 and 6.7) now interrupt this case with
  `PAYMOS__ORDER_NEEDS_REVIEW`, and the plugin's override of
  `storefront/page/account/order/index.html.twig` shows the SDK buyer message
  ("The store needs to review this order before payment can continue. Please
  contact the store.") under the heading "This order needs review", with no
  payment-method picker, terms checkboxes or submit button. Every other error
  code, including a failed invoice creation, still gets the stock page. The
  strings are in the new storefront snippet files
  `Resources/snippet/<locale>/paymos.<locale>.json`; de-DE, ru-RU, es-ES, tr-TR
  and zh-CN carry the English text until they are translated.
- A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the transaction amount, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and the payment is interrupted and the reason is logged under
  `manual_review`. A 404 on the read of the live invoice no longer cuts a new
  one either.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- A returning buyer could be sent to an expired invoice. The checkout reused
  the invoice it had already cut for the order whenever the amount and
  currency still matched, but a Paymos invoice lives 30 minutes from creation
  and may have ended unpaid since. It now reads the live invoice before reusing
  it and cuts a new one when the old one expired, was cancelled or ended
  underpaid. A paid invoice is never replaced.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

### Changed
- Install-time translations are filtered against the shop's own language table.
  Writing a translation for a locale the shop has no language for is not
  something a merchant can fix; a shop with only English and German gets exactly
  those two, and gains Russian the moment the language is installed.

## [1.4.15] - 2026-09-25

- fix(shopware): BUG-189 заблокированная замена счёта больше не зовёт покупателя платить снова
- fix(plugins): BUG-166 старый счёт закрывается на сервере до выпуска нового; открытый, оплаченный или 404 — в ручную проверку
- chore: bundle Paymos PHP SDK v1.4.3
- chore: rebuild canonical CMS package

### Fixed
- A blocked invoice replacement asked the buyer to pay again (BUG-189). The
  payment was interrupted with Shopware's stock
  `CHECKOUT__ASYNC_PAYMENT_PROCESS_INTERRUPTED`, and the edit-order page answers
  every error code with "change the payment method or try again" above the
  payment-method picker and the "Complete payment" button, while the old invoice
  may already be paid. The handlers (6.5/6.6 and 6.7) now interrupt this case with
  `PAYMOS__ORDER_NEEDS_REVIEW`, and the plugin's override of
  `storefront/page/account/order/index.html.twig` shows the SDK buyer message
  ("The store needs to review this order before payment can continue. Please
  contact the store.") under the heading "This order needs review", with no
  payment-method picker, terms checkboxes or submit button. Every other error
  code, including a failed invoice creation, still gets the stock page. The
  strings are in the new storefront snippet files
  `Resources/snippet/<locale>/paymos.<locale>.json`; de-DE, ru-RU, es-ES, tr-TR
  and zh-CN carry the English text until they are translated.
- A changed order could leave its old invoice payable beside the new one
  (BUG-166). When the transaction amount, the mode or the project changed, the
  checkout cut a new invoice and left the old one open on Paymos, so a buyer
  could pay both. The old invoice is now cancelled first, in its own
  environment, through the SDK's `InvoiceReplacement`; the new one is cut only
  after that cancel succeeds or Paymos reports the old one expired, cancelled
  or underpaid. When the old invoice is paid, still payable (network picked,
  funds confirming, part paid) or cannot be read — a 404 included — no new
  invoice is cut and the payment is interrupted and the reason is logged under
  `manual_review`. A 404 on the read of the live invoice no longer cuts a new
  one either.
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- A returning buyer could be sent to an expired invoice. The checkout reused
  the invoice it had already cut for the order whenever the amount and
  currency still matched, but a Paymos invoice lives 30 minutes from creation
  and may have ended unpaid since. It now reads the live invoice before reusing
  it and cuts a new one when the old one expired, was cancelled or ended
  underpaid. A paid invoice is never replaced.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

### Changed
- Install-time translations are filtered against the shop's own language table.
  Writing a translation for a locale the shop has no language for is not
  something a merchant can fix; a shop with only English and German gets exactly
  those two, and gains Russian the moment the language is installed.

## [1.4.14] - 2026-09-25

- fix(plugins): BUG-163/BUG-164 остальные плагины — замена счёта только по ответу сервера закреплена тестами, комментарии о сроке счёта исправлены
- fix(plugins): BUG-103 вебхук, который ещё обрабатывается, больше не отвечается 200 «duplicate»
- fix(plugins): BUG-090 оплата больше не ведёт на истёкший или проваленный счёт Paymos
- fix(plugins): BUG-135 поздний нефинальный вебхук больше не оживляет проваленный или отменённый заказ
- chore: bundle Paymos PHP SDK v1.4.2

### Fixed
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- A returning buyer could be sent to an expired invoice. The checkout reused
  the invoice it had already cut for the order whenever the amount and
  currency still matched, but a Paymos invoice lives 30 minutes from creation
  and may have ended unpaid since. It now reads the live invoice before reusing
  it and cuts a new one when the old one expired, was cancelled or ended
  underpaid. A paid invoice is never replaced.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.
- An invoice nobody started is replaced only once its deadline is five minutes
  behind the store's clock (`InvoiceRenewal::CLOCK_SKEW_SECONDS` in the bundled
  SDK). The deadline is the server's, and a store clock running ahead could cut
  a second invoice while the buyer could still pick a network on the first.
  Normally the server marks such an invoice expired within seconds, and that
  status decides first.

### Changed
- Install-time translations are filtered against the shop's own language table.
  Writing a translation for a locale the shop has no language for is not
  something a merchant can fix; a shop with only English and German gets exactly
  those two, and gains Russian the moment the language is installed.

## [1.4.13] - 2026-09-25

- chore: rebuild canonical CMS package

### Changed
- Install-time translations are filtered against the shop's own language table.
  Writing a translation for a locale the shop has no language for is not
  something a merchant can fix; a shop with only English and German gets exactly
  those two, and gains Russian the moment the language is installed.

## [1.4.12] - 2026-09-21

- chore: rebuild canonical CMS package

### Changed
- Install-time translations are filtered against the shop's own language table.
  Writing a translation for a locale the shop has no language for is not
  something a merchant can fix; a shop with only English and German gets exactly
  those two, and gains Russian the moment the language is installed.

## [1.4.11] - 2026-09-17

- chore: bundle Paymos PHP SDK v1.4.1

### Changed
- Install-time translations are filtered against the shop's own language table.
  Writing a translation for a locale the shop has no language for is not
  something a merchant can fix; a shop with only English and German gets exactly
  those two, and gains Russian the moment the language is installed.

## [1.4.10] - 2026-08-30

- fix(plugins): CMS marketplace readiness spec, phases 1-3
- chore: rebuild canonical CMS package

### Changed
- Install-time translations are filtered against the shop's own language table.
  Writing a translation for a locale the shop has no language for is not
  something a merchant can fix; a shop with only English and German gets exactly
  those two, and gains Russian the moment the language is installed.

## [1.4.9] - 2026-08-30

- fix(plugins): implicitly nullable factory params break Magento DI compile on PHP 8.5
- chore: rebuild canonical CMS package

### Changed
- Install-time translations are filtered against the shop's own language table.
  Writing a translation for a locale the shop has no language for is not
  something a merchant can fix; a shop with only English and German gets exactly
  those two, and gains Russian the moment the language is installed.

## [1.4.8] - 2026-08-28

- release: the changelog rot had a cause, and it was not the one I named
- audit: the shipped plugin and SDK docs described a product we stopped shipping
- docs(plugins): eight README stubs become the front pages they already were
- docs(plugins): the changelogs stopped in June and the audit never reached them
- chore: bundle Paymos PHP SDK v1.4.0
- chore: rebuild canonical CMS package

### Changed
- Install-time translations are filtered against the shop's own language table.
  Writing a translation for a locale the shop has no language for is not
  something a merchant can fix; a shop with only English and German gets exactly
  those two, and gains Russian the moment the language is installed.

## [1.4.7] - 2026-08-08

- fix(plugins): make the six shipped locales actually reach the merchant

## [1.4.6] - 2026-08-08

- chore: bundle Paymos PHP SDK v1.3.2

## [1.4.5] - 2026-08-07

- chore: rebuild canonical CMS package

## [1.4.4] - 2026-08-07

- chore: rebuild canonical CMS package

## [1.4.3] - 2026-08-07

- fix(shopware): key the handler choice on the interface that disappears

## [1.4.2] - 2026-08-07

- docs(plugins): record the Shopware 6.6 run and widen the advertised range
- chore: rebuild canonical CMS package

## [1.4.1] - 2026-08-07

- fix(shopware): ship the admin bundle in the layout 6.7 actually reads
- chore: rebuild canonical CMS package

## [1.4.0] - 2026-08-07

- feat(shopware): register the handler that matches the running core
- feat(shopware): add the 6.7 payment handler alongside the legacy one
- fix(plugins): open the approval tab in the six remaining CMS plugins

## [1.3.1] - 2026-08-07

- chore: bundle Paymos PHP SDK v1.3.1

## [1.3.0] - 2026-08-06

- feat(locales): Spanish blog and plugin catalogs
- feat(locales): gate locale identity and localize Shopware 6
- chore: bundle Paymos PHP SDK v1.3.0

## [1.2.0] - 2026-08-03

- Merge remote-tracking branch 'origin/main'
- feat: consolidate BotexV2, Rentron, and ecosystem updates
- chore: bundle Paymos PHP SDK v1.3.0
- chore: rebuild canonical CMS package

## [1.1.2] - 2026-08-02

- chore: rebuild canonical CMS package

## [1.1.1] - 2026-08-02

- fix(ecosystem): recover SDK releases
- chore: bundle Paymos PHP SDK v1.2.1
- chore: rebuild canonical CMS package

## [1.1.0] - 2026-07-21

- feat(docs): make the developer surface consumable by LLM agents
- chore: bundle Paymos PHP SDK v1.2.0
- chore: rebuild canonical CMS package

## [1.0.6] - 2026-07-19

- chore: bundle Paymos PHP SDK v1.1.1

## [1.0.5] - 2026-07-13

- chore: rebuild canonical CMS package

## [1.0.4] - 2026-07-12

- fix(plugins): align CMS guidance with secure Connect

## [1.0.3] - 2026-07-12

- chore: rebuild canonical CMS package

## [1.0.2] - 2026-07-12

- chore: rebuild canonical CMS package

## [1.0.1] - 2026-07-12

- fix(release): align package stamping and webhook fixtures
- chore: rebuild canonical CMS package

## [1.0.0] - 2026-06-22

### Added
- Initial release.
- USDT and USDC payments across 13 mainnet networks via the hosted Paymos checkout.
- Payment plugin for Shopware 6.5.7+ and 6.6 (async payment handler).
- Pre-registered webhook endpoint with HMAC-SHA256 (`X-Webhook-Signature`) verification and reverse-verification of terminal events.
- Idempotent webhook processing with event-id dedup and a roll-back guard that protects a paid transaction from a late downgrade.
- Order transaction state transitions driven by the SDK status mapper (no phantom statuses).
- Snippets for admin-facing labels (EN + RU).
- API credentials and signing secret pre-injected by the dashboard ZIP generator (the merchant types nothing).
- Sandbox / Live mode switch in the Shopware admin.
