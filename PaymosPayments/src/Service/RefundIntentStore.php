<?php

declare(strict_types=1);

namespace PaymosPayments\Service;

use Doctrine\DBAL\Connection;
use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\EffectClaim;
use Paymos\Plugin\NativeProjectionResult;
use Paymos\Plugin\RefundIntent;
use Paymos\Plugin\RefundIntentPage;
use Paymos\Plugin\RefundIntentStoreInterface;
use Paymos\Plugin\RefundStates;
use Paymos\Plugin\VerifiedRefund;

class RefundIntentStore implements RefundIntentStoreInterface
{
    private $db;
    private $clock;
    private $paymentKey;
    private $lockName;
    private $inTransaction = false;

    public function __construct(Connection $connection, ?callable $clock = null)
    {
        $this->db = $connection;
        $this->clock = $clock ?: static function () { return new \DateTimeImmutable('now'); };
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
        $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    public function installationId(): string
    {
        if ($this->db->getTransactionNestingLevel() !== 0 && !$this->inTransaction) {
            throw new \RuntimeException('refund_intent_requires_commit');
        }
        $this->db->executeStatement('INSERT IGNORE INTO paymos_refund_installation (entity_id, installation_id) VALUES (1, ?)', [self::uuid()]);
        return (string) $this->db->fetchOne('SELECT installation_id FROM ' . $this->table('installation') . ' WHERE entity_id = 1');
    }

    public function withPaymentLock(string $paymentKey, callable $action)
    {
        if ($this->paymentKey !== null) {
            $this->assertLock($paymentKey);
            return $action();
        }
        if ($this->db->getTransactionNestingLevel() !== 0) {
            throw new \RuntimeException('refund_outer_transaction_forbidden');
        }
        $name = 'pmrf:sw:' . substr(hash('sha256', $this->installationId() . ':' . $paymentKey), 0, 48);
        if ((string) $this->db->fetchOne('SELECT GET_LOCK(?, 0)', [$name]) !== '1') {
            throw new \RuntimeException('refund_payment_busy');
        }
        $this->paymentKey = $paymentKey;
        $this->lockName = $name;
        try {
            $this->assertLock($paymentKey);
            $result = $action();
            $this->assertLock($paymentKey);
            return $result;
        } finally {
            try {
                $this->db->fetchOne('SELECT RELEASE_LOCK(?)', [$name]);
            } finally {
                $this->paymentKey = null;
                $this->lockName = null;
            }
        }
    }

    public function assertLock(string $key): void
    {
        if ($key !== $this->paymentKey || $this->lockName === null
            || (string) $this->db->fetchOne('SELECT IS_USED_LOCK(?) = CONNECTION_ID()', [$this->lockName]) !== '1') {
            throw new \RuntimeException('refund_payment_lock_lost');
        }
    }

    public function transaction(callable $action)
    {
        $this->assertLock((string) $this->paymentKey);
        if ($this->inTransaction) {
            return $action();
        }
        if ($this->db->getTransactionNestingLevel() !== 0) {
            throw new \RuntimeException('refund_outer_transaction_forbidden');
        }
        $this->inTransaction = true;
        try {
            return $this->db->transactional(function () use ($action) {
                $result = $action();
                $this->assertLock((string) $this->paymentKey);
                if ($this->db->getTransactionNestingLevel() !== 1) {
                    throw new \RuntimeException('refund_native_transaction_unbalanced');
                }
                return $result;
            });
        } finally {
            $this->inTransaction = false;
        }
    }

    public function createIntent(RefundIntent $intent): RefundIntent
    {
        if ($this->inTransaction || $this->db->getTransactionNestingLevel() !== 0) {
            throw new \RuntimeException('refund_intent_requires_commit');
        }
        $this->assertLock($intent->paymentKey());
        return $this->transaction(function () use ($intent) {
            $data = $intent->toArray();
            $existing = $this->findByRequestToken($data['installation_id'], $data['request_token']);
            if ($existing !== null) {
                $previous = $existing->toArray();
                foreach (['platform', 'native_payment_key', 'invoice_id', 'project_id', 'environment', 'mode', 'full_draft'] as $field) {
                    if ($data[$field] !== $previous[$field]) {
                        throw new \RuntimeException('refund_request_conflict');
                    }
                }
                return $existing;
            }
            if ($data['platform'] !== 'shopware6' || $data['installation_id'] !== $this->installationId()
                || !preg_match('/^[a-zA-Z0-9-]{16,64}$/D', $data['request_token'])
                || $intent->withdrawalId() !== null || $intent->cashState() !== RefundStates::NOT_SUBMITTED
                || $intent->projectionState() !== RefundStates::PROJECTION_NONE || $intent->observation() !== null
                || $data['projection'] !== null || $data['effects'] !== []) {
                throw new \RuntimeException('refund_invalid_new_intent');
            }
            $this->assertNewActionAllowed($intent->paymentKey());
            $nativeId = $intent->mode() === RefundStates::FULL ? ($intent->fullDraft()['native_id'] ?? null) : null;
            if ($intent->mode() === RefundStates::FULL && (!is_string($nativeId) || !preg_match('/^[a-f0-9]{32}$/D', $nativeId)
                || $this->findByNativeRefundId($nativeId) !== null)) { throw new \RuntimeException('refund_native_identity_conflict'); }
            $now = $this->now()->getTimestamp();
            $data['created_at'] = $now;
            $this->db->insert($this->table('intent'), [
                'operation_id' => $intent->operationId(), 'installation_id' => $data['installation_id'],
                'request_token' => $data['request_token'], 'payment_key' => $intent->paymentKey(),
                'external_order_id' => $intent->externalOrderId(), 'native_refund_id' => $nativeId, 'payload' => $this->encode($data),
                'created_at' => $now, 'next_due' => $now, 'poll_count' => 0,
            ]);
            return $this->getIntent($intent->operationId());
        });
    }

    public function assertNewActionAllowed(string $key): void
    {
        $this->assertLock($key);
        foreach ($this->unboundForPayment($key) as $row) {
            $refund = VerifiedRefund::fromStorage($this->decode($row['payload']));
            if ($refund->mode() === RefundStates::FULL || !in_array($refund->status(), ['completed', 'failed', 'cancelled'], true)) {
                throw new \RuntimeException('refund_unbound_cash_requires_review');
            }
        }
        foreach ($this->forPayment($key) as $intent) {
            if ($intent->projectionState() === RefundStates::NATIVE_REVIEW
                || !in_array($intent->cashState(), [RefundStates::COMPLETED, RefundStates::FAILED, RefundStates::CANCELLED], true)
                || ($intent->cashState() === RefundStates::COMPLETED && $intent->projectionState() !== RefundStates::PROJECTION_APPLIED)
                || ($intent->mode() === RefundStates::FULL && $intent->toArray()['projection'] !== null)) {
                throw new \RuntimeException('refund_payment_unresolved_or_refunded');
            }
        }
    }

    public function findByRequestToken(string $installationId, string $token): ?RefundIntent
    {
        return $this->find('installation_id = ? AND request_token = ?', [$installationId, $token]);
    }

    public function getIntent(string $operationId): RefundIntent
    {
        $intent = $this->find('operation_id = ?', [$operationId]);
        if ($intent === null) {
            throw new \RuntimeException('refund_intent_missing');
        }
        return $intent;
    }

    public function findByExternalId(string $key): ?RefundIntent { return $this->find('external_order_id = ?', [$key]); }
    public function findByWithdrawalId(string $id): ?RefundIntent { return $this->find('withdrawal_id = ?', [$id]); }
    public function findByNativeRefundId(string $id): ?RefundIntent { return $this->find('native_refund_id = ?', [$id]); }

    private function find(string $where, array $bind): ?RefundIntent
    {
        $row = $this->db->fetchAssociative('SELECT * FROM ' . $this->table('intent') . ' WHERE ' . $where . ' LIMIT 1', $bind);
        return is_array($row) ? new RefundIntent($this->decode($row['payload'])) : null;
    }

    public function forPayment(string $key): array
    {
        return array_map(function ($row) { return new RefundIntent($this->decode($row['payload'])); },
            $this->db->fetchAllAssociative('SELECT * FROM ' . $this->table('intent') . ' WHERE payment_key = ? ORDER BY created_at, operation_id', [$key]));
    }

    public function bindWithdrawal(string $operationId, string $id): string
    {
        return $this->transaction(function () use ($operationId, $id) {
            $intent = $this->getIntent($operationId);
            $this->assertLock($intent->paymentKey());
            $other = $this->findByWithdrawalId($id);
            if ($id === '' || ($other !== null && $other->operationId() !== $operationId)
                || ($intent->withdrawalId() !== null && $intent->withdrawalId() !== $id)) {
                $this->recordState($operationId, RefundStates::REVIEW, RefundStates::NATIVE_REVIEW, 'refund_binding_conflict');
                return 'conflict';
            }
            if ($intent->withdrawalId() === $id) {
                return 'same';
            }
            try {
                $count = $this->db->executeStatement('UPDATE paymos_refund_intent SET withdrawal_id = ? WHERE operation_id = ? AND withdrawal_id IS NULL', [$id, $operationId]);
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $error) {
                $this->recordState($operationId, RefundStates::REVIEW, RefundStates::NATIVE_REVIEW, 'refund_binding_conflict');
                return 'conflict';
            }
            if ($count !== 1) {
                throw new \RuntimeException('refund_binding_conflict');
            }
            $this->update($intent, ['withdrawal_id' => $id]);
            return 'bound';
        });
    }

    public function recordObservation(string $operationId, VerifiedRefund $snapshot): void
    {
        $this->transaction(function () use ($operationId, $snapshot) {
            $intent = $this->getIntent($operationId);
            $pin = $intent->observation();
            if ($snapshot->withdrawalId() !== $intent->withdrawalId() || $snapshot->invoiceId() !== $intent->invoiceId()
                || $snapshot->projectId() !== $intent->projectId() || $snapshot->environment() !== $intent->environment()
                || $snapshot->mode() !== $intent->mode() || $snapshot->externalOrderId() !== $intent->externalOrderId()
                || ($pin !== null && (!AmountGuard::amountsEqual($pin->principalDecimal(), $snapshot->principalDecimal())
                    || $pin->currency() !== $snapshot->currency() || $pin->network() !== $snapshot->network()
                    || !\Paymos\Plugin\RefundRecipientIdentity::same($pin, $snapshot)))) {
                throw new \RuntimeException('refund_observation_conflict');
            }
            $this->update($intent, ['observation' => $snapshot->toArray()]);
        });
    }

    public function recordProjection(string $operationId, NativeProjectionResult $result): void
    {
        $this->transaction(function () use ($operationId, $result) {
            $intent = $this->getIntent($operationId);
            $saved = $intent->toArray()['projection'];
            if ($result->kind() !== NativeProjectionResult::EXACT_MATCH || $result->mode() !== $intent->mode()
                || ($saved !== null && $saved !== $result->toArray())) {
                throw new \RuntimeException('refund_projection_conflict');
            }
            $this->update($intent, ['projection' => $result->toArray()]);
        });
    }

    public function recordState(string $operationId, string $cashState, string $projectionState, ?string $reason): void
    {
        $this->transaction(function () use ($operationId, $cashState, $projectionState, $reason) {
            if (!in_array($cashState, ['not_submitted', 'unknown', 'created', 'processing', 'completed', 'failed', 'cancelled', 'review'], true)
                || !in_array($projectionState, ['none', 'pending', 'applied', 'native_review'], true)) {
                throw new \InvalidArgumentException('refund_invalid_state');
            }
            $this->update($this->getIntent($operationId), ['cash_state' => $cashState,
                'projection_state' => $projectionState, 'review_reason' => $reason]);
        });
    }

    public function claimEffect(string $operationId, string $kind, int $generation): EffectClaim
    {
        if ($this->inTransaction || $kind === '' || $generation < 1) {
            throw new \RuntimeException('refund_effect_requires_commit');
        }
        return $this->transaction(function () use ($operationId, $kind, $generation) {
            $intent = $this->getIntent($operationId);
            $effects = $intent->toArray()['effects'];
            $key = $kind . ':' . $generation;
            if (isset($effects[$key]) && $effects[$key] !== RefundStates::EFFECT_PENDING) {
                return new EffectClaim(EffectClaim::ALREADY_STARTED);
            }
            if ($intent->cashState() !== RefundStates::COMPLETED || $intent->projectionState() !== RefundStates::PROJECTION_APPLIED) {
                throw new \RuntimeException('refund_effect_not_ready');
            }
            $effects[$key] = RefundStates::EFFECT_STARTED;
            $this->update($intent, ['effects' => $effects]);
            return new EffectClaim(EffectClaim::CLAIMED);
        });
    }

    public function recordEffectOutcome(string $operationId, string $kind, int $generation, string $state): void
    {
        $this->transaction(function () use ($operationId, $kind, $generation, $state) {
            $intent = $this->getIntent($operationId);
            $effects = $intent->toArray()['effects'];
            $key = $kind . ':' . $generation;
            if (($effects[$key] ?? null) !== RefundStates::EFFECT_STARTED
                || !in_array($state, ['delivered', 'failed_no_effect', 'unknown', 'skipped'], true)) {
                throw new \RuntimeException('refund_effect_conflict');
            }
            $effects[$key] = $state;
            $this->update($intent, ['effects' => $effects]);
        });
    }

    public function listDue(\DateTimeImmutable $now, int $limit, ?string $cursor): RefundIntentPage
    {
        $limit = max(1, min(100, $limit));
        $bind = [$now->getTimestamp()];
        $where = 'next_due <= ?';
        if ($cursor !== null) {
            $parts = json_decode((string) base64_decode($cursor, true), true);
            if (!is_array($parts) || count($parts) !== 2 || !is_int($parts[0]) || !is_string($parts[1])) {
                throw new \InvalidArgumentException('refund_invalid_cursor');
            }
            $where .= ' AND (next_due > ? OR (next_due = ? AND operation_id > ?))';
            $bind = array_merge($bind, [$parts[0], $parts[0], $parts[1]]);
        }
        $rows = $this->db->fetchAllAssociative('SELECT * FROM ' . $this->table('intent') . ' WHERE ' . $where
            . ' ORDER BY next_due, operation_id LIMIT ' . ($limit + 1), $bind);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $last = count($rows) ? $rows[count($rows) - 1] : null;
        return new RefundIntentPage(array_map(function ($row) { return new RefundIntent($this->decode($row['payload'])); }, $rows),
            $more && $last !== null ? base64_encode($this->encode([(int) $last['next_due'], $last['operation_id']])) : null);
    }

    public function schedule(string $operationId): void
    {
        $this->transaction(function () use ($operationId) {
            $intent = $this->getIntent($operationId);
            $row = $this->db->fetchAssociative('SELECT * FROM ' . $this->table('intent') . ' WHERE operation_id = ?', [$operationId]);
            $now = $this->now()->getTimestamp();
            if ($now - (int) $row['created_at'] > 86400
                && in_array($intent->cashState(), ['not_submitted', 'unknown', 'created', 'processing'], true)) {
                $this->recordState($operationId, $intent->cashState(), RefundStates::NATIVE_REVIEW, 'refund_unresolved_24h');
                $intent = $this->getIntent($operationId);
            }
            $delays = [60, 120, 300, 900, 3600];
            $delay = in_array($intent->cashState(), ['completed', 'failed', 'cancelled', 'review'], true)
                ? 86400 : $delays[min((int) $row['poll_count'], 4)];
            if ($intent->cashState() === 'completed' && $intent->projectionState() !== 'applied') {
                $delay = 60;
            }
            $this->db->update($this->table('intent'), ['next_due' => $now + $delay, 'poll_count' => (int) $row['poll_count'] + 1],
                ['operation_id' => $operationId]);
        });
    }

    public function connection() { return $this->db; }
    public function table(string $suffix): string { return 'paymos_refund_' . $suffix; }

    public function assertNativeConnection(): void
    {
        if (!defined('Shopware\\Core\\Framework\\Context::SKIP_TRIGGER_FLOW')) {
            throw new \RuntimeException('refund_native_flow_boundary_missing');
        }
        $tables = ['paymos_refund_intent', 'paymos_refund_projection', 'order_transaction',
            'order_transaction_capture', 'order_transaction_capture_refund', 'state_machine_history'];
        foreach ($tables as $table) {
            $engine = $this->db->fetchOne('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
            if (strtolower((string) $engine) !== 'innodb') { throw new \RuntimeException('refund_native_transaction_unsupported'); }
        }
    }

    public function saveMarker(RefundIntent $intent, NativeProjectionResult $result): void
    {
        if (!$this->inTransaction) {
            throw new \RuntimeException('refund_native_transaction_required');
        }
        $this->assertLock($intent->paymentKey());
        $this->db->insert($this->table('projection'), ['operation_id' => $intent->operationId(),
            'payment_key' => $intent->paymentKey(), 'result' => $this->encode($result->toArray()),
            'note' => $intent->mode() === RefundStates::OVERPAYMENT ? 'TODO-COPY' : '']);
    }

    public function marker(string $operationId): ?array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM ' . $this->table('projection') . ' WHERE operation_id = ?', [$operationId]);
        return is_array($row) ? $row : null;
    }

    public function paymentForInvoice(string $invoiceId): string
    {
        $rows = $this->db->fetchAllAssociative('SELECT transaction_id FROM paymos_payment_invoice WHERE paymos_invoice_id = ?', [$invoiceId]);
        if (count($rows) !== 1) { throw new \RuntimeException('refund_unbound_payment_ambiguous'); }
        return (string) $rows[0]['transaction_id'];
    }

    public function saveUnbound(string $paymentKey, VerifiedRefund $refund): void
    {
        $this->transaction(function () use ($paymentKey, $refund) {
            $this->assertLock($paymentKey);
            $old = $this->db->fetchAssociative('SELECT * FROM paymos_refund_observation WHERE withdrawal_id = ?', [$refund->withdrawalId()]);
            if (is_array($old)) {
                $pin = VerifiedRefund::fromStorage($this->decode($old['payload']));
                if ($old['payment_key'] !== $paymentKey || $pin->invoiceId() !== $refund->invoiceId()
                    || $pin->mode() !== $refund->mode() || $pin->projectId() !== $refund->projectId()
                    || $pin->environment() !== $refund->environment() || $pin->externalOrderId() !== $refund->externalOrderId()
                    || !AmountGuard::amountsEqual($pin->principalDecimal(), $refund->principalDecimal())
                    || $pin->currency() !== $refund->currency() || $pin->network() !== $refund->network()
                    || !\Paymos\Plugin\RefundRecipientIdentity::same($pin, $refund)) { throw new \RuntimeException('refund_unbound_evidence_changed'); }
            }
            $data = ['payment_key' => $paymentKey, 'payload' => $this->encode($refund->toArray()),
                'note' => $old['note'] ?? ($refund->mode() === 'overpayment' && $refund->status() === 'completed' ? 'TODO-COPY' : null),
                'review_reason' => $refund->mode() === 'full' ? 'refund_unbound_full_requires_draft' : null,
                'next_due' => $this->now()->getTimestamp() + 86400];
            if (is_array($old)) { $this->db->update('paymos_refund_observation', $data, ['withdrawal_id' => $refund->withdrawalId()]); }
            else { $this->db->insert('paymos_refund_observation', ['withdrawal_id' => $refund->withdrawalId()] + $data); }
        });
    }

    public function unboundDue(\DateTimeImmutable $now): array
    {
        return $this->db->fetchAllAssociative('SELECT * FROM paymos_refund_observation WHERE next_due <= ? ORDER BY next_due, withdrawal_id LIMIT 100', [$now->getTimestamp()]);
    }

    public function unboundForPayment(string $paymentKey): array
    {
        return $this->db->fetchAllAssociative('SELECT * FROM paymos_refund_observation WHERE payment_key = ?', [$paymentKey]);
    }

    private function update(RefundIntent $intent, array $changes): void
    {
        $this->assertLock($intent->paymentKey());
        $this->db->update($this->table('intent'), ['payload' => $this->encode(array_replace($intent->toArray(), $changes))],
            ['operation_id' => $intent->operationId()]);
    }

    private function now(): \DateTimeImmutable { return call_user_func($this->clock); }
    private function encode(array $value): string { return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
    private function decode(string $value): array { return json_decode($value, true, 512, JSON_THROW_ON_ERROR); }
}
