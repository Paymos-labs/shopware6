<?php

declare(strict_types=1);

namespace PaymosPayments\Service;

/**
 * Persistence for the per-order Paymos invoice snapshot. The snapshot is the
 * amount+currency the invoice was created for; the webhook AmountGuard and the
 * reverse-verifier compare against it, never against a live order total that a
 * later edit might have changed.
 */
interface InvoiceStoreInterface
{
    /**
     * Latest snapshot for a Shopware order transaction id, or null.
     *
     * @param string $transactionId
     * @return array<string, mixed>|null
     */
    public function findByTransactionId($transactionId);

    /**
     * Snapshot whose external order id matches the webhook payload, or null.
     *
     * @param string $externalOrderId
     * @return array<string, mixed>|null
     */
    public function findByExternalOrderId($externalOrderId);

    /**
     * Insert or update a snapshot. Upsert key is external_order_id.
     *
     * @param array<string, mixed> $row
     */
    public function save(array $row);

    /**
     * Record the latest Paymos status against an invoice id (diagnostic only —
     * order state is driven by the StatusMapper action, not this column).
     *
     * @param string $paymosInvoiceId
     * @param string $status
     */
    public function updateStatus($paymosInvoiceId, $status);

    /**
     * Snapshots that are not in a terminal Paymos status and were created before
     * the given cutoff — candidates for reconciliation.
     *
     * @param int $limit
     * @param int $createdBeforeUnix
     * @return array<int, array<string, mixed>>
     */
    public function findUnpaidRecent($limit, $createdBeforeUnix);
}
