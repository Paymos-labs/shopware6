<?php

declare(strict_types=1);

namespace PaymosPayments\Service;

use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\StatusMapper;
use Paymos\Webhook\WebhookEvent;

/**
 * Maps a verified Paymos invoice event onto a Shopware order transaction state
 * transition. The transition is driven ONLY by
 * {@see StatusMapper::invoiceAction()} -> ACTION_* — never by hardcoded status
 * strings — and is protected by a roll-back guard and the SDK AmountGuard.
 *
 * Shopware order-transaction states the mapper drives (via the gateway):
 *   ACTION_PAYMENT_COMPLETE (invoice.paid / paid_over) -> paid
 *   ACTION_CONFIRMING / ACTION_AWAITING_PAYMENT        -> reopen (stays open)
 *   ACTION_FAIL_ORDER (invoice.underpaid)              -> failed
 *   ACTION_CANCEL_ORDER (invoice.expired / cancelled)  -> cancelled
 *   ACTION_MANUAL_REVIEW                               -> hold + log (no paid)
 *   ACTION_IGNORE                                      -> leave unchanged
 */
final class OrderMapper
{
    /** Shopware technical state names that mean "at or past paid". */
    private const PAID_STATES = array('paid', 'refunded', 'refunded_partially');

    /**
     * All terminal order-transaction states. The Shopware state machine has no
     * transition between two terminal states (e.g. failed->cancel, cancelled->fail,
     * cancelled->paid all throw IllegalTransitionException), so a webhook that would
     * move the transaction from one terminal state to a DIFFERENT one must be
     * handled explicitly — never blindly forwarded to the state handler (that 400s
     * and the server retries forever).
     */
    private const TERMINAL_STATES = array('paid', 'refunded', 'refunded_partially', 'cancelled', 'failed');

    /** @var ShopwareGatewayInterface */
    private $gateway;

    public function __construct(ShopwareGatewayInterface $gateway)
    {
        $this->gateway = $gateway;
    }

    /**
     * Apply a verified event to the order transaction described by $row.
     *
     * @param array<string, mixed> $row    The persisted invoice snapshot.
     * @param bool                 $debug  Whether routine diagnostics are logged.
     * @return bool                        True when the order was marked paid.
     */
    public function apply(WebhookEvent $event, array $row, $debug)
    {
        $action = StatusMapper::invoiceAction($event->type(), $event->status());
        if ($action === StatusMapper::ACTION_IGNORE) {
            if ($debug) {
                $this->gateway->log('Paymos ignored a non-actionable invoice event. Invoice: ' . $event->invoiceId());
            }
            return false;
        }

        $transactionId = (string) $row['transaction_id'];
        $currentState = $this->gateway->transactionState($transactionId);

        // Roll-back guard: out-of-order delivery (a stale confirming, a late
        // cancelled/expired/underpaid after paid) must never downgrade an
        // already-paid transaction. Reverse-verify covers forgery, not order.
        if ($this->wouldRollBackPaid($currentState, $action)) {
            if ($debug) {
                $this->gateway->log('Paymos ignored a stale invoice status after payment completed. Invoice: ' . $event->invoiceId());
            }
            return false;
        }

        if ($action === StatusMapper::ACTION_PAYMENT_COMPLETE) {
            if (!$this->amountSafe($event, $row)) {
                // Amount/currency drift on a reverse-verified paid invoice is not a
                // transient failure — do NOT throw (that 400s and the server retries
                // forever). Hold for manual review and acknowledge the webhook.
                $this->gateway->log('Paymos payment needs manual review. ' . AmountGuard::mismatchSummary(
                    $row['amount'],
                    $row['currency'],
                    (string) $row['amount'],
                    (string) $row['currency'],
                    $event->orderAmount(),
                    $event->orderCurrency()
                ));
                return false;
            }
            // Recovery: a transaction that already reached a terminal NON-paid state
            // (cancelled/failed — e.g. expired then paid in a reorg) has no direct
            // transition to paid; reopen it first (cancelled/failed -> open is legal),
            // then pay (open -> paid is legal).
            if (in_array($currentState, self::TERMINAL_STATES, true)) {
                $this->gateway->reopen($transactionId);
            }
            $this->gateway->markPaid($transactionId);
            return true;
        }

        if ($action === StatusMapper::ACTION_FAIL_ORDER) {
            // Never attempt a cross-terminal transition (cancelled -> fail is illegal
            // and would throw -> 400 -> retry loop). The order already reached a
            // terminal state; leave it.
            if ($this->isTerminal($currentState)) {
                return false;
            }
            $this->gateway->markFailed($transactionId);
            return false;
        }

        if ($action === StatusMapper::ACTION_CANCEL_ORDER) {
            // Same: failed -> cancel is illegal. Don't cross terminal states.
            if ($this->isTerminal($currentState)) {
                return false;
            }
            $this->gateway->markCancelled($transactionId);
            return false;
        }

        if ($action === StatusMapper::ACTION_AWAITING_PAYMENT || $action === StatusMapper::ACTION_CONFIRMING) {
            // Confirming and the reorg regression both keep the transaction
            // open/awaiting; reopen only if it had moved off "open" — but never out
            // of a terminal state (would be a downgrade / illegal transition).
            $this->reopenIfNeeded($transactionId, $currentState);
            return false;
        }

        // ACTION_MANUAL_REVIEW and anything unexpected: hold, never mark paid.
        $this->gateway->log('Paymos invoice needs manual review. Invoice: ' . $event->invoiceId() . ', status: ' . $event->status());
        return false;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function amountSafe(WebhookEvent $event, array $row)
    {
        return AmountGuard::isSafeToComplete(
            $row['amount'],
            $row['currency'],
            $row['amount'],
            $row['currency'],
            $event->orderAmount(),
            $event->orderCurrency()
        );
    }

    private function wouldRollBackPaid($currentState, $action)
    {
        if (!in_array($currentState, self::PAID_STATES, true)) {
            return false;
        }

        return in_array($action, array(
            StatusMapper::ACTION_CONFIRMING,
            StatusMapper::ACTION_AWAITING_PAYMENT,
            StatusMapper::ACTION_FAIL_ORDER,
            StatusMapper::ACTION_CANCEL_ORDER,
        ), true);
    }

    private function isTerminal($currentState)
    {
        return in_array($currentState, self::TERMINAL_STATES, true);
    }

    private function reopenIfNeeded($transactionId, $currentState)
    {
        // "open" is already the awaiting state and needs no transition. A `paid`
        // transaction can never reach here for a downgrade action — the roll-back
        // guard returns early for paid + confirming/awaiting. So the only states
        // left to reopen are cancelled/failed (a reorg regression recovering an
        // expired/underpaid invoice back to awaiting — cancelled/failed -> open is
        // a legal Shopware transition) or an advanced in-flight state.
        if ($currentState !== '' && $currentState !== 'open' && $currentState !== 'in_progress') {
            $this->gateway->reopen($transactionId);
        }
    }
}
