<?php

declare(strict_types=1);

namespace PaymosPayments\Service;

/**
 * The thin seam between the framework-agnostic Paymos core (checkout /
 * webhook / reconcile logic) and Shopware itself.
 *
 * Everything the core needs to read from or write to Shopware goes through
 * this interface, so the core can be unit-tested under plain PHP without
 * booting Shopware. The production implementation
 * ({@see \PaymosPayments\Service\ShopwareGateway}) wraps the DBAL connection
 * and the OrderTransactionStateHandler; tests use a fake.
 *
 * Deliberately no scalar type-hints — this mirrors the vendored PHP SDK so the
 * same code runs on the test runner (PHP 7.4) and inside Shopware (PHP 8.2+).
 */
interface ShopwareGatewayInterface
{
    /**
     * Transition a Shopware order transaction to the "paid" state.
     *
     * @param string $transactionId
     */
    public function markPaid($transactionId);

    /**
     * Transition a Shopware order transaction to the "cancelled" state.
     *
     * @param string $transactionId
     */
    public function markCancelled($transactionId);

    /**
     * Transition a Shopware order transaction to the "failed" state.
     *
     * @param string $transactionId
     */
    public function markFailed($transactionId);

    /**
     * Reopen a Shopware order transaction (used when an on-chain reorg pulls a
     * confirmed payment back to awaiting-payment).
     *
     * @param string $transactionId
     */
    public function reopen($transactionId);

    /**
     * The current technical state name of the order transaction
     * (e.g. "open", "in_progress", "paid", "cancelled", "failed"), or "" when
     * unknown. Used by the roll-back guard.
     *
     * @param string $transactionId
     * @return string
     */
    public function transactionState($transactionId);

    /**
     * The id of the most recent NON-terminal Paymos order transaction for the
     * given customer, or "" when none (guest with no customer row, no pending
     * Paymos order, etc.).
     *
     * Used by the storefront return bridge to map a returning buyer (identified
     * only by the Shopware session, since the Paymos project SuccessUrl is
     * static) back to the transaction whose snapshotted return URL should drive
     * Shopware's finalize flow. "Newest wins" — older pending transactions are
     * driven to terminal state by their own webhooks.
     *
     * @param string $customerId             The Shopware customer id, or "" for a guest with no row.
     * @param string $paymentHandlerIdentifier The Paymos payment handler FQCN.
     * @return string The order-transaction id, or "" when none.
     */
    public function findLatestPendingTransactionIdForCustomer($customerId, $paymentHandlerIdentifier);

    /**
     * Append a human-readable note to the plugin log. The plugin gates routine
     * diagnostics on the admin debug toggle; operational failures are logged
     * unconditionally by the caller.
     *
     * @param string $message
     * @param array<string, mixed> $context
     */
    public function log($message, array $context = array());
}
