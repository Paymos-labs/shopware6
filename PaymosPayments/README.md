# Paymos for Shopware 6

Official Paymos stablecoin payment integration for Shopware 6.

## Install and connect

1. Download the latest package from [GitHub Releases](https://github.com/paymos-labs/shopware6/releases/latest).
2. Install and activate it using the standard Shopware 6 extension workflow.
3. Open the intended project in the Paymos dashboard; that current project is used automatically.
4. Open **Settings → Extensions → Paymos** and click **Connect Paymos**.
5. Approve the displayed installation URL and current project in Paymos.

Official packages are identical for every merchant and contain no API keys, API secrets, project IDs, webhook secrets, OAuth tokens, or device codes.

For each environment, Paymos reuses the merchant's single active Payment key or creates one when absent. It reuses a webhook only when the exact callback URL, Invoice category, and current project match; otherwise it creates a dedicated Invoice webhook. OAuth device authorization is only a one-time delivery channel; runtime Merchant API calls remain HMAC signed.

## Secret storage

Credentials and temporary device state are stored as a AES-256-GCM SystemConfigService envelope keyed from kernel.secret. Saved secrets are not rendered back into the administration page.

## Runtime

The integration creates hosted-checkout invoices, verifies signed webhooks, deduplicates events, guards amount and currency, reverse-verifies terminal status, and reconciles missed webhook delivery.

- [Documentation](https://paymos.io/docs/cms-shopware6)
- [Source](https://github.com/paymos-labs/shopware6)
- [Support](mailto:support@paymos.io)