<?php

declare(strict_types=1);

namespace PaymosPayments\Service;

/**
 * Pure decision for the Shopware async {@see \PaymosPayments\Service\PaymosPaymentHandler::finalize()}.
 *
 * finalize() runs when the buyer is redirected back into Shopware after the
 * hosted Paymos checkout. The signed, reverse-verified webhook remains the
 * SOURCE OF TRUTH for the real paid/failed/cancelled state; finalize() must
 * never fight it. This maps (cancel flag, current order-transaction technical
 * state) to one of four outcomes the thin handler then acts on:
 *
 *   'complete'   — return without throwing (Shopware routes to the finish page).
 *                  Used when the webhook already marked the transaction paid, or
 *                  when the buyer explicitly cancelled but the payment had in
 *                  fact already completed (don't undo a confirmed payment).
 *   'cancel'     — throw PaymentException::customerCanceled (buyer cancelled, or
 *                  the invoice is already cancelled/expired).
 *   'fail'       — throw PaymentException::asyncFinalizeInterrupted (payment failed).
 *   'leave-open' — return without throwing while the webhook is still in flight.
 *                  Throwing here would route the buyer to the error page and
 *                  cancel a payment the webhook is about to confirm — the single
 *                  most important "don't fight the webhook" rule.
 *
 * Deliberately no scalar type-hints (mirrors the SDK/plugin core so the same
 * code runs on the PHP 7.4 test runner and inside Shopware 8.2+).
 */
final class FinalizeDecision
{
    const COMPLETE = 'complete';
    const CANCEL = 'cancel';
    const FAIL = 'fail';
    const LEAVE_OPEN = 'leave-open';

    /** Shopware technical state names that mean "at or past paid" (matches OrderMapper). */
    private static $paidStates = array('paid', 'refunded', 'refunded_partially');

    /** Shopware technical state names that mean the transaction failed. */
    private static $failedStates = array('failed');

    /** Shopware technical state names that mean the transaction was cancelled. */
    private static $cancelledStates = array('cancelled');

    /**
     * @param bool   $cancel       Whether the buyer returned via the cancel link.
     * @param string $currentState The current order-transaction technical state.
     * @return string One of COMPLETE | CANCEL | FAIL | LEAVE_OPEN.
     */
    public static function decide($cancel, $currentState)
    {
        $cancel = (bool) $cancel;
        $currentState = strtolower(trim((string) $currentState));

        // The webhook already confirmed payment: never downgrade it, even if the
        // buyer hit the cancel link on the way back.
        if (in_array($currentState, self::$paidStates, true)) {
            return self::COMPLETE;
        }

        if ($cancel) {
            return self::CANCEL;
        }

        if (in_array($currentState, self::$failedStates, true)) {
            return self::FAIL;
        }

        if (in_array($currentState, self::$cancelledStates, true)) {
            return self::CANCEL;
        }

        // open / in_progress / unknown ("") — the webhook is still in flight.
        // Leave the transaction as-is; do NOT throw.
        return self::LEAVE_OPEN;
    }
}
