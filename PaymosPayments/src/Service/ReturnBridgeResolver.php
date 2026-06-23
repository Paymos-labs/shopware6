<?php

declare(strict_types=1);

namespace PaymosPayments\Service;

/**
 * Pure redirect-target resolver for the storefront return bridge
 * ({@see \PaymosPayments\Storefront\Controller\PaymosReturnController}).
 *
 * Paymos hosted-checkout cannot bounce the buyer to a per-transaction URL (the
 * create-invoice API has no return-URL field; the buyer-return destination is
 * the static PROJECT-level SuccessUrl/FailUrl). The merchant points that project
 * URL at this plugin's `/paymos/return` route. The controller resolves which
 * pending transaction the returning buyer belongs to (via the Shopware session),
 * fetches that transaction's snapshotted Shopware return URL, and asks this
 * resolver for the final redirect:
 *
 *   - a snapshotted Shopware return URL is known  -> redirect there (Shopware
 *     validates the _sw_payment_token it carries and fires finalize()); append
 *     the cancel flag so finalize() sees it.
 *   - none is known (guest with no session, expired session, no pending
 *     transaction) -> redirect to the supplied fallback (account orders / home).
 *
 * Deliberately no scalar type-hints (mirrors the SDK/plugin core).
 */
final class ReturnBridgeResolver
{
    /**
     * @param string $resolvedReturnUrl The snapshotted Shopware return URL, or '' when unknown.
     * @param bool   $cancel            Whether the buyer returned via the cancel link.
     * @param string $fallbackUrl       Where to send the buyer when no return URL is known.
     * @return string The absolute/relative URL to redirect the buyer to.
     */
    public function resolveTarget($resolvedReturnUrl, $cancel, $fallbackUrl)
    {
        $resolvedReturnUrl = trim((string) $resolvedReturnUrl);
        $fallbackUrl = (string) $fallbackUrl;

        if ($resolvedReturnUrl === '') {
            return $fallbackUrl;
        }

        if (!(bool) $cancel) {
            return $resolvedReturnUrl;
        }

        // Append cancel=1 so finalize() sees the cancellation. Shopware return
        // URLs always carry a query (_sw_payment_token), so the '&' branch is the
        // realistic path; the '?' branch is defensive.
        $separator = strpos($resolvedReturnUrl, '?') === false ? '?' : '&';

        return $resolvedReturnUrl . $separator . 'cancel=1';
    }
}
