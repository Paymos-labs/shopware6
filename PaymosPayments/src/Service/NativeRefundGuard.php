<?php

declare(strict_types=1);

namespace PaymosPayments\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;

final class NativeRefundGuard
{
    private const TRANSACTION = 'order_transaction';
    private const CAPTURE = 'order_transaction_capture';
    private const REFUND = 'order_transaction_capture_refund';
    private const POSITION = 'order_transaction_capture_refund_position';

    private $db;
    private $writes;

    public function __construct(Connection $db, RefundWriteScope $writes)
    {
        $this->db = $db;
        $this->writes = $writes;
    }

    public function beforeWrite(PreWriteValidationEvent $event): void
    {
        $context = $event->getContext();
        if ($this->writes->allows($context)) { return; }

        $version = hex2bin($context->getVersionId());
        $pending = [];
        $commands = [];
        $histories = [];
        foreach ($event->getCommands() as $command) {
            if (!$command instanceof InsertCommand && !$command instanceof UpdateCommand) { continue; }
            $entity = $command->getEntityName();
            if ($entity === 'state_machine_history' && $command instanceof InsertCommand) {
                $histories[] = $command->getPayload();
                continue;
            }
            if (!in_array($entity, [self::TRANSACTION, self::CAPTURE, self::REFUND, self::POSITION], true)) { continue; }
            $row = array_merge($command->getPrimaryKey(), $command->getPayload());
            $rowVersion = $row['version_id'] ?? $version;
            $key = $this->key($entity, $row['id'], $rowVersion);
            $pending[$key] = array_merge($pending[$key] ?? [], $row);
            if ($entity === self::TRANSACTION && isset($row['payment_method_id'])) {
                $pending[$key]['_paymos_methods'][] = $row['payment_method_id'];
            }
            $commands[] = [$command, $entity, $row['id'], $rowVersion];
        }

        $stored = [];
        // The registry commits history before updating the entity's state.
        foreach ($histories as $history) {
            $entity = $history['entity_name'] ?? '';
            if (!in_array($entity, [self::TRANSACTION, self::CAPTURE, self::REFUND], true)
                || !isset($history['referenced_id'], $history['to_state_id'])) { continue; }
            $states = $entity === self::REFUND ? ['completed'] : ['refunded', 'refunded_partially'];
            if ($entity !== self::CAPTURE && !$this->isRefunded($history['to_state_id'], $states)) { continue; }
            $id = $history['referenced_id'];
            $rowVersion = $history['referenced_version_id'] ?? $version;
            if ($this->isPaymos($entity, $id, $rowVersion, [], $stored)
                || $this->isPaymos($entity, $id, $rowVersion, $pending, $stored)) { $this->deny(); }
        }
        foreach ($commands as [$command, $entity, $id, $rowVersion]) {
            $current = $pending;
            $key = $this->key($entity, $id, $rowVersion);
            $current[$key] = array_merge($current[$key], $command->getPayload());
            if (!$this->isPaymos($entity, $id, $rowVersion, [], $stored)
                && !$this->isPaymos($entity, $id, $rowVersion, $current, $stored)) { continue; }

            $payload = $command->getPayload();
            if ($entity === self::TRANSACTION) {
                $row = $this->row($entity, $id, $rowVersion, $current, $stored);
                if ((isset($payload['state_id']) || isset($payload['payment_method_id']))
                    && $this->isRefunded($row['state_id'] ?? null, ['refunded', 'refunded_partially'])) {
                    $this->deny();
                }
                continue;
            }

            // Nonterminal states allow core failure handling without claiming a refund.
            if ($entity === self::REFUND && $command instanceof UpdateCommand
                && isset($payload['state_id'])
                && count(array_diff(array_keys($payload), ['state_id', 'updated_at'])) === 0
                && !$this->isRefunded($payload['state_id'], ['completed'])) { continue; }

            $this->deny();
        }
    }

    private function isPaymos(string $entity, string $id, string $version, array $pending, array &$stored): bool
    {
        $row = $this->row($entity, $id, $version, $pending, $stored);
        if ($entity === self::POSITION) {
            return isset($row['refund_id']) && $this->isPaymos(self::REFUND, $row['refund_id'],
                $row['refund_version_id'] ?? $version, $pending, $stored);
        }
        if ($entity === self::REFUND) {
            return isset($row['capture_id']) && $this->isPaymos(self::CAPTURE, $row['capture_id'],
                $row['capture_version_id'] ?? $version, $pending, $stored);
        }
        if ($entity === self::CAPTURE) {
            return isset($row['order_transaction_id']) && $this->isPaymos(self::TRANSACTION, $row['order_transaction_id'],
                $row['order_transaction_version_id'] ?? $version, $pending, $stored);
        }
        if (!$row) { return false; }

        // Invoice ownership survives a later change of the payment method.
        if ($this->db->fetchOne('SELECT 1 FROM paymos_payment_invoice WHERE transaction_id = ? LIMIT 1', [bin2hex($id)])) {
            return true;
        }
        $methods = $row['_paymos_methods'] ?? [];
        if (isset($row['payment_method_id'])) { $methods[] = $row['payment_method_id']; }
        foreach (array_unique($methods) as $methodId) {
            $method = $this->db->fetchAssociative('SELECT handler_identifier FROM payment_method WHERE id = ?', [$methodId]);
            if (is_array($method) && in_array($method['handler_identifier'],
                [PaymosPaymentHandler::class, PaymosPaymentHandler67::class], true)) { return true; }
        }
        return false;
    }

    private function row(string $entity, string $id, string $version, array $pending, array &$stored): array
    {
        $key = $this->key($entity, $id, $version);
        if (!array_key_exists($key, $stored)) {
            $row = $this->db->fetchAssociative('SELECT * FROM `' . $entity . '` WHERE id = ? AND version_id = ?', [$id, $version]);
            $stored[$key] = is_array($row) ? $row : [];
        }
        return array_merge($stored[$key], $pending[$key] ?? []);
    }

    private function key(string $entity, string $id, string $version): string
    {
        return $entity . ':' . bin2hex($id) . ':' . bin2hex($version);
    }

    private function isRefunded(?string $stateId, array $states): bool
    {
        if ($stateId === null) { return false; }
        $state = $this->db->fetchOne('SELECT technical_name FROM state_machine_state WHERE id = ?', [$stateId]);
        return in_array($state, $states, true);
    }

    private function deny(): void
    {
        throw new \RuntimeException('refund_manual_or_partial_native_history');
    }
}
