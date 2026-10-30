<?php

declare(strict_types=1);

namespace PaymosPayments\Service;

use Paymos\Client;
use Paymos\Plugin\CredentialSet;
use Paymos\Plugin\NativeProjectionResult;
use Paymos\Plugin\NativeRefundProjectorInterface;
use Paymos\Plugin\RefundCoordinator;
use Paymos\Plugin\RefundIntent;
use Paymos\Plugin\RefundStates;
use Paymos\Plugin\VerifiedRefund;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;

final class RefundAdapter implements NativeRefundProjectorInterface
{
    private $store;
    private $native;
    private $credentials;
    private $clientFactory;

    public function __construct(RefundIntentStore $store, ShopwareRefundGatewayInterface $native,
        CredentialStore $credentials, ?callable $clientFactory = null)
    {
        $this->store = $store;
        $this->native = $native;
        $this->credentials = $credentials;
        $this->clientFactory = $clientFactory;
    }

    public function supports(): bool
    {
        foreach ($this->credentials->settings([])['environments'] ?? [] as $values) {
            if (CredentialSet::hasCapability($values, 'invoice_refunds_v1') && CredentialSet::refundsEnabled($values)) {
                return true;
            }
        }
        return false;
    }

    /** The core refund processor already authorizes this entry. */
    public function nativeFull(string $refundId, Context $context): array
    {
        if ($context->getVersionId() !== Defaults::LIVE_VERSION) {
            throw new \RuntimeException('refund_live_version_required');
        }
        try {
            $row = $this->native->refund($refundId);
            return $this->store->withPaymentLock($row['payment_key'], function () use ($row, $refundId) {
                $existing = $this->store->findByNativeRefundId($refundId);
                $token = $existing !== null ? $existing->toArray()['request_token'] : $refundId;
                return $this->request($row['payment_key'], RefundStates::FULL, $token, $refundId);
            });
        } catch (\Throwable $error) {
            // Core must not turn possibly accepted cash into a failed native refund.
            $existing = $this->store->findByNativeRefundId($refundId);
            if ($existing !== null && ($existing->cashState() === RefundStates::UNKNOWN || $existing->withdrawalId() !== null)) {
                return $this->view($existing);
            }
            throw $error;
        }
    }

    public function full(string $paymentKey, string $token): array
    {
        return $this->request($paymentKey, RefundStates::FULL, $token, null);
    }

    public function overpayment(string $paymentKey, string $token): array
    {
        return $this->request($paymentKey, RefundStates::OVERPAYMENT, $token, null);
    }

    private function request(string $paymentKey, string $mode, string $token, ?string $nativeId): array
    {
        if (!preg_match('/^[a-zA-Z0-9-]{16,64}$/D', $token)) {
            throw new \InvalidArgumentException('refund_request_token_invalid');
        }
        return $this->store->withPaymentLock($paymentKey, function () use ($paymentKey, $mode, $token, $nativeId) {
            $installation = $this->store->installationId();
            $existing = $this->store->findByRequestToken($installation, $token);
            if ($existing !== null) {
                if ($existing->paymentKey() !== $paymentKey || $existing->mode() !== $mode
                    || ($nativeId !== null && ($existing->fullDraft()['native_id'] ?? null) !== $nativeId)) {
                    throw new \RuntimeException('refund_request_conflict');
                }
                $this->assertConnection($existing->projectId(), $existing->environment(), false);
                return $this->submit($existing);
            }
            $row = $this->native->payment($paymentKey);
            $this->assertConnection($row['project_id'], $row['environment'], true);
            $this->store->assertNewActionAllowed($paymentKey);
            if ($row['state'] !== 'paid') { throw new \RuntimeException('refund_payment_not_paid'); }
            $draft = null;
            if ($mode === RefundStates::FULL) {
                if ($nativeId === null) {
                    $invoice = $this->client($row['environment'])->invoices()->get($row['paymos_invoice_id']);
                    $this->store->assertLock($paymentKey);
                    $nativeId = substr(hash('sha256', $installation . ':' . $token), 0, 32);
                    $row = $this->store->transaction(function () use ($paymentKey, $nativeId, $invoice) {
                        return $this->native->prepareFull($paymentKey, $nativeId, $invoice);
                    });
                } else {
                    $row = $this->native->refund($nativeId);
                }
                $draft = array_intersect_key($row, array_flip(['native_id', 'capture_id', 'amount_minor', 'currency',
                    'price', 'refund_price', 'capture_price']));
                $nativeIntent = $this->store->findByNativeRefundId($nativeId);
                if ($nativeIntent !== null) {
                    if ($nativeIntent->paymentKey() !== $paymentKey || $nativeIntent->fullDraft() !== $draft) {
                        throw new \RuntimeException('refund_native_identity_conflict');
                    }
                    return $this->submit($nativeIntent);
                }
                if (ShopwareRefundGateway::isLegacySyntheticCapture($paymentKey, (string) ($draft['capture_id'] ?? ''))) {
                    throw new \RuntimeException('refund_capture_unverified');
                }
            }
            $intent = new RefundIntent(['operation_id' => RefundIntentStore::uuid(), 'request_token' => $token,
                'installation_id' => $installation, 'platform' => 'shopware6', 'native_payment_key' => $paymentKey,
                'invoice_id' => $row['paymos_invoice_id'], 'project_id' => $row['project_id'],
                'environment' => $row['environment'], 'mode' => $mode, 'full_draft' => $draft]);
            $reason = $this->preflight($intent);
            if ($reason !== null) { throw new \RuntimeException($reason); }
            return $this->submit($this->store->createIntent($intent));
        });
    }

    private function submit(RefundIntent $intent): array
    {
        if ($this->hasLegacySyntheticCapture($intent)) {
            $this->holdLegacySyntheticCapture($intent);
            return $this->view($this->store->getIntent($intent->operationId()));
        }
        $coordinator = $this->coordinator($intent);
        $scheduled = false;
        // Everything after submission must survive without failing core's refund processor.
        try {
            $coordinator->submit($intent->operationId());
            if ($this->store->getIntent($intent->operationId())->withdrawalId() !== null) {
                $this->reconcile($intent->operationId());
                $scheduled = true;
            } else {
                $this->syncNative($this->store->getIntent($intent->operationId()));
            }
        } catch (\Throwable $error) {
            $current = $this->store->getIntent($intent->operationId());
            $this->store->recordState($intent->operationId(), $current->cashState(), RefundStates::NATIVE_REVIEW, 'native_projection_unknown');
        }
        if (!$scheduled) {
            $this->store->schedule($intent->operationId());
        }
        return $this->view($this->store->getIntent($intent->operationId()));
    }

    private function hasLegacySyntheticCapture(RefundIntent $intent): bool
    {
        if ($intent->mode() !== RefundStates::FULL) { return false; }
        $draft = $intent->fullDraft();
        return is_array($draft) && isset($draft['capture_id']) && is_string($draft['capture_id'])
            && ShopwareRefundGateway::isLegacySyntheticCapture($intent->paymentKey(), $draft['capture_id']);
    }

    /** Preserve uncertain cash and historical native rows; a bound ID can only be verified by GET. */
    private function holdLegacySyntheticCapture(RefundIntent $intent): string
    {
        $reason = $intent->toArray()['review_reason'] ?? null;
        if (!is_string($reason) || $reason === '' || in_array($reason, ['cash_rewound', 'native_projection_unknown'], true)) {
            $reason = 'refund_capture_unverified';
        }
        $this->store->recordState($intent->operationId(), $intent->cashState(), RefundStates::NATIVE_REVIEW, $reason);
        $action = RefundStates::SHOW_REVIEW;
        if ($intent->withdrawalId() !== null) {
            $this->assertConnection($intent->projectId(), $intent->environment(), false);
            $action = $this->coordinator($this->store->getIntent($intent->operationId()))->reconcile($intent->operationId());
        }
        $this->store->schedule($intent->operationId());
        return $action;
    }

    public function preflight(RefundIntent $intent): ?string
    {
        try {
            $this->assertConnection($intent->projectId(), $intent->environment(), true);
            $this->store->assertLock($intent->paymentKey());
            $this->store->assertNativeConnection();
            return $this->native->validate($intent, false);
        } catch (\Throwable $error) {
            return $error->getMessage();
        }
    }

    public function inspect(string $operationId): NativeProjectionResult
    {
        $intent = $this->store->getIntent($operationId);
        $marker = $this->store->marker($operationId);
        $reason = $this->native->validate($intent, $marker !== null);
        if ($reason !== null) { return new NativeProjectionResult(NativeProjectionResult::AMBIGUOUS, $intent->mode(), null, null, null, null, null, $reason); }
        if ($marker === null) { return new NativeProjectionResult(NativeProjectionResult::ABSENT, $intent->mode()); }
        if ($marker['payment_key'] !== $intent->paymentKey()) {
            return new NativeProjectionResult(NativeProjectionResult::AMBIGUOUS, $intent->mode(), null, null, null, null, null, 'refund_marker_parent_conflict');
        }
        return NativeProjectionResult::fromStorage(json_decode($marker['result'], true, 512, JSON_THROW_ON_ERROR));
    }

    public function applyCompleted(RefundIntent $intent, VerifiedRefund $refund): NativeProjectionResult
    {
        $existing = $this->inspect($intent->operationId());
        if ($existing->kind() !== NativeProjectionResult::ABSENT) { return $existing; }
        if ($refund->status() !== 'completed' || $refund->withdrawalId() !== $intent->withdrawalId()
            || $refund->mode() !== $intent->mode()) {
            throw new \RuntimeException('refund_completion_unverified');
        }
        if ($intent->mode() === RefundStates::OVERPAYMENT) {
            $result = new NativeProjectionResult(NativeProjectionResult::EXACT_MATCH, $intent->mode(),
                'cash:' . $intent->operationId(), null, null, $refund->principalDecimal(), $refund->currency() . '/' . $refund->network());
        } else {
            $this->native->transition($intent, 'completed', Context::createDefaultContext());
            $draft = $intent->fullDraft();
            $result = new NativeProjectionResult(NativeProjectionResult::EXACT_MATCH, $intent->mode(),
                $draft['native_id'], $draft['amount_minor'], $draft['currency']);
        }
        $this->store->saveMarker($intent, $result);
        return $result;
    }

    public function reconcile(string $operationId): string
    {
        $intent = $this->store->getIntent($operationId);
        return $this->store->withPaymentLock($intent->paymentKey(), function () use ($operationId) {
            $intent = $this->store->getIntent($operationId);
            if ($this->hasLegacySyntheticCapture($intent)) {
                return $this->holdLegacySyntheticCapture($intent);
            }
            if ($intent->withdrawalId() === null && $intent->cashState() === RefundStates::UNKNOWN) {
                $this->assertConnection($intent->projectId(), $intent->environment(), false);
            }
            $action = $this->coordinator($intent)->reconcile($operationId);
            $this->syncNative($this->store->getIntent($operationId));
            $this->store->schedule($operationId);
            return $action;
        });
    }

    private function syncNative(RefundIntent $intent): void
    {
        if ($intent->mode() !== RefundStates::FULL) { return; }
        $state = $intent->cashState();
        $nativeState = null;
        if ($state === RefundStates::COMPLETED && $intent->projectionState() === RefundStates::PROJECTION_APPLIED) {
            $nativeState = 'completed';
        }
        if (in_array($state, [RefundStates::UNKNOWN, RefundStates::CREATED, RefundStates::PROCESSING], true)) {
            $nativeState = 'in_progress';
        } elseif ($intent->observation() !== null && in_array($state, [RefundStates::FAILED, RefundStates::CANCELLED], true)) {
            $nativeState = $state;
        }
        if ($intent->toArray()['review_reason'] === 'cash_rewound') { $nativeState = 'in_progress'; }
        if ($nativeState !== null) {
            $this->store->transaction(function () use ($intent, $nativeState) {
                $reason = $this->native->validate($intent, $this->store->marker($intent->operationId()) !== null);
                if ($reason !== null) {
                    $this->store->recordState($intent->operationId(), $intent->cashState(), RefundStates::NATIVE_REVIEW, $reason);
                    return;
                }
                $this->native->transition($intent, $nativeState, Context::createDefaultContext());
            });
        }
    }

    /** Called only after SDK signature verification and event reservation. */
    public function callback(array $payload, string $environment): void
    {
        $data = $payload['data'] ?? [];
        if (!is_array($data) || empty($data['invoice_id']) || empty($data['withdrawal_id'])) { return; }
        $intent = $this->store->findByWithdrawalId((string) $data['withdrawal_id']);
        if ($intent === null && isset($data['external_order_id']) && is_string($data['external_order_id'])) {
            $intent = $this->store->findByExternalId($data['external_order_id']);
        }
        if ($intent === null) {
            $this->observeUnbound((string) $data['withdrawal_id'], (string) $data['invoice_id'], $environment, $data);
            return;
        }
        if ($intent->environment() !== $environment || $intent->invoiceId() !== $data['invoice_id']
            || (isset($data['project_id']) && $intent->projectId() !== $data['project_id'])
            || (isset($data['mode']) && $intent->mode() !== $data['mode'])
            || (isset($data['refund_mode']) && $intent->mode() !== $data['refund_mode'])
            || (isset($data['external_order_id']) && $intent->externalOrderId() !== $data['external_order_id'])
            || (isset($data['is_test']) && $data['is_test'] !== ($environment === 'sandbox'))) {
            throw new \RuntimeException('refund_callback_scope_conflict');
        }
        $this->reconcile($intent->operationId());
    }

    private function observeUnbound(string $withdrawalId, string $invoiceId, string $environment, array $hint = []): void
    {
        $paymentKey = $this->store->paymentForInvoice($invoiceId);
        $this->store->withPaymentLock($paymentKey, function () use ($paymentKey, $withdrawalId, $invoiceId, $environment, $hint) {
            $payment = $this->native->payment($paymentKey);
            if ($payment['environment'] !== $environment || $payment['paymos_invoice_id'] !== $invoiceId
                || (isset($hint['project_id']) && $hint['project_id'] !== $payment['project_id'])
                || (isset($hint['is_test']) && $hint['is_test'] !== ($environment === 'sandbox'))) {
                throw new \RuntimeException('refund_callback_scope_conflict');
            }
            $client = $this->client($environment);
            $refund = VerifiedRefund::fromApi($client->refunds()->get($withdrawalId),
                $client->invoices()->get($invoiceId), new \DateTimeImmutable('now'));
            $this->store->assertLock($paymentKey);
            if ($refund->withdrawalId() !== $withdrawalId || $refund->invoiceId() !== $invoiceId
                || $refund->projectId() !== $payment['project_id'] || $refund->environment() !== $environment
                || (isset($hint['mode']) && $hint['mode'] !== $refund->mode())
                || (isset($hint['refund_mode']) && $hint['refund_mode'] !== $refund->mode())
                || (isset($hint['external_order_id']) && $hint['external_order_id'] !== $refund->externalOrderId())) {
                throw new \RuntimeException('refund_unbound_scope_conflict');
            }
            $this->store->saveUnbound($paymentKey, $refund);
        });
    }

    public function run(?\DateTimeImmutable $now = null): int
    {
        $page = $this->store->listDue($now ?: new \DateTimeImmutable('now'), 100, null);
        $count = 0;
        foreach ($page->items() as $intent) {
            try { $this->reconcile($intent->operationId()); $count++; }
            catch (\Throwable $error) {
                $this->store->withPaymentLock($intent->paymentKey(), function () use ($intent) { $this->store->schedule($intent->operationId()); });
            }
        }
        foreach ($this->store->unboundDue($now ?: new \DateTimeImmutable('now')) as $row) {
            $snapshot = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
            try { $this->observeUnbound($row['withdrawal_id'], $snapshot['invoice_id'], $snapshot['environment']); }
            catch (\Throwable $error) { /* Retain the observation for the next sweep. */ }
        }
        return $count;
    }

    public function operations(string $paymentKey): array
    {
        $this->native->payment($paymentKey);
        return array_map([$this, 'view'], $this->store->forPayment($paymentKey));
    }

    public function observations(string $paymentKey): array
    {
        $this->native->payment($paymentKey);
        return array_map(static function ($row) {
            return ['withdrawal_id' => $row['withdrawal_id'], 'observation' => json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR),
                'note' => $row['note'], 'review_reason' => $row['review_reason']];
        }, $this->store->unboundForPayment($paymentKey));
    }

    public function dispatchConfirmation(string $operationId, callable $send): void
    {
        $intent = $this->store->getIntent($operationId);
        if ($intent->mode() !== RefundStates::FULL) { return; }
        $this->store->withPaymentLock($intent->paymentKey(), function () use ($operationId, $send) {
            if ($this->store->claimEffect($operationId, 'confirmation', 1)->kind() !== \Paymos\Plugin\EffectClaim::CLAIMED) { return; }
            try {
                $sent = call_user_func($send, $this->store->getIntent($operationId));
                $this->store->recordEffectOutcome($operationId, 'confirmation', 1,
                    $sent === true ? RefundStates::EFFECT_DELIVERED : RefundStates::EFFECT_UNKNOWN);
            } catch (\Throwable $error) {
                $this->store->recordEffectOutcome($operationId, 'confirmation', 1, RefundStates::EFFECT_UNKNOWN);
            }
        });
    }

    public function summary(string $paymentKey): array
    {
        return $this->store->withPaymentLock($paymentKey, function () use ($paymentKey) {
            $row = $this->native->payment($paymentKey);
            $this->assertConnection($row['project_id'], $row['environment'], false);
            $invoice = $this->client($row['environment'])->invoices()->get($row['paymos_invoice_id']);
            $this->store->assertLock($paymentKey);
            if (($invoice['invoice_id'] ?? null) !== $row['paymos_invoice_id']
                || ($invoice['project_id'] ?? null) !== $row['project_id']
                || ($invoice['is_test'] ?? null) !== ($row['environment'] === 'sandbox')) {
                throw new \RuntimeException('refund_summary_scope_conflict');
            }
            return $invoice['refund_summary'] ?? [];
        });
    }

    public function view(RefundIntent $intent): array
    {
        $data = $intent->toArray();
        return ['operation_id' => $intent->operationId(), 'mode' => $intent->mode(), 'cash_state' => $intent->cashState(),
            'projection_state' => $intent->projectionState(), 'withdrawal_id' => $intent->withdrawalId(),
            'observation' => $data['observation'], 'projection' => $data['projection'], 'effects' => $data['effects'],
            'review_reason' => $data['review_reason'], 'message' => 'TODO-COPY'];
    }

    private function assertConnection(string $project, string $environment, bool $new): void
    {
        $values = $this->credentials->settings([])['environments'][$environment] ?? [];
        if (($values['project_id'] ?? null) !== $project || !CredentialSet::hasCapability($values, 'invoice_refunds_v1')) {
            throw new \RuntimeException('refund_reconnect_required');
        }
        if ($new && !CredentialSet::refundsEnabled($values)) { throw new \RuntimeException('refund_new_actions_disabled'); }
    }

    private function client(string $environment): Client
    {
        $settings = $this->credentials->settings([]);
        if ($this->clientFactory !== null) { return call_user_func($this->clientFactory, $environment); }
        return new Client(Config::fromSettings($settings)->clientConfigForEnvironment($environment));
    }

    private function coordinator(RefundIntent $intent): RefundCoordinator
    {
        return new RefundCoordinator($this->client($intent->environment()), $this->store, $this);
    }
}
