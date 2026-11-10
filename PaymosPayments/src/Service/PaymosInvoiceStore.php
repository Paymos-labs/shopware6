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

    private $orderLocks = array();

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function synchronized($transactionId, callable $action)
    {
        $key = (string) $transactionId;
        if (isset($this->orderLocks[$key])) {
            throw new \LogicException('Paymos transaction lock is already held by this store.');
        }
        // Locks and mutations must use the writer on installations with read replicas.
        if (method_exists($this->connection, 'ensureConnectedToPrimary')) {
            $this->connection->ensureConnectedToPrimary();
        }
        // Connection-owned locks have no lease that can expire during an API call.
        $name = hash('sha256', $this->connection->fetchOne('SELECT DATABASE()') . ':paymos_shopware:' . $key);
        $acquired = $this->connection->fetchOne('SELECT GET_LOCK(:name, 0)', array('name' => $name));
        if ((string) $acquired === '0') {
            throw new \Paymos\Exception\EventInProgressException('Paymos transaction is busy.');
        }
        if ((string) $acquired !== '1') {
            throw new \RuntimeException('Paymos transaction lock could not be acquired.');
        }
        $this->orderLocks[$key] = $name;
        try {
            $this->assertLocked($key);
            return $action();
        } finally {
            unset($this->orderLocks[$key]);
            // MySQL releases only locks owned by this connection, even after reconnect.
            $this->connection->fetchOne('SELECT RELEASE_LOCK(:name)', array('name' => $name));
        }
    }

    public function assertLocked($transactionId)
    {
        $name = $this->orderLocks[(string) $transactionId] ?? '';
        if ($name === '' || (string) $this->connection->fetchOne(
            'SELECT IS_USED_LOCK(:name) = CONNECTION_ID()', array('name' => $name)
        ) !== '1') {
            throw new \RuntimeException('Paymos transaction lock ownership was lost.');
        }
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
        // Written only by the callback that set it; other saves leave the marker alone.
        if (array_key_exists('paid_late_noted', $row)) {
            $data['paid_late_noted'] = $row['paid_late_noted'] === null ? null : (string) $row['paid_late_noted'];
        }

        $existing = $this->findByExternalOrderId($data['external_order_id']);
        if (is_array($existing)) {
            $status = $data['status'];
            unset($data['status']);
            $this->connection->update(
                'paymos_payment_invoice',
                $data,
                array('external_order_id' => $data['external_order_id'])
            );
            $this->updateStatus($data['paymos_invoice_id'], $status);
            return;
        }

        $data['created_at'] = $now;
        $this->connection->insert('paymos_payment_invoice', $data);
    }

    public function updateStatus($paymosInvoiceId, $status)
    {
        $this->connection->executeStatement(
            "UPDATE `paymos_payment_invoice` SET `status` = :status, `updated_at` = :now "
            . "WHERE `paymos_invoice_id` = :id AND `status` NOT IN ('paid','paid_over','underpaid','expired','cancelled')",
            array('status' => (string) $status, 'now' => date('Y-m-d H:i:s'), 'id' => (string) $paymosInvoiceId)
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
