<?php

declare(strict_types=1);

namespace PaymosPayments\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\Context;

/**
 * Production {@see ShopwareGatewayInterface}: drives the Shopware order
 * transaction state machine and reads the current technical state via DBAL.
 *
 * All transitions go through {@see OrderTransactionStateHandler}, the supported
 * Shopware API for order-transaction state changes (paid/cancel/fail/reopen,
 * each `(string $transactionId, Context $context): void`).
 */
final class ShopwareGateway implements ShopwareGatewayInterface
{
    /** @var OrderTransactionStateHandler */
    private $stateHandler;

    /** @var Connection */
    private $connection;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        OrderTransactionStateHandler $stateHandler,
        Connection $connection,
        LoggerInterface $logger
    ) {
        $this->stateHandler = $stateHandler;
        $this->connection = $connection;
        $this->logger = $logger;
    }

    public function markPaid($transactionId)
    {
        $this->stateHandler->paid((string) $transactionId, Context::createDefaultContext());
    }

    public function markCancelled($transactionId)
    {
        $this->stateHandler->cancel((string) $transactionId, Context::createDefaultContext());
    }

    public function markFailed($transactionId)
    {
        $this->stateHandler->fail((string) $transactionId, Context::createDefaultContext());
    }

    public function reopen($transactionId)
    {
        $this->stateHandler->reopen((string) $transactionId, Context::createDefaultContext());
    }

    public function transactionState($transactionId)
    {
        $state = $this->connection->fetchOne(
            'SELECT LOWER(`state`.`technical_name`) '
            . 'FROM `order_transaction` AS `txn` '
            . 'INNER JOIN `state_machine_state` AS `state` ON `state`.`id` = `txn`.`state_id` '
            . 'WHERE `txn`.`id` = UNHEX(:id) LIMIT 1',
            array('id' => (string) $transactionId)
        );

        return is_string($state) ? $state : '';
    }

    /**
     * Everything the checkout needs about an order, addressed by its transaction.
     *
     * Shopware 6.7 hands the payment handler only an order-transaction id — the
     * order and the sales-channel context that older versions passed in are gone,
     * so the plugin has to load them itself. Order versioning: every join carries
     * version_id so the live row is matched rather than a draft.
     *
     * @param string $transactionId
     * @return array<string, string>|null null when the transaction is unknown
     */
    public function orderContext($transactionId)
    {
        $row = $this->connection->fetchAssociative(
            'SELECT `o`.`order_number` AS `order_number`, '
            . '  `txn`.`amount` AS `amount`, '
            . '  `cur`.`iso_code` AS `currency`, '
            . '  LOWER(HEX(`oc`.`customer_id`)) AS `customer_id`, '
            . '  LOWER(HEX(`o`.`sales_channel_id`)) AS `sales_channel_id` '
            . 'FROM `order_transaction` AS `txn` '
            . 'INNER JOIN `order` AS `o` '
            . '  ON `o`.`id` = `txn`.`order_id` AND `o`.`version_id` = `txn`.`order_version_id` '
            . 'INNER JOIN `currency` AS `cur` ON `cur`.`id` = `o`.`currency_id` '
            . 'LEFT JOIN `order_customer` AS `oc` '
            . '  ON `oc`.`order_id` = `o`.`id` AND `oc`.`order_version_id` = `o`.`version_id` '
            . 'WHERE `txn`.`id` = UNHEX(:id) LIMIT 1',
            array('id' => (string) $transactionId)
        );

        if (!is_array($row) || !isset($row['order_number'])) {
            return null;
        }

        // `amount` is the serialised CalculatedPrice; the total is what Paymos bills.
        $total = '';
        if (isset($row['amount']) && is_string($row['amount'])) {
            $decoded = json_decode($row['amount'], true);
            if (is_array($decoded) && isset($decoded['totalPrice'])) {
                $total = (string) $decoded['totalPrice'];
            }
        }

        return array(
            'order_number' => (string) $row['order_number'],
            'amount' => $total,
            'currency' => isset($row['currency']) ? (string) $row['currency'] : '',
            'customer_id' => isset($row['customer_id']) ? (string) $row['customer_id'] : '',
            'sales_channel_id' => isset($row['sales_channel_id']) ? (string) $row['sales_channel_id'] : '',
        );
    }

    public function findLatestPendingTransactionIdForCustomer($customerId, $technicalName)
    {
        $customerId = (string) $customerId;
        if ($customerId === '') {
            return '';
        }

        // Most recent Paymos order transaction for this customer that has not
        // reached a terminal state. Order versioning: join on both id and
        // version_id so the live (not a draft) order row is matched.
        $id = $this->connection->fetchOne(
            'SELECT LOWER(HEX(`txn`.`id`)) '
            . 'FROM `order_transaction` AS `txn` '
            . 'INNER JOIN `order` AS `o` '
            . '  ON `o`.`id` = `txn`.`order_id` AND `o`.`version_id` = `txn`.`order_version_id` '
            . 'INNER JOIN `order_customer` AS `oc` '
            . '  ON `oc`.`order_id` = `o`.`id` AND `oc`.`order_version_id` = `o`.`version_id` '
            . 'INNER JOIN `payment_method` AS `pm` ON `pm`.`id` = `txn`.`payment_method_id` '
            . 'INNER JOIN `state_machine_state` AS `state` ON `state`.`id` = `txn`.`state_id` '
            . 'WHERE `oc`.`customer_id` = UNHEX(:customerId) '
            . '  AND `pm`.`technical_name` = :technicalName '
            . "  AND LOWER(`state`.`technical_name`) NOT IN ('paid', 'refunded', 'refunded_partially', 'cancelled', 'failed') "
            . 'ORDER BY `o`.`created_at` DESC LIMIT 1',
            array(
                'customerId' => $customerId,
                'technicalName' => (string) $technicalName,
            )
        );

        return is_string($id) ? $id : '';
    }

    public function log($message, array $context = array())
    {
        $this->logger->info('[Paymos] ' . (string) $message, $context);
    }
}
