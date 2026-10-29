<?php

declare(strict_types=1);

namespace PaymosPayments\Service;

use Doctrine\DBAL\Connection;
use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\RefundIntent;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundStateHandler;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

final class ShopwareRefundGateway implements ShopwareRefundGatewayInterface
{
    private $db;
    private $captures;
    private $refunds;
    private $refundStates;
    private $paymentStates;
    private $writes;

    public function __construct(Connection $db, EntityRepository $captures, EntityRepository $refunds,
        OrderTransactionCaptureRefundStateHandler $refundStates, OrderTransactionStateHandler $paymentStates,
        ?RefundWriteScope $writes = null)
    {
        $this->db = $db;
        $this->captures = $captures;
        $this->refunds = $refunds;
        $this->refundStates = $refundStates;
        $this->paymentStates = $paymentStates;
        $this->writes = $writes ?: new RefundWriteScope();
    }

    public function payment(string $paymentKey): array
    {
        $this->assertId($paymentKey);
        $row = $this->db->fetchAssociative('SELECT LOWER(HEX(t.id)) AS payment_key, t.amount AS price,
            JSON_UNQUOTE(JSON_EXTRACT(t.amount, "$.totalPrice")) AS amount,
            JSON_UNQUOTE(JSON_EXTRACT(o.total_rounding, "$.decimals")) AS decimals,
            c.iso_code AS currency, s.technical_name AS state, m.technical_name AS method,
            m.handler_identifier, i.paymos_invoice_id, i.project_id, i.environment,
            i.external_order_id, i.amount AS invoice_amount, i.currency AS invoice_currency, i.status AS invoice_status
            FROM order_transaction t
            INNER JOIN `order` o ON o.id = t.order_id AND o.version_id = t.order_version_id
            INNER JOIN currency c ON c.id = o.currency_id
            INNER JOIN state_machine_state s ON s.id = t.state_id
            INNER JOIN payment_method m ON m.id = t.payment_method_id
            INNER JOIN paymos_payment_invoice i ON i.transaction_id = LOWER(HEX(t.id))
            WHERE t.id = UNHEX(?) AND t.version_id = UNHEX(?) AND o.version_id = UNHEX(?)
            ORDER BY i.id DESC LIMIT 2' . $this->rowLock(), [$paymentKey, Defaults::LIVE_VERSION, Defaults::LIVE_VERSION]);
        if (!is_array($row) || $row['method'] !== 'paymos_crypto'
            || !in_array($row['handler_identifier'], [PaymosPaymentHandler::class, PaymosPaymentHandler67::class], true)
            || !AmountGuard::amountsEqual($row['amount'], $row['invoice_amount'])
            || $row['currency'] !== $row['invoice_currency']
            || !in_array($row['invoice_status'], ['paid', 'paid_over'], true)
            || $row['decimals'] === null || !ctype_digit((string) $row['decimals'])) {
            throw new \RuntimeException('refund_payment_mapping_invalid');
        }
        $count = $this->db->fetchOne('SELECT COUNT(*) FROM paymos_payment_invoice WHERE transaction_id = ?', [$paymentKey]);
        if ((int) $count !== 1) {
            throw new \RuntimeException('refund_payment_mapping_ambiguous');
        }
        $row['amount_minor'] = self::minor((string) $row['amount'], (int) $row['decimals']);
        return $row;
    }

    public function refund(string $refundId): array
    {
        $this->assertId($refundId);
        $row = $this->db->fetchAssociative('SELECT LOWER(HEX(r.id)) AS native_id,
            LOWER(HEX(c.order_transaction_id)) AS payment_key, LOWER(HEX(c.id)) AS capture_id,
            r.amount AS refund_price, c.amount AS capture_price,
            JSON_UNQUOTE(JSON_EXTRACT(r.amount, "$.totalPrice")) AS refund_amount,
            JSON_UNQUOTE(JSON_EXTRACT(c.amount, "$.totalPrice")) AS capture_amount,
            s.technical_name AS refund_state, cs.technical_name AS capture_state
            FROM order_transaction_capture_refund r
            INNER JOIN order_transaction_capture c ON c.id = r.capture_id AND c.version_id = r.capture_version_id
            INNER JOIN state_machine_state s ON s.id = r.state_id
            INNER JOIN state_machine_state cs ON cs.id = c.state_id
            WHERE r.id = UNHEX(?) AND r.version_id = UNHEX(?) AND c.version_id = UNHEX(?)
              AND c.order_transaction_version_id = UNHEX(?)' . $this->rowLock(),
            [$refundId, Defaults::LIVE_VERSION, Defaults::LIVE_VERSION, Defaults::LIVE_VERSION]);
        if (!is_array($row)) {
            throw new \RuntimeException('refund_native_missing');
        }
        $payment = $this->payment($row['payment_key']);
        if ($row['capture_state'] !== 'completed'
            || !AmountGuard::amountsEqual($row['capture_amount'], $payment['amount'])
            || !AmountGuard::amountsEqual($row['refund_amount'], $payment['amount'])) {
            throw new \RuntimeException('refund_full_original_total_required');
        }
        $positions = $this->db->fetchOne('SELECT COUNT(*) FROM order_transaction_capture_refund_position WHERE refund_id = UNHEX(?) AND refund_version_id = UNHEX(?)',
            [$refundId, Defaults::LIVE_VERSION]);
        if ((int) $positions !== 0) { throw new \RuntimeException('refund_native_positions_require_review'); }
        return array_merge($payment, $row);
    }

    public function prepareFull(string $paymentKey, string $nativeId, array $invoice): array
    {
        return $this->writes->run($this->context(Context::createDefaultContext()),
            function (Context $context) use ($paymentKey, $nativeId, $invoice) {
                return $this->prepareFullWithContext($paymentKey, $nativeId, $invoice, $context);
            });
    }

    private function prepareFullWithContext(string $paymentKey, string $nativeId, array $invoice, Context $context): array
    {
        $payment = $this->payment($paymentKey);
        if ($payment['state'] !== 'paid' || !isset($invoice['status'], $invoice['order'], $invoice['invoice_id'],
            $invoice['project_id'], $invoice['is_test'])
            || !in_array($invoice['status'], ['paid', 'paid_over'], true)
            || $invoice['invoice_id'] !== $payment['paymos_invoice_id']
            || $invoice['project_id'] !== $payment['project_id']
            || $invoice['is_test'] !== ($payment['environment'] === 'sandbox')
            || ($invoice['order']['external_id'] ?? null) !== $payment['external_order_id']
            || !AmountGuard::amountsEqual($invoice['order']['amount'] ?? '', $payment['amount'])
            || ($invoice['order']['currency'] ?? null) !== $payment['currency']) {
            throw new \RuntimeException('refund_capture_payment_unverified');
        }
        $captures = $this->db->fetchAllAssociative('SELECT LOWER(HEX(id)) AS id FROM order_transaction_capture
            WHERE order_transaction_id = UNHEX(?) AND order_transaction_version_id = UNHEX(?) AND version_id = UNHEX(?)',
            [$paymentKey, Defaults::LIVE_VERSION, Defaults::LIVE_VERSION]);
        if (count($captures) > 1) {
            throw new \RuntimeException('refund_capture_ambiguous');
        }
        $captureId = count($captures) ? $captures[0]['id'] : substr(hash('sha256', 'paymos-capture:' . $paymentKey), 0, 32);
        if (!count($captures)) {
            $this->captures->create([['id' => $captureId, 'versionId' => Defaults::LIVE_VERSION,
                'orderTransactionId' => $paymentKey, 'orderTransactionVersionId' => Defaults::LIVE_VERSION,
                'stateId' => $this->stateId('order_transaction_capture.state', 'completed'),
                'amount' => json_decode($payment['price'], true, 512, JSON_THROW_ON_ERROR),
                'externalReference' => 'paymos:' . $payment['paymos_invoice_id']]], $context);
        }
        $exists = $this->db->fetchOne('SELECT COUNT(*) FROM order_transaction_capture_refund WHERE id = UNHEX(?) AND version_id = UNHEX(?)',
            [$nativeId, Defaults::LIVE_VERSION]);
        if (!(int) $exists) {
            $this->refunds->create([['id' => $nativeId, 'versionId' => Defaults::LIVE_VERSION,
                'captureId' => $captureId, 'captureVersionId' => Defaults::LIVE_VERSION,
                'stateId' => $this->stateId('order_transaction_capture_refund.state', 'open'),
                'amount' => json_decode($payment['price'], true, 512, JSON_THROW_ON_ERROR)]], $context);
        }
        return $this->refund($nativeId);
    }

    public function validate(RefundIntent $intent, bool $projected): ?string
    {
        try {
            $payment = $this->payment($intent->paymentKey());
            if ($intent->invoiceId() !== $payment['paymos_invoice_id'] || $intent->projectId() !== $payment['project_id']
                || $intent->environment() !== $payment['environment']) {
                return 'refund_payment_mapping_changed';
            }
            if ($intent->mode() === 'overpayment') {
                return in_array($payment['state'], ['paid', 'refunded'], true) ? null : 'refund_payment_not_paid';
            }
            $this->context(Context::createDefaultContext());
            $draft = $intent->fullDraft();
            $refund = $this->refund($draft['native_id']);
            if ($refund['payment_key'] !== $intent->paymentKey() || $refund['capture_id'] !== $draft['capture_id']
                || $payment['amount_minor'] !== $draft['amount_minor'] || $payment['currency'] !== $draft['currency']
                || $refund['refund_price'] !== $draft['refund_price'] || $refund['capture_price'] !== $draft['capture_price']
                || $payment['price'] !== $draft['price']
                || $payment['state'] !== ($projected && $refund['refund_state'] === 'completed' ? 'refunded' : 'paid')
                || !in_array($refund['refund_state'], $projected ? ['completed', 'in_progress'] : ['open', 'in_progress'], true)) {
                return 'refund_native_snapshot_changed';
            }
            $others = $this->db->fetchOne('SELECT COUNT(*) FROM order_transaction_capture_refund r
                INNER JOIN order_transaction_capture c ON c.id = r.capture_id AND c.version_id = r.capture_version_id
                INNER JOIN state_machine_state s ON s.id = r.state_id
                WHERE c.order_transaction_id = UNHEX(?) AND c.order_transaction_version_id = UNHEX(?)
                AND r.version_id = UNHEX(?) AND r.id <> UNHEX(?) AND s.technical_name NOT IN ("failed", "cancelled")',
                [$intent->paymentKey(), Defaults::LIVE_VERSION, Defaults::LIVE_VERSION, $draft['native_id']]);
            return (int) $others === 0 ? null : 'refund_manual_or_partial_native_history';
        } catch (\Throwable $error) {
            return $error->getMessage();
        }
    }

    public function transition(RefundIntent $intent, string $state, Context $context): void
    {
        if ($intent->mode() !== 'full') { return; }
        $this->writes->run($this->context($context), function (Context $owned) use ($intent, $state) {
            $this->transitionWithContext($intent, $state, $owned);
        });
    }

    private function transitionWithContext(RefundIntent $intent, string $state, Context $context): void
    {
        $draft = $intent->fullDraft();
        $refund = $this->refund($draft['native_id']);
        $current = $refund['refund_state'];
        if ($current !== $state) {
            if ($current !== 'open' && ($state === 'in_progress' || in_array($current, ['failed', 'cancelled'], true))) {
                $this->refundStates->reopen($draft['native_id'], $context);
                $current = 'open';
            }
            if ($state === 'completed' && $current === 'open') {
                $this->refundStates->process($draft['native_id'], $context);
            }
            $method = ['in_progress' => 'process', 'completed' => 'complete', 'failed' => 'fail', 'cancelled' => 'cancel'][$state];
            $this->refundStates->$method($draft['native_id'], $context);
        }
        if ($state === 'completed' && $this->payment($intent->paymentKey())['state'] !== 'refunded') {
            $this->paymentStates->refund($intent->paymentKey(), $context);
        } elseif ($state !== 'completed' && $this->payment($intent->paymentKey())['state'] === 'refunded') {
            $this->paymentStates->reopen($intent->paymentKey(), $context);
            $this->paymentStates->paid($intent->paymentKey(), $context);
        }
    }

    private function context(Context $context): Context
    {
        if ($context->getVersionId() !== Defaults::LIVE_VERSION || !defined(Context::class . '::SKIP_TRIGGER_FLOW')) {
            throw new \RuntimeException('refund_native_context_unsupported');
        }
        $copy = clone $context;
        $copy->addState(Context::SKIP_TRIGGER_FLOW);
        return $copy;
    }

    private function stateId(string $machine, string $state): string
    {
        $id = $this->db->fetchOne('SELECT LOWER(HEX(s.id)) FROM state_machine_state s
            INNER JOIN state_machine m ON m.id = s.state_machine_id WHERE m.technical_name = ? AND s.technical_name = ?', [$machine, $state]);
        if (!is_string($id) || $id === '') { throw new \RuntimeException('refund_native_state_missing'); }
        return $id;
    }

    private function assertId(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) { throw new \InvalidArgumentException('refund_native_id_invalid'); }
    }

    private function rowLock(): string
    {
        return $this->db->getTransactionNestingLevel() > 0 ? ' FOR UPDATE' : '';
    }

    public static function minor(string $amount, int $decimals): string
    {
        if ($decimals < 0 || $decimals > 8 || !preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]+))?$/D', $amount, $parts)) {
            throw new \RuntimeException('refund_native_amount_invalid');
        }
        $fraction = $parts[2] ?? '';
        if (strlen(rtrim($fraction, '0')) > $decimals) { throw new \RuntimeException('refund_native_amount_precision'); }
        $minor = ltrim($parts[1] . substr(str_pad($fraction, $decimals, '0'), 0, $decimals), '0');
        if ($minor === '') { throw new \RuntimeException('refund_native_amount_invalid'); }
        return $minor;
    }
}
