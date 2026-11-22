<?php

declare(strict_types=1);

namespace PaymosPayments\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Defaults;
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

    public function paymentReview($transactionId, $invoiceId, $status, $reason)
    {
        // Preserve unrelated custom fields and bind the update to the exact live order version.
        $this->connection->executeStatement(
            'UPDATE `order` AS o INNER JOIN `order_transaction` AS t '
            . 'ON o.id = t.order_id AND o.version_id = t.order_version_id '
            . "SET o.custom_fields = JSON_SET(COALESCE(o.custom_fields, JSON_OBJECT()), "
            . "'$.paymos_invoice_status', :observation, '$.paymos_payment_review', :reason) "
            . 'WHERE t.id = UNHEX(:id) AND t.version_id = UNHEX(:liveVersion)',
            array(
                'id' => (string) $transactionId,
                'liveVersion' => Defaults::LIVE_VERSION,
                'observation' => $invoiceId . ': ' . $status,
                'reason' => (string) $reason,
            )
        );
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
            . 'WHERE `txn`.`id` = UNHEX(:id) AND `txn`.`version_id` = UNHEX(:liveVersion) LIMIT 1',
            array(
                'id' => (string) $transactionId,
                'liveVersion' => Defaults::LIVE_VERSION,
            )
        );

        return is_string($state) ? $state : '';
    }

    /**
     * Everything the checkout needs about an order, addressed by its transaction.
     *
     * Shopware 6.7 hands the payment handler only an order-transaction id — the
     * order and the sales-channel context that older versions passed in are gone,
     * so the plugin has to load them itself. Order versioning: every query and join
     * pins Defaults::LIVE_VERSION so a draft order version can never override or
     * duplicate the live order.
     *
     * @param string $transactionId
     * @return array<string, mixed>|null null when the transaction is unknown
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
            . 'WHERE `txn`.`id` = UNHEX(:id) AND `txn`.`version_id` = UNHEX(:liveVersion) LIMIT 1',
            array(
                'id' => (string) $transactionId,
                'liveVersion' => Defaults::LIVE_VERSION,
            )
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

        $context = array(
            'order_number' => (string) $row['order_number'],
            'amount' => $total,
            'currency' => isset($row['currency']) ? (string) $row['currency'] : '',
            'customer_id' => isset($row['customer_id']) ? (string) $row['customer_id'] : '',
            'sales_channel_id' => isset($row['sales_channel_id']) ? (string) $row['sales_channel_id'] : '',
        );

        $products = $this->orderProducts($transactionId);
        if ($products !== array()) {
            $context['products'] = $products;
        }

        return $context;
    }

    /**
     * Load native top-level product line items for the order attached to a transaction.
     *
     * @param string $transactionId
     * @return array<int, array<string, mixed>>
     */
    private function orderProducts($transactionId)
    {
        if (!method_exists($this->connection, 'fetchAllAssociative')) {
            return array();
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT `li`.`type` AS `type`, '
            . '  `li`.`parent_id` AS `parent_id`, '
            . '  `li`.`label` AS `label`, '
            . '  `li`.`description` AS `description`, '
            . '  `li`.`quantity` AS `quantity`, '
            . '  `li`.`unit_price` AS `unit_price`, '
            . '  `li`.`price` AS `price`, '
            . '  `li`.`payload` AS `payload` '
            . 'FROM `order_line_item` AS `li` '
            . 'INNER JOIN `order_transaction` AS `txn` '
            . '  ON `txn`.`order_id` = `li`.`order_id` AND `txn`.`order_version_id` = `li`.`order_version_id` '
            . 'WHERE `txn`.`id` = UNHEX(:id) '
            . '  AND `txn`.`version_id` = UNHEX(:liveVersion) '
            . '  AND `li`.`version_id` = `txn`.`version_id` '
            . 'ORDER BY `li`.`position` ASC, `li`.`id` ASC',
            array(
                'id' => (string) $transactionId,
                'liveVersion' => Defaults::LIVE_VERSION,
            )
        );

        if (!is_array($rows)) {
            return array();
        }

        $products = array();
        foreach ($rows as $line) {
            if (!is_array($line)) {
                continue;
            }
            if ((isset($line['type']) ? (string) $line['type'] : '') !== 'product') {
                continue;
            }
            if (isset($line['parent_id']) && $line['parent_id'] !== null && $line['parent_id'] !== '') {
                continue;
            }

            $payload = array();
            if (isset($line['payload']) && is_string($line['payload'])) {
                $decodedPayload = json_decode($line['payload'], true);
                if (is_array($decodedPayload)) {
                    $payload = $decodedPayload;
                }
            }

            $unitAmount = null;
            if (isset($line['price']) && is_string($line['price'])) {
                $decodedPrice = json_decode($line['price'], true);
                if (is_array($decodedPrice) && array_key_exists('unitPrice', $decodedPrice)) {
                    $unitAmount = $decodedPrice['unitPrice'];
                }
            }
            if ($unitAmount === null && array_key_exists('unit_price', $line)) {
                $unitAmount = $line['unit_price'];
            }

            $products[] = CheckoutProcessor::buildProductCandidate(
                isset($line['label']) ? $line['label'] : null,
                isset($line['description']) ? $line['description'] : null,
                array_key_exists('quantity', $line) ? $line['quantity'] : null,
                $unitAmount,
                $payload
            );
        }

        return $products;
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
            . '  AND `txn`.`version_id` = UNHEX(:liveVersion) '
            . '  AND `pm`.`technical_name` = :technicalName '
            . "  AND LOWER(`state`.`technical_name`) NOT IN ('paid', 'refunded', 'refunded_partially', 'cancelled', 'failed') "
            . 'ORDER BY `o`.`created_at` DESC LIMIT 1',
            array(
                'customerId' => $customerId,
                'liveVersion' => Defaults::LIVE_VERSION,
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
