<?php

declare(strict_types=1);

namespace PaymosPayments\Service;

use Paymos\Plugin\StatusMapper;

/**
 * In-memory snapshot store for unit tests and for the reconcile path's
 * throwaway event store.
 */
final class InMemoryInvoiceStore implements InvoiceStoreInterface
{
    /** @var array<int, array<string, mixed>> */
    private $rows = array();

    public function findByTransactionId($transactionId)
    {
        $match = null;
        foreach ($this->rows as $row) {
            if ((string) $row['transaction_id'] === (string) $transactionId) {
                $match = $row;
            }
        }

        return $match;
    }

    public function findByExternalOrderId($externalOrderId)
    {
        foreach ($this->rows as $row) {
            if ((string) $row['external_order_id'] === (string) $externalOrderId) {
                return $row;
            }
        }

        return null;
    }

    public function save(array $row)
    {
        foreach ($this->rows as $index => $existing) {
            if ((string) $existing['external_order_id'] === (string) $row['external_order_id']) {
                $this->rows[$index] = $row;
                return;
            }
        }

        $this->rows[] = $row;
    }

    public function updateStatus($paymosInvoiceId, $status)
    {
        foreach ($this->rows as $index => $row) {
            if ((string) $row['paymos_invoice_id'] === (string) $paymosInvoiceId) {
                $this->rows[$index]['status'] = (string) $status;
                return;
            }
        }
    }

    public function findUnpaidRecent($limit, $createdBeforeUnix)
    {
        $matches = array();
        foreach ($this->rows as $row) {
            $action = StatusMapper::invoiceAction('', isset($row['status']) ? (string) $row['status'] : '');
            $terminal = in_array($action, array(
                StatusMapper::ACTION_PAYMENT_COMPLETE,
                StatusMapper::ACTION_FAIL_ORDER,
                StatusMapper::ACTION_CANCEL_ORDER,
            ), true);
            if ($terminal) {
                continue;
            }

            // Honour the grace-window cutoff exactly as the production store
            // (`created_at <= :cutoff`): a snapshot still inside the grace
            // window is skipped, since its webhook is still expected. A row
            // without a parseable created_at is treated as eligible.
            if (isset($row['created_at']) && (string) $row['created_at'] !== '') {
                $createdAt = strtotime((string) $row['created_at']);
                if ($createdAt !== false && $createdAt > (int) $createdBeforeUnix) {
                    continue;
                }
            }

            $matches[] = $row;
            if (count($matches) >= (int) $limit) {
                break;
            }
        }

        return $matches;
    }
}
