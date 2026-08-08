# Changelog

All notable changes to the Paymos for Shopware 6 plugin are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The public release history also lives at [paymos.io/changelog](https://paymos.io/changelog).

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
