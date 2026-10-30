<?php

declare(strict_types=1);

require_once __DIR__ . '/stubs/refund.php';
require_once __DIR__ . '/stubs/refund-services.php';

use PaymosPayments\Service\CredentialStore;
use PaymosPayments\Service\RefundAdapter;
use PaymosPayments\Service\RefundIntentStore;
use PaymosPayments\Service\ShopwareRefundGateway;
use PaymosPayments\Service\ShopwareRefundGatewayInterface;
use Paymos\Plugin\RefundIntent;
use Shopware\Core\Framework\Context;

class SwRefundConfig extends \Shopware\Core\System\SystemConfig\SystemConfigService
{
    public $values = [];
    public function get($key, $salesChannelId = null) { return $this->values[$key] ?? null; }
    public function set($key, $value, $salesChannelId = null) { $this->values[$key] = $value; }
    public function delete($key, $salesChannelId = null) { unset($this->values[$key]); }
}

class SwRefundNative implements ShopwareRefundGatewayInterface
{
    public $db;
    public $partial = false;
    public $crash = false;
    public $ambiguous = false;
    public $capturePresent = true;
    public $row;

    public function __construct(SwRefundDb $db)
    {
        $this->db = $db;
        $this->row = ['payment_key' => str_repeat('a', 32), 'paymos_invoice_id' => 'inv_123',
            'project_id' => 'prj_123', 'environment' => 'sandbox', 'state' => 'paid', 'amount_minor' => '10000',
            'currency' => 'USD', 'amount' => '100.00', 'price' => '{"totalPrice":100}',
            'refund_price' => '{"totalPrice":100}', 'capture_price' => '{"totalPrice":100}',
            'native_id' => str_repeat('b', 32), 'capture_id' => str_repeat('c', 32)];
    }
    public function payment(string $paymentKey): array
    {
        if ($paymentKey !== $this->row['payment_key']) { throw new RuntimeException('mapping'); }
        return array_replace($this->row, ['state' => $this->db->native['state']]);
    }
    public function refund(string $refundId): array
    {
        if ($this->partial) { throw new RuntimeException('refund_full_original_total_required'); }
        return array_replace($this->payment($this->row['payment_key']), ['native_id' => $refundId]);
    }
    public function prepareFull(string $paymentKey, string $nativeId, array $invoice): array
    {
        assertSameValue(1, $this->db->level, 'Native refund draft uses a local transaction');
        assertSameValue('paid_over', $invoice['status'], 'Draft receives fresh paid invoice');
        if (!$this->capturePresent) { throw new RuntimeException('refund_capture_missing'); }
        return $this->refund($nativeId);
    }
    public function validate(RefundIntent $intent, bool $projected): ?string
    {
        if ($this->partial || $this->ambiguous) { return 'refund_native_snapshot_changed'; }
        if ($intent->mode() === 'full') {
            if ($intent->fullDraft()['amount_minor'] !== $this->row['amount_minor']) { return 'refund_native_snapshot_changed'; }
            $expected = $projected && $this->db->native['refund_state'] === 'completed' ? 'refunded' : 'paid';
            if ($this->db->native['state'] !== $expected) { return 'refund_manual_native_change'; }
        }
        return null;
    }
    public function transition(RefundIntent $intent, string $state, Context $context): void
    {
        assertSameValue(1, $this->db->level, 'Native state and marker share transaction');
        assertFalseValue($context->hasState(Context::SKIP_TRIGGER_FLOW), 'Caller context is not mutated');
        $this->db->native['refund_state'] = $state;
        if ($state === 'completed') {
            if ($this->db->native['documents'] === 0) { $this->db->native['documents']++; }
            $this->db->native['refunded'] = '10000';
            $this->db->native['state'] = 'refunded';
        } elseif ($this->db->native['state'] === 'refunded') {
            $this->db->native['state'] = 'paid';
            $this->db->native['refunded'] = '0';
        }
        if ($this->crash) { throw new RuntimeException('native write interrupted'); }
    }
}

class SwRefundTransport implements \Paymos\Http\TransportInterface
{
    public $store;
    public $db;
    public $requests = [];
    public $withdrawals = [];
    public $timeout = false;
    public $invoice;
    public $loseLock = false;
    public $failure = false;

    public function __construct(RefundIntentStore $store, SwRefundDb $db)
    {
        $this->store = $store;
        $this->db = $db;
        $this->invoice = ['invoice_id' => 'inv_123', 'project_id' => 'prj_123', 'is_test' => true,
            'status' => 'paid_over', 'order' => ['external_id' => '10001_0', 'amount' => '100', 'currency' => 'USD'],
            'refund_summary' => ['can_refund_full' => true, 'can_refund_overpayment' => true,
                'full_refund_amount' => '120', 'overpayment_refund_amount' => '20']];
    }
    public function request($method, $url, array $headers, $body, $timeoutSeconds)
    {
        assertSameValue(0, $this->db->level, 'HTTP outside database transaction');
        assertContainsValue('HMAC-SHA256', $headers['Authorization'] ?? '', 'SDK signs requests');
        $path = parse_url($url, PHP_URL_PATH);
        $this->requests[] = ['method' => $method, 'path' => $path, 'body' => $body];
        if ($method === 'POST') {
            $payload = json_decode($body, true);
            assertSameValue(['invoice_id', 'mode', 'external_order_id'], array_keys($payload), 'Mode-only contract');
            $intent = $this->store->findByExternalId($payload['external_order_id']);
            assertTrueValue($intent !== null, 'Durable intent before HTTP');
            assertSameValue('unknown', $intent->cashState(), 'Uncertainty committed before POST');
            assertTrueValue($this->db->commits >= 2, 'Separate intent/uncertainty commits');
            if ($this->failure) {
                return new \Paymos\Http\HttpResponse(400, json_encode(['code' => 'refund_no_overpayment', 'detail' => 'fixture']), []);
            }
            $id = 'wdr_' . substr(hash('sha256', $payload['external_order_id']), 0, 12);
            if (!isset($this->withdrawals[$id])) {
                $this->withdrawals[$id] = ['withdrawal_id' => $id, 'invoice_id' => $payload['invoice_id'],
                    'mode' => $payload['mode'], 'external_order_id' => $payload['external_order_id'],
                    'is_test' => true, 'project_id' => 'prj_123', 'currency' => 'USDT', 'network' => 'TRC20',
                    'first_sender' => 'sender-A', 'status' => 'created', 'amount' => $payload['mode'] === 'overpayment'
                        ? '20' : $this->invoice['refund_summary']['full_refund_amount']];
            }
            if ($this->timeout) { throw new RuntimeException('Lost response after accepted cash'); }
            $wire = ['withdrawal_id' => $id];
        } elseif (strpos($path, '/v1/invoices/') === 0) { $wire = $this->invoice; }
        else { $wire = $this->withdrawals[basename($path)]; }
        if ($this->loseLock) { $this->db->owned = false; }
        return new \Paymos\Http\HttpResponse(200, json_encode($wire), ['Cache-Control' => 'private, no-store']);
    }
}

function sw_refund_credentials(CredentialStore $credentials, array $override = []): void
{
    $credentials->saveCredentials(['sandbox' => array_replace(['api_key' => 'pk_test_key', 'api_secret' => 'sk_test_key',
        'project_id' => 'prj_123', 'webhook_secret' => 'whsec_sandbox', 'capabilities' => ['invoice_refunds_v1'],
        'refund_contract_version' => 1, 'refunds_enabled' => true], $override)]);
}

function sw_refunds(): array
{
    $db = new SwRefundDb();
    $clock = new DateTimeImmutable('2026-10-28T00:00:00Z');
    $store = new RefundIntentStore($db, static function () use ($clock) { return $clock; });
    $native = new SwRefundNative($db);
    $config = new SwRefundConfig();
    $credentials = new CredentialStore($config, 'fixture-kernel-secret');
    sw_refund_credentials($credentials);
    $http = new SwRefundTransport($store, $db);
    $client = new \Paymos\Client(new \Paymos\ClientConfig('pk_test_key', 'sk_test_key'), $http);
    $factory = static function ($environment) use ($client) { return $client; };
    $adapter = new RefundAdapter($store, $native, $credentials, $factory);
    return [$db, $store, $native, $credentials, $http, $adapter, $config, $factory];
}

function test_sw_ton_raw_pin_accepts_friendly_same_account_and_rejects_another()
{
    require_once __DIR__ . '/CmsTonPinContract.php';
    list($db, $store, $native) = sw_refunds();
    paymos_cms_ton_pin_contract($store, 'shopware6', $store->installationId(), $native->row['payment_key']);
}

function sw_refund_throws(callable $action, string $reason = ''): void
{
    try { $action(); } catch (\RuntimeException $error) {
        if ($reason !== '') { assertContainsValue($reason, $error->getMessage(), 'Refusal reason'); }
        return;
    } catch (\InvalidArgumentException $error) {
        if ($reason !== '') { assertContainsValue($reason, $error->getMessage(), 'Refusal reason'); }
        return;
    }
    throw new RuntimeException('Expected refusal');
}

function test_sw_refund_over20_then_full100_reorg_recompletion()
{
    list($db, $store, $native, $credentials, $http, $adapter) = sw_refunds();
    $over = $adapter->overpayment($native->row['payment_key'], 'request-over-0001');
    $id = $over['withdrawal_id'];
    $http->withdrawals[$id]['status'] = 'completed';
    assertSameValue('apply_completed', $adapter->reconcile($over['operation_id']), 'Overpayment completion');
    assertSameValue(1, count($db->tables['paymos_refund_projection']), 'One cash note');
    assertSameValue(['documents' => 0, 'refunded' => '0', 'stock' => 0, 'state' => 'paid', 'refund_state' => 'open', 'captures' => 0], $db->native, 'Overpayment has no native financial effects');
    $http->invoice['refund_summary']['full_refund_amount'] = '100';
    $full = $adapter->full($native->row['payment_key'], 'request-full-0001');
    assertSameValue('100', $full['observation']['amount'], 'Server computes remaining principal');
    $http->withdrawals[$id]['status'] = 'signed';
    $adapter->reconcile($over['operation_id']);
    assertSameValue('created', $store->getIntent($full['operation_id'])->cashState(), 'Old overpayment cannot overwrite full');
    $http->withdrawals[$full['withdrawal_id']]['status'] = 'completed';
    $adapter->reconcile($full['operation_id']);
    assertSameValue('10000', $db->native['refunded'], 'Full original native total');
    assertSameValue(1, $db->native['documents'], 'One full native record');
    $http->withdrawals[$full['withdrawal_id']]['status'] = 'signed';
    $adapter->reconcile($full['operation_id']);
    assertSameValue('in_progress', $db->native['refund_state'], 'Rewind uses same native refund');
    assertSameValue('paid', $db->native['state'], 'Aggregate payment projection follows current cash');
    assertSameValue('native_review', $store->getIntent($full['operation_id'])->projectionState(), 'Cash warning');
    $http->withdrawals[$full['withdrawal_id']]['status'] = 'completed';
    assertSameValue('resume_recovery', $adapter->reconcile($full['operation_id']), 'Exact marker recovery');
    assertSameValue('completed', $db->native['refund_state'], 'Same refund completes again');
    assertSameValue(1, $db->native['documents'], 'No second document');
    assertSameValue(0, $db->native['stock'], 'No inventory mutation');
    sw_refund_throws(static function () use ($adapter, $native) { $adapter->overpayment($native->row['payment_key'], 'request-over-0002'); });
}

function test_sw_refund_unknown_restart_same_key_no_automatic_new_refund()
{
    list($db, $store, $native, $credentials, $http, $adapter, $config, $factory) = sw_refunds();
    $http->timeout = true;
    $first = $adapter->nativeFull(str_repeat('b', 32), Context::createDefaultContext());
    assertSameValue('unknown', $first['cash_state'], 'Lost acknowledgement remains unknown');
    assertSameValue('in_progress', $db->native['refund_state'], 'Unknown does not fail core refund');
    sw_refund_throws(static function () use ($adapter, $native) { $adapter->overpayment($native->row['payment_key'], str_repeat('b', 32)); }, 'conflict');
    sw_refund_throws(static function () use ($adapter, $native) { $adapter->overpayment($native->row['payment_key'], 'request-over-0002'); });
    sw_refund_credentials($credentials, ['refunds_enabled' => false]);
    $http->timeout = false;
    $restarted = new RefundAdapter(new RefundIntentStore($db), $native, $credentials, $factory);
    $second = $restarted->nativeFull(str_repeat('b', 32), Context::createDefaultContext());
    assertSameValue($first['operation_id'], $second['operation_id'], 'Restart restores operation');
    assertSameValue(1, count($http->withdrawals), 'One provider withdrawal');
    $posts = array_values(array_filter($http->requests, static function ($r) { return $r['method'] === 'POST'; }));
    assertSameValue($posts[0]['body'], $posts[1]['body'], 'Byte-identical unknown replay');
}

function test_sw_refund_capability_and_native_guards_before_http()
{
    foreach (['capability', 'disabled', 'partial', 'manual', 'mapping', 'busy', 'outer'] as $case) {
        list($db, $store, $native, $credentials, $http, $adapter) = sw_refunds();
        if ($case === 'capability') { sw_refund_credentials($credentials, ['capabilities' => []]); }
        if ($case === 'disabled') { sw_refund_credentials($credentials, ['refunds_enabled' => false]); }
        if ($case === 'partial') { $native->partial = true; }
        if ($case === 'manual') { $db->native['state'] = 'refunded_partially'; }
        if ($case === 'mapping') { sw_refund_credentials($credentials, ['project_id' => 'prj_foreign']); }
        if ($case === 'busy') { $db->busy = true; }
        if ($case === 'outer') { $db->beginTransaction(); }
        sw_refund_throws(static function () use ($adapter) { $adapter->nativeFull(str_repeat('b', 32), Context::createDefaultContext()); });
        assertSameValue([], $http->requests, $case . ' refuses before HTTP');
    }
}

function test_sw_refund_connect_sdk_normalization_preserves_capability()
{
    list($db, $store, $native, $credentials, $http, $adapter, $config) = sw_refunds();
    $values = $credentials->settings([])['environments']['sandbox'];
    assertTrueValue(\Paymos\Plugin\CredentialSet::hasCapability($values, 'invoice_refunds_v1'), 'SDK capability survives encrypted storage');
    assertTrueValue(\Paymos\Plugin\CredentialSet::refundsEnabled($values), 'SDK new-action gate survives storage');
    assertTrueValue(strpos($config->values['PaymosPayments.config.credentialsEnvelope'], 'sk_test_key') === false, 'Opaque credentials envelope');
    sw_refund_credentials($credentials, ['capabilities' => []]);
    assertFalseValue($adapter->supports(), 'Old Connect declines refunds');
    sw_refund_throws(static function () use ($adapter, $native) { $adapter->summary($native->row['payment_key']); }, 'reconnect');
    assertSameValue([], $http->requests, 'No HTTP without capability');
}

function test_sw_refund_binding_and_verified_money_are_immutable()
{
    list($db, $store, $native, $credentials, $http, $adapter) = sw_refunds();
    $view = $adapter->overpayment($native->row['payment_key'], 'request-over-0001');
    $id = $view['withdrawal_id'];
    $http->withdrawals[$id]['status'] = 'completed';
    $http->withdrawals[$id]['first_sender'] = 'sender-B';
    assertSameValue('show_review', $adapter->reconcile($view['operation_id']), 'Pinned receiver cannot change');
    assertSameValue([], $db->tables['paymos_refund_projection'] ?? [], 'No projection after conflict');
    $store->withPaymentLock($native->row['payment_key'], static function () use ($store, $view, $id) {
        assertSameValue('same', $store->bindWithdrawal($view['operation_id'], $id), 'Same binding');
        assertSameValue('conflict', $store->bindWithdrawal($view['operation_id'], 'wdr_other'), 'Write-once binding');
        assertSameValue($id, $store->getIntent($view['operation_id'])->withdrawalId(), 'Original binding preserved');
    });
}

function test_sw_refund_native_crash_rollback_and_exact_marker_recovery()
{
    list($db, $store, $native, $credentials, $http, $adapter) = sw_refunds();
    $full = $adapter->nativeFull(str_repeat('b', 32), Context::createDefaultContext());
    $http->withdrawals[$full['withdrawal_id']]['status'] = 'completed';
    $native->crash = true;
    assertSameValue('show_review', $adapter->reconcile($full['operation_id']), 'Interrupted native transaction');
    assertSameValue(0, $db->native['documents'], 'Native write rolled back with marker');
    $native->crash = false;
    assertSameValue('apply_completed', $adapter->reconcile($full['operation_id']), 'Recovery after rollback');
    $store->withPaymentLock($native->row['payment_key'], static function () use ($store, $full) {
        $store->recordState($full['operation_id'], 'completed', 'native_review', 'native_projection_unknown');
    });
    assertSameValue('resume_recovery', $adapter->reconcile($full['operation_id']), 'Crash after commit recovers exact marker');
    assertSameValue(1, $db->native['documents'], 'One financial record');
}

function test_sw_refund_signed_scoped_callback_is_only_a_read_signal()
{
    list($db, $store, $native, $credentials, $http, $adapter) = sw_refunds();
    $over = $adapter->overpayment($native->row['payment_key'], 'request-over-0001');
    $processor = new \PaymosPayments\Service\WebhookProcessor(new FakeShopwareGateway(),
        new \PaymosPayments\Service\InMemoryInvoiceStore(), new \Paymos\Webhook\InMemoryEventStore(), null, $adapter);
    $event = ['event_id' => 'evt_refund', 'event_type' => 'withdrawal.completed', 'version' => 1,
        'occurred_at' => time(), 'data' => ['invoice_id' => 'inv_123', 'withdrawal_id' => $over['withdrawal_id'],
            'project_id' => 'prj_123', 'mode' => 'overpayment', 'is_test' => true, 'amount' => '999']];
    $body = json_encode($event);
    $settings = $credentials->settings([]);
    assertSameValue(401, $processor->handle($body, 'invalid', $settings)->statusCode(), 'Signature gate');
    $before = count($http->requests);
    assertSameValue(200, $processor->handle($body, sw_signed_header('whsec_sandbox', $body, time()), $settings)->statusCode(), 'Signed callback accepted');
    assertSameValue(2, count($http->requests) - $before, 'Fresh refund and invoice GET');
    assertSameValue([], $db->tables['paymos_refund_projection'] ?? [], 'Callback status does not override primary created');
    $event['event_id'] = 'evt_wrong_scope';
    $event['data']['project_id'] = 'prj_other';
    $body = json_encode($event);
    assertSameValue(400, $processor->handle($body, sw_signed_header('whsec_sandbox', $body, time()), $settings)->statusCode(), 'Cross-project rejected');
    $payout = json_encode(['event_id' => 'evt_payout', 'event_type' => 'withdrawal.completed', 'version' => 1, 'occurred_at' => time(), 'data' => []]);
    assertSameValue(200, $processor->handle($payout, sw_signed_header('whsec_sandbox', $payout, time()), $settings)->statusCode(), 'Ordinary payout ignored');
}

function test_sw_refund_native_decimal_conversion_does_not_round()
{
    assertSameValue('10000', ShopwareRefundGateway::minor('100.00', 2), 'Native total');
    assertSameValue('100000000000000000001', ShopwareRefundGateway::minor('1000000000000000000.01', 2), 'No float conversion');
    sw_refund_throws(static function () { ShopwareRefundGateway::minor('100.001', 2); }, 'precision');
}

function test_sw_refund_effect_claim_commits_before_send_and_never_auto_reclaims()
{
    list($db, $store, $native, $credentials, $http, $adapter) = sw_refunds();
    $full = $adapter->nativeFull(str_repeat('b', 32), Context::createDefaultContext());
    $http->withdrawals[$full['withdrawal_id']]['status'] = 'completed';
    $adapter->reconcile($full['operation_id']);
    $store->withPaymentLock($native->row['payment_key'], static function () use ($store, $full, $db) {
        assertSameValue('claimed', $store->claimEffect($full['operation_id'], 'confirmation', 1)->kind(), 'First attempt');
        assertSameValue(0, $db->level, 'Started committed before external effect');
        assertSameValue('already_started', $store->claimEffect($full['operation_id'], 'confirmation', 1)->kind(), 'Crash cannot resend');
        $store->recordEffectOutcome($full['operation_id'], 'confirmation', 1, 'unknown');
        assertSameValue('already_started', $store->claimEffect($full['operation_id'], 'confirmation', 1)->kind(), 'Unknown cannot resend');
    });
}

function test_sw_refund_backoff_due_limit_and_old_unresolved_review()
{
    list($db, $store, $native, $credentials, $http, $adapter) = sw_refunds();
    $http->timeout = true;
    $over = $adapter->overpayment($native->row['payment_key'], 'request-over-0001');
    $row = $db->tables['paymos_refund_intent'][0];
    assertSameValue(60, $row['next_due'] - $row['created_at'], 'Initial one-minute backoff');
    assertSameValue(0, count($store->listDue(new DateTimeImmutable('2026-10-28T00:00:00Z'), 100, null)->items()), 'Not due immediately');
    assertSameValue(1, count($store->listDue(new DateTimeImmutable('2026-10-29T00:00:00Z'), 100, null)->items()), 'Due operation included');
    sw_refund_throws(static function () use ($store) { $store->listDue(new DateTimeImmutable(), 100, 'invalid'); }, 'cursor');
    $db->tables['paymos_refund_intent'][0]['created_at'] -= 86401;
    $store->withPaymentLock($native->row['payment_key'], static function () use ($store, $over) { $store->schedule($over['operation_id']); });
    assertSameValue('refund_unresolved_24h', $store->getIntent($over['operation_id'])->toArray()['review_reason'], 'Unresolved operation older than 24 hours requires review');
}

function test_sw_refund_production_gateway_preflight_partial_history_and_scoped_context()
{
    $db = new SwNativeDb();
    $db->paymentRow = ['payment_key' => str_repeat('a', 32), 'amount' => '100.00', 'decimals' => '2',
        'currency' => 'USD', 'state' => 'paid', 'method' => 'paymos_crypto',
        'handler_identifier' => \PaymosPayments\Service\PaymosPaymentHandler::class,
        'paymos_invoice_id' => 'inv_123', 'project_id' => 'prj_123', 'environment' => 'sandbox',
        'invoice_amount' => '100', 'invoice_currency' => 'USD', 'invoice_status' => 'paid_over',
        'external_order_id' => '10001_0', 'price' => '{"totalPrice":100}'];
    $db->refundRow = ['native_id' => str_repeat('b', 32), 'payment_key' => str_repeat('a', 32),
        'capture_id' => str_repeat('c', 32), 'refund_amount' => '100.00', 'capture_amount' => '100.00',
        'capture_state' => 'completed', 'refund_price' => '{"totalPrice":100}', 'capture_price' => '{"totalPrice":100}'];
    $states = new SwNativeRefundStates($db);
    $paymentStates = new SwNativePaymentStates($db);
    $gateway = new ShopwareRefundGateway($db, new SwNativeRepository(), new SwNativeRepository(), $states, $paymentStates);
    $row = $gateway->refund(str_repeat('b', 32));
    $intent = new RefundIntent(['operation_id' => 'operation-full', 'request_token' => str_repeat('b', 32),
        'installation_id' => 'installation-fixture', 'platform' => 'shopware6', 'native_payment_key' => $row['payment_key'],
        'invoice_id' => 'inv_123', 'project_id' => 'prj_123', 'environment' => 'sandbox', 'mode' => 'full',
        'full_draft' => array_intersect_key($row, array_flip(['native_id', 'capture_id', 'amount_minor', 'currency', 'price', 'refund_price', 'capture_price']))]);
    assertSameValue(null, $gateway->validate($intent, false), 'Production full preflight');
    $db->refundRow['refund_amount'] = '10';
    sw_refund_throws(static function () use ($gateway) { $gateway->refund(str_repeat('b', 32)); }, 'full_original');
    $db->refundRow['refund_amount'] = '100';
    $db->others = 1;
    assertSameValue('refund_manual_or_partial_native_history', $gateway->validate($intent, false), 'Manual history rejected');
    $db->others = 0;
    $db->positions = 1;
    sw_refund_throws(static function () use ($gateway) { $gateway->refund(str_repeat('b', 32)); }, 'positions_require_review');
    $db->positions = 0;
    $context = Context::createDefaultContext();
    $gateway->transition($intent, 'in_progress', $context);
    $gateway->transition($intent, 'completed', $context);
    $gateway->transition($intent, 'in_progress', $context);
    $gateway->transition($intent, 'completed', $context);
    assertSameValue(['process', 'complete', 'reopen', 'process', 'complete'], array_column($states->calls, 0), 'Native reorg/recomplete preserves UUID');
    assertSameValue(['refunded', 'open', 'paid', 'refunded'], $paymentStates->calls, 'Aggregate tracks current completed total');
    assertFalseValue($context->hasState(Context::SKIP_TRIGGER_FLOW), 'Shared context is unchanged');
    assertTrueValue($states->calls[0][2] !== $context, 'Native state handler receives a clone');
    $before = count($states->calls);
    $over = $intent->toArray();
    $over['mode'] = 'overpayment';
    $over['full_draft'] = null;
    unset($over['payload_hash']);
    $gateway->transition(new RefundIntent($over), 'completed', $context);
    assertSameValue($before, count($states->calls), 'Overpayment never enters native full transition');
}

function test_sw_refund_both_handler_generations_use_adapter_and_leave_unknown_in_progress()
{
    foreach (['legacy', '67'] as $era) {
        list($db, $store, $native, $credentials, $http, $adapter) = sw_refunds();
        $http->timeout = true;
        $class = $era === 'legacy' ? \PaymosPayments\Service\PaymosPaymentHandler::class : \PaymosPayments\Service\PaymosPaymentHandler67::class;
        $handler = new $class(new \PaymosPayments\Service\CheckoutProcessor(new \PaymosPayments\Service\InMemoryInvoiceStore()),
            new FakeShopwareGateway(), new SwTestSystemConfig(), new SwTestLogger(), $credentials, $adapter);
        $context = Context::createDefaultContext();
        $argument = $era === 'legacy' ? str_repeat('b', 32) : new \Shopware\Core\Checkout\Payment\Cart\RefundPaymentTransactionStruct(str_repeat('b', 32));
        $handler->refund($argument, $context);
        assertSameValue('in_progress', $db->native['refund_state'], $era . ' unknown avoids core failure');
        assertSameValue(1, count($http->withdrawals), $era . ' calls common coordinator');
    }
}

function test_sw_refund_unbound_cash_observation_never_creates_provider_or_full_document()
{
    list($db, $store, $native, $credentials, $http, $adapter) = sw_refunds();
    $db->tables['paymos_payment_invoice'] = [['transaction_id' => $native->row['payment_key'], 'paymos_invoice_id' => 'inv_123']];
    $http->withdrawals['wdr_external'] = ['withdrawal_id' => 'wdr_external', 'invoice_id' => 'inv_123', 'mode' => 'overpayment',
        'external_order_id' => 'external-dashboard-key', 'project_id' => 'prj_123', 'is_test' => true, 'amount' => '20',
        'currency' => 'USDT', 'network' => 'TRC20', 'first_sender' => 'sender-A', 'status' => 'completed'];
    $payload = ['data' => ['withdrawal_id' => 'wdr_external', 'invoice_id' => 'inv_123']];
    $adapter->callback($payload, 'sandbox');
    $adapter->callback($payload, 'sandbox');
    $before = count($http->requests);
    sw_refund_throws(static function () use ($adapter, $payload) {
        $payload['data']['project_id'] = 'prj_foreign';
        $adapter->callback($payload, 'sandbox');
    }, 'scope');
    assertSameValue($before, count($http->requests), 'Unbound foreign project does not enter HTTP');
    assertSameValue(1, count($db->tables['paymos_refund_observation']), 'One unbound cash note');
    assertSameValue('TODO-COPY', $db->tables['paymos_refund_observation'][0]['note'], 'Overpayment note');
    assertSameValue('paid', $db->native['state'], 'Unbound overpayment leaves payment paid');
    assertSameValue(['GET', 'GET', 'GET', 'GET'], array_column($http->requests, 'method'), 'No cash POST for unbound event');
    $http->withdrawals['wdr_external']['status'] = 'signed';
    $adapter->callback($payload, 'sandbox');
    assertSameValue('TODO-COPY', $db->tables['paymos_refund_observation'][0]['note'], 'Reorg retains note history');
    sw_refund_throws(static function () use ($adapter, $native) { $adapter->overpayment($native->row['payment_key'], 'request-over-0001'); }, 'unbound');
    $http->withdrawals['wdr_external']['mode'] = 'full';
    sw_refund_throws(static function () use ($adapter, $payload) { $adapter->callback($payload, 'sandbox'); }, 'changed');
    $http->withdrawals['wdr_full'] = array_replace($http->withdrawals['wdr_external'], ['withdrawal_id' => 'wdr_full', 'status' => 'completed']);
    $adapter->callback(['data' => ['withdrawal_id' => 'wdr_full', 'invoice_id' => 'inv_123']], 'sandbox');
    assertSameValue(0, $db->native['documents'], 'Unbound full cannot invent native draft');
    assertSameValue('refund_unbound_full_requires_draft', $db->tables['paymos_refund_observation'][1]['review_reason'], 'Unbound full requires review');
}

function test_sw_refund_confirmation_lost_acknowledgement_is_not_resent()
{
    list($db, $store, $native, $credentials, $http, $adapter) = sw_refunds();
    $full = $adapter->nativeFull(str_repeat('b', 32), Context::createDefaultContext());
    $http->withdrawals[$full['withdrawal_id']]['status'] = 'completed';
    $adapter->reconcile($full['operation_id']);
    $calls = 0;
    $send = static function ($intent) use (&$calls, $db, $store) {
        assertSameValue(0, $db->level, 'Notifier runs outside native transaction');
        assertSameValue('started', $store->getIntent($intent->operationId())->toArray()['effects']['confirmation:1'], 'Started was committed before send');
        $calls++;
        throw new RuntimeException('SMTP accepted but acknowledgement lost');
    };
    $adapter->dispatchConfirmation($full['operation_id'], $send);
    $adapter->dispatchConfirmation($full['operation_id'], $send);
    assertSameValue(1, $calls, 'Only one notification attempt');
    assertSameValue('unknown', $store->getIntent($full['operation_id'])->toArray()['effects']['confirmation:1'], 'Unknown outcome persisted');
}

function test_sw_refund_controller_rejects_amount_and_requires_native_refund_acl()
{
    list($db, $store, $native, $credentials, $http, $adapter) = sw_refunds();
    $controller = new \PaymosPayments\Api\Controller\RefundController($adapter);
    $route = (new ReflectionClass($controller))->getAttributes(\Symfony\Component\Routing\Attribute\Route::class)[0]->getArguments();
    assertSameValue(['api'], $route['defaults']['_routeScope'], 'Admin API authentication');
    assertTrueValue(in_array('order_refund.editor', $route['defaults']['_acl'], true), 'Same permission as native refund API');
    foreach ([['request_token' => 'request-over-0001', 'amount' => '20'], ['request_token' => 'request-over-0001', 'address' => 'sender-B']] as $body) {
        $response = $controller->submit($native->row['payment_key'], 'overpayment',
            new \Symfony\Component\HttpFoundation\Request(json_encode($body)), Context::createDefaultContext());
        assertSameValue(409, $response->getStatusCode(), 'Reject arbitrary money/recipient fields');
    }
    assertSameValue([], $http->requests, 'Invalid input never enters HTTP');
}

function test_sw_refund_lost_lock_after_cash_cannot_fail_native_processor_or_project()
{
    list($db, $store, $native, $credentials, $http, $adapter) = sw_refunds();
    $http->loseLock = true;
    $result = $adapter->nativeFull(str_repeat('b', 32), Context::createDefaultContext());
    assertSameValue('unknown', $result['cash_state'], 'Accepted cash remains unknown if connection lock is lost');
    assertSameValue(1, count($http->withdrawals), 'One provider cash operation');
    assertSameValue(0, $db->native['documents'], 'Lost owner cannot project native state');
    assertSameValue([], $db->tables['paymos_refund_projection'] ?? [], 'Lost owner cannot write a marker');
}

function test_sw_refund_unknown_replay_needs_capability_but_completed_read_ignores_new_gate()
{
    list($db, $store, $native, $credentials, $http, $adapter) = sw_refunds();
    $http->timeout = true;
    $result = $adapter->overpayment($native->row['payment_key'], 'request-over-0001');
    $before = count($http->requests);
    sw_refund_credentials($credentials, ['capabilities' => []]);
    sw_refund_throws(static function () use ($adapter, $native) { $adapter->overpayment($native->row['payment_key'], 'request-over-0001'); }, 'reconnect');
    assertSameValue($before, count($http->requests), 'No refund HTTP without capability');
    sw_refund_credentials($credentials, ['refunds_enabled' => false]);
    $http->timeout = false;
    $adapter->reconcile($result['operation_id']);
    $intent = $store->getIntent($result['operation_id']);
    $http->withdrawals[$intent->withdrawalId()]['status'] = 'completed';
    assertSameValue('apply_completed', $adapter->reconcile($intent->operationId()), 'Disabled new gate does not stop accepted cash');
}

function test_sw_refund_native_uuid_keeps_panel_intent_after_definitive_failure()
{
    list($db, $store, $native, $credentials, $http, $adapter) = sw_refunds();
    $full = $adapter->full($native->row['payment_key'], 'request-full-0001');
    $http->withdrawals[$full['withdrawal_id']]['status'] = 'failed';
    $adapter->reconcile($full['operation_id']);
    $intent = $store->getIntent($full['operation_id']);
    $before = count($http->withdrawals);
    $nativeEntry = $adapter->nativeFull($intent->fullDraft()['native_id'], Context::createDefaultContext());
    assertSameValue($full['operation_id'], $nativeEntry['operation_id'], 'Core UUID finds existing panel operation');
    assertSameValue('failed', $nativeEntry['cash_state'], 'Old native UUID remains bound to old failed cash');
    assertSameValue($before, count($http->withdrawals), 'No new withdrawal from alternate entry');
}

function sw_legacy_intent_fixture(bool $bound): array
{
    list($db, $store, $native, $credentials, $http, $adapter) = sw_refunds();
    $payment = $native->row['payment_key'];
    $native->row['capture_id'] = substr(hash('sha256', 'paymos-capture:' . $payment), 0, 32);
    $draft = array_intersect_key($native->row, array_flip(['native_id', 'capture_id', 'amount_minor', 'currency', 'price', 'refund_price', 'capture_price']));
    $intent = $store->withPaymentLock($payment, static function () use ($store, $payment, $draft, $bound) {
        $intent = new RefundIntent(['operation_id' => RefundIntentStore::uuid(), 'request_token' => 'request-legacy-0001',
            'installation_id' => $store->installationId(), 'platform' => 'shopware6', 'native_payment_key' => $payment,
            'invoice_id' => 'inv_123', 'project_id' => 'prj_123', 'environment' => 'sandbox', 'mode' => 'full', 'full_draft' => $draft]);
        $store->createIntent($intent);
        $store->recordState($intent->operationId(), $bound ? 'created' : 'unknown', 'pending', null);
        if ($bound) { assertSameValue('bound', $store->bindWithdrawal($intent->operationId(), 'wdr_legacy'), 'Existing ID bound in fixture'); }
        return $store->getIntent($intent->operationId());
    });
    if ($bound) {
        $http->withdrawals['wdr_legacy'] = ['withdrawal_id' => 'wdr_legacy', 'invoice_id' => 'inv_123',
            'mode' => 'full', 'external_order_id' => $intent->externalOrderId(), 'is_test' => true, 'project_id' => 'prj_123',
            'currency' => 'USDT', 'network' => 'TRC20', 'first_sender' => 'sender-A', 'status' => 'completed', 'amount' => '120'];
    }
    return [$db, $store, $native, $http, $adapter, $intent];
}

function test_sw_legacy_synthetic_orphan_native_entry_does_not_post()
{
    list($db, $store, $native, $credentials, $http, $adapter) = sw_refunds();
    $native->row['capture_id'] = substr(hash('sha256', 'paymos-capture:' . $native->row['payment_key']), 0, 32);
    $before = $db->native;
    sw_refund_throws(static function () use ($adapter) { $adapter->nativeFull(str_repeat('b', 32), Context::createDefaultContext()); }, 'refund_capture_unverified');
    assertSameValue([], $http->requests, 'Orphan synthetic draft cannot initiate HTTP');
    assertSameValue([], $store->forPayment($native->row['payment_key']), 'No new intent from orphan');
    assertSameValue($before, $db->native, 'Existing native history remains untouched');
}

function test_sw_legacy_unknown_preserves_key_and_never_replays_post()
{
    list($db, $store, $native, $http, $adapter, $intent) = sw_legacy_intent_fixture(false);
    $before = $db->native;
    assertSameValue('show_review', $adapter->reconcile($intent->operationId()), 'Unbound legacy cash needs review');
    $current = $store->getIntent($intent->operationId());
    assertSameValue('unknown', $current->cashState(), 'Unknown cash state remains unknown');
    assertSameValue($intent->externalOrderId(), $current->externalOrderId(), 'Original idempotency key remains');
    assertSameValue('native_review', $current->projectionState(), 'Durable review recorded');
    assertSameValue('refund_capture_unverified', $current->toArray()['review_reason'], 'Known legacy capture reason');
    $adapter->full($native->row['payment_key'], 'request-legacy-0001');
    assertSameValue([], $http->requests, 'Reconcile and retry make no POST or external request');
    assertSameValue($before, $db->native, 'Unknown legacy operation does not change native state');
}

function test_sw_legacy_bound_get_never_projects_native_refund()
{
    list($db, $store, $native, $http, $adapter, $intent) = sw_legacy_intent_fixture(true);
    $before = $db->native;
    assertSameValue('show_review', $adapter->reconcile($intent->operationId()), 'Bound legacy cash remains review');
    $current = $store->getIntent($intent->operationId());
    assertSameValue('wdr_legacy', $current->withdrawalId(), 'Bound withdrawal is retained');
    assertSameValue('completed', $current->cashState(), 'Fresh GET can record terminal cash');
    assertSameValue('native_review', $current->projectionState(), 'Synthetic native projection remains held');
    assertSameValue($before, $db->native, 'No native mutation on bound GET');
    assertSameValue(['GET', 'GET'], array_column($http->requests, 'method'), 'Only independent refund and invoice GETs');
}
