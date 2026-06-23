<?php

declare(strict_types=1);

namespace PaymosPayments\Service;

use Doctrine\DBAL\Connection;

/**
 * DBAL-backed invoice snapshot store. One row per Paymos invoice, keyed for
 * lookup by the Shopware order transaction id (checkout) and by the external
 * order id (webhook). Upsert key is external_order_id.
 */
final class PaymosInvoiceStore implements InvoiceStoreInterface
{
    /** Paymos statuses that are terminal (skip during reconcile). */
    private const TERMINAL_STATUSES = array('paid', 'paid_over', 'underpaid', 'expired', 'cancelled');

    /** @var Connection */
    private $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function findByTransactionId($transactionId)
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM `paymos_payment_invoice` WHERE `transaction_id` = :id ORDER BY `id` DESC LIMIT 1',
            array('id' => (string) $transactionId)
        );

        return is_array($row) ? $row : null;
    }

    public function findByExternalOrderId($externalOrderId)
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM `paymos_payment_invoice` WHERE `external_order_id` = :ext LIMIT 1',
            array('ext' => (string) $externalOrderId)
        );

        return is_array($row) ? $row : null;
    }

    public function save(array $row)
    {
        $now = date('Y-m-d H:i:s');
        $data = array(
            'transaction_id' => (string) $row['transaction_id'],
            'paymos_invoice_id' => (string) $row['paymos_invoice_id'],
            'external_order_id' => (string) $row['external_order_id'],
            'environment' => (string) $row['environment'],
            'project_id' => (string) $row['project_id'],
            'amount' => (string) $row['amount'],
            'currency' => strtoupper((string) $row['currency']),
            'payment_url' => (string) $row['payment_url'],
            'return_url' => isset($row['return_url']) ? (string) $row['return_url'] : '',
            'status' => (string) $row['status'],
            'renew_count' => isset($row['renew_count']) ? (int) $row['renew_count'] : 0,
            'updated_at' => $now,
        );

        $existing = $this->findByExternalOrderId($data['external_order_id']);
        if (is_array($existing)) {
            $this->connection->update(
                'paymos_payment_invoice',
                $data,
                array('external_order_id' => $data['external_order_id'])
            );
            return;
        }

        $data['created_at'] = $now;
        $this->connection->insert('paymos_payment_invoice', $data);
    }

    public function updateStatus($paymosInvoiceId, $status)
    {
        $this->connection->update(
            'paymos_payment_invoice',
            array('status' => (string) $status, 'updated_at' => date('Y-m-d H:i:s')),
            array('paymos_invoice_id' => (string) $paymosInvoiceId)
        );
    }

    public function findUnpaidRecent($limit, $createdBeforeUnix)
    {
        // LIMIT cannot be a bound parameter in MySQL (it would bind as a string
        // and break), so clamp it to a safe integer and inline it.
        $limit = (int) $limit;
        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 200) {
            $limit = 200;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM `paymos_payment_invoice` '
            . 'WHERE `status` NOT IN (:terminal) '
            . 'AND `created_at` <= :cutoff '
            . 'ORDER BY `id` DESC LIMIT ' . $limit,
            array(
                'terminal' => self::TERMINAL_STATUSES,
                'cutoff' => date('Y-m-d H:i:s', (int) $createdBeforeUnix),
            ),
            array('terminal' => Connection::PARAM_STR_ARRAY)
        );

        return is_array($rows) ? $rows : array();
    }
}
