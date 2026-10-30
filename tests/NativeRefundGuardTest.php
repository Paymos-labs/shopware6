<?php

declare(strict_types=1);

use PaymosPayments\Service\NativeRefundGuard;
use PaymosPayments\Service\PaymosPaymentHandler;
use PaymosPayments\Service\PaymosPaymentHandler67;
use PaymosPayments\Service\RefundWriteScope;
use PaymosPayments\Service\ShopwareRefundGateway;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;

class SwGuardDb extends SwNativeDb
{
    public $entities = [];
    public $methods = [];
    public $invoices = [];
    public $states = [];

    public function key($id, $version) { return bin2hex($id) . ':' . bin2hex($version); }
    public function put($entity, array $row)
    {
        $this->entities[$entity][$this->key($row['id'], $row['version_id'] ?? '')] = $row;
    }
    public function fetchAssociative($sql, $bind = [])
    {
        if (preg_match('/^SELECT \* FROM `([a-z_]+)` WHERE id = \? AND version_id = \?$/D', $sql, $match)) {
            return $this->entities[$match[1]][$this->key($bind[0], $bind[1])] ?? false;
        }
        if ($sql === 'SELECT handler_identifier FROM payment_method WHERE id = ?') {
            return isset($this->methods[bin2hex($bind[0])]) ? ['handler_identifier' => $this->methods[bin2hex($bind[0])]] : false;
        }
        return parent::fetchAssociative($sql, $bind);
    }
    public function fetchOne($sql, $bind = [])
    {
        if ($sql === 'SELECT 1 FROM paymos_payment_invoice WHERE transaction_id = ? LIMIT 1') {
            return isset($this->invoices[$bind[0]]) ? 1 : false;
        }
        if ($sql === 'SELECT technical_name FROM state_machine_state WHERE id = ?') {
            return $this->states[bin2hex($bind[0])] ?? false;
        }
        if (strpos($sql, 'SELECT COUNT(*) FROM order_transaction_capture_refund WHERE id = UNHEX(?)') === 0) {
            return isset($this->entities['order_transaction_capture_refund'][$this->key(hex2bin($bind[0]), hex2bin($bind[1]))]) ? 1 : 0;
        }
        if (strpos($sql, 'SELECT LOWER(HEX(s.id))') === 0) { return array_search($bind[1], $this->states, true); }
        return parent::fetchOne($sql, $bind);
    }
}

class SwGuardDal
{
    public $db;
    public $guard;
    public $writes = 0;
    public $transitions = [];
    public function __construct($db, $guard) { $this->db = $db; $this->guard = $guard; }
    public function write(array $commands, Context $context): void
    {
        $this->guard->beforeWrite(new PreWriteValidationEvent(
            \Shopware\Core\Framework\DataAbstractionLayer\Write\WriteContext::createFromContext($context), $commands));
        foreach ($commands as $command) {
            $entity = $command->getEntityName();
            $row = array_merge($command->getPrimaryKey(), $command->getPayload());
            $old = $this->db->entities[$entity][$this->db->key($row['id'], $row['version_id'] ?? '')] ?? [];
            $this->db->put($entity, array_merge($old, $row));
            $this->writes++;
        }
    }
    public function transition($entity, $id, $state, Context $context): void
    {
        $stateId = hex2bin(array_search($state, $this->db->states, true));
        $this->write([new InsertCommand('state_machine_history', ['entity_name' => $entity,
            'referenced_id' => hex2bin($id), 'referenced_version_id' => hex2bin($context->getVersionId()),
            'to_state_id' => $stateId], ['id' => random_bytes(16)])], $context);
        $this->write([new UpdateCommand($entity, ['state_id' => $stateId],
            ['id' => hex2bin($id), 'version_id' => hex2bin($context->getVersionId())])], $context);
        $this->transitions[] = [$entity, $id, $state];
    }
}

class SwGuardRepository extends \Shopware\Core\Framework\DataAbstractionLayer\EntityRepository
{
    private $dal;
    private $entity;
    public function __construct($dal, $entity) { $this->dal = $dal; $this->entity = $entity; }
    public function create($data, $context)
    {
        $commands = [];
        foreach ($data as $row) {
            $payload = [];
            foreach ($row as $field => $value) {
                $column = strtolower(preg_replace('/[A-Z]/', '_$0', $field));
                $payload[$column] = $column === 'id' || substr($column, -3) === '_id' ? hex2bin($value) : $value;
            }
            $commands[] = new InsertCommand($this->entity, $payload,
                ['id' => $payload['id'], 'version_id' => $payload['version_id']]);
        }
        $this->dal->write($commands, $context);
    }
}

class SwGuardRefundStates extends SwNativeRefundStates
{
    public $dal;
    public $context;
    public function __construct($dal) { parent::__construct($dal->db); $this->dal = $dal; }
    public function process($id, $context)
    {
        $this->dal->transition('order_transaction_capture_refund', $id, 'in_progress', $context);
        parent::process($id, $context);
    }
    public function complete($id, $context)
    {
        $this->context = $context;
        $this->dal->transition('order_transaction_capture_refund', $id, 'completed', $context);
        parent::complete($id, $context);
    }
}

class SwGuardPaymentStates extends SwNativePaymentStates
{
    public $dal;
    public function __construct($dal) { parent::__construct($dal->db); $this->dal = $dal; }
    public function refund($id, $context)
    {
        $this->dal->transition('order_transaction', $id, 'refunded', $context);
        parent::refund($id, $context);
    }
}

function sw_guard_id($digit): string { return hex2bin(str_repeat($digit, 32)); }
function sw_guard_command($entity, $digit, array $payload, $insert = true, $version = null)
{
    $class = $insert ? InsertCommand::class : UpdateCommand::class;
    return new $class($entity, $payload, ['id' => sw_guard_id($digit), 'version_id' => $version ?: hex2bin(Defaults::LIVE_VERSION)]);
}
function sw_guard_fixture($handler = PaymosPaymentHandler::class, $invoiced = true): array
{
    $db = new SwGuardDb();
    $scope = new RefundWriteScope();
    $dal = new SwGuardDal($db, new NativeRefundGuard($db, $scope));
    $db->methods[bin2hex(sw_guard_id('1'))] = $handler;
    $db->methods[bin2hex(sw_guard_id('2'))] = 'OtherPaymentHandler';
    foreach (['3' => 'paid', '4' => 'refunded', '5' => 'refunded_partially', '6' => 'open',
        '7' => 'in_progress', '8' => 'completed', '9' => 'failed'] as $digit => $state) {
        $db->states[bin2hex(sw_guard_id((string) $digit))] = $state;
    }
    $version = hex2bin(Defaults::LIVE_VERSION);
    $db->put('order_transaction', ['id' => sw_guard_id('a'), 'version_id' => $version,
        'payment_method_id' => sw_guard_id('1'), 'state_id' => sw_guard_id('3')]);
    $db->put('order_transaction', ['id' => sw_guard_id('d'), 'version_id' => $version,
        'payment_method_id' => sw_guard_id('2'), 'state_id' => sw_guard_id('3')]);
    $db->put('order_transaction_capture', ['id' => sw_guard_id('c'), 'version_id' => $version,
        'order_transaction_id' => sw_guard_id('a'), 'order_transaction_version_id' => $version]);
    $db->put('order_transaction_capture_refund', ['id' => sw_guard_id('b'), 'version_id' => $version,
        'capture_id' => sw_guard_id('c'), 'capture_version_id' => $version, 'state_id' => sw_guard_id('6')]);
    $db->put('order_transaction_capture_refund_position', ['id' => sw_guard_id('e'), 'version_id' => $version,
        'refund_id' => sw_guard_id('b'), 'refund_version_id' => $version]);
    if ($invoiced) { $db->invoices[str_repeat('a', 32)] = true; }
    return [$db, $scope, $dal, Context::createDefaultContext()];
}
function sw_guard_rejected($dal, $action): void
{
    $before = $dal->db->entities;
    $writes = $dal->writes;
    $transitions = $dal->transitions;
    sw_refund_throws($action, 'refund_manual_or_partial_native_history');
    assertSameValue($before, $dal->db->entities, 'Rejected request leaves every native row unchanged');
    assertSameValue($writes, $dal->writes, 'Rejected request performs zero writes');
    assertSameValue($transitions, $dal->transitions, 'Rejected request performs zero transitions');
}

function test_sw_native_guard_rejects_refund_crud_and_related_rows_before_any_write()
{
    list($db, $scope, $dal, $context) = sw_guard_fixture();
    foreach ([
        sw_guard_command('order_transaction_capture_refund', 'f', ['capture_id' => sw_guard_id('c'), 'state_id' => sw_guard_id('6')]),
        sw_guard_command('order_transaction_capture_refund', 'f', ['capture_id' => sw_guard_id('c'), 'state_id' => sw_guard_id('8')]),
        sw_guard_command('order_transaction_capture_refund_position', 'f', ['refund_id' => sw_guard_id('b')]),
        sw_guard_command('order_transaction_capture_refund_position', 'e', ['amount' => '100'], false),
        sw_guard_command('order_transaction_capture', 'f', ['order_transaction_id' => sw_guard_id('a')]),
        sw_guard_command('order_transaction', 'a', ['state_id' => sw_guard_id('4')], false),
        sw_guard_command('order_transaction', 'a', ['state_id' => sw_guard_id('5')], false),
        sw_guard_command('order_transaction_capture_refund', 'b', ['state_id' => sw_guard_id('8')], false),
    ] as $command) {
        sw_guard_rejected($dal, static function () use ($dal, $context, $command) { $dal->write([$command], $context); });
    }
}

function test_sw_native_guard_stops_completed_refund_and_full_or_partial_payment_transitions()
{
    list($db, $scope, $dal, $context) = sw_guard_fixture();
    foreach ([['order_transaction_capture_refund', 'b', 'completed'],
        ['order_transaction', 'a', 'refunded'], ['order_transaction', 'a', 'refunded_partially']] as [$entity, $id, $state]) {
        sw_guard_rejected($dal, static function () use ($dal, $context, $entity, $id, $state) {
            $dal->transition($entity, str_repeat($id, 32), $state, $context);
        });
    }
}

function test_sw_native_guard_resolves_nested_sync_graph_and_rejects_whole_batch()
{
    list($db, $scope, $dal, $context) = sw_guard_fixture();
    $commands = [
        sw_guard_command('order_transaction_capture_refund_position', 'f', ['refund_id' => sw_guard_id('0')]),
        sw_guard_command('order_transaction_capture_refund', '0', ['capture_id' => sw_guard_id('e')]),
        sw_guard_command('order_transaction_capture', 'e', ['order_transaction_id' => sw_guard_id('a')]),
    ];
    sw_guard_rejected($dal, static function () use ($dal, $context, $commands) {
        $dal->write(array_merge([sw_guard_command('order_transaction', 'd', ['custom_fields' => '{}'], false)], $commands), $context);
    });
}

function test_sw_native_guard_owns_both_handler_generations_without_an_invoice_and_keeps_invoice_ownership()
{
    foreach ([PaymosPaymentHandler::class, PaymosPaymentHandler67::class, 'OtherPaymentHandler'] as $handler) {
        list($db, $scope, $dal, $context) = sw_guard_fixture($handler, $handler === 'OtherPaymentHandler');
        sw_guard_rejected($dal, static function () use ($dal, $context) {
            $dal->transition('order_transaction', str_repeat('a', 32), 'refunded', $context);
        });
        sw_guard_rejected($dal, static function () use ($dal, $context) {
            $dal->write([sw_guard_command('order_transaction_capture_refund', 'f', ['capture_id' => sw_guard_id('c')])], $context);
        });
    }
}

function test_sw_native_guard_cannot_reparent_refunds_or_hide_handler_in_same_sync()
{
    list($db, $scope, $dal, $context) = sw_guard_fixture(PaymosPaymentHandler::class, false);
    sw_guard_rejected($dal, static function () use ($dal, $context) {
        $dal->write([sw_guard_command('order_transaction', 'a', ['payment_method_id' => sw_guard_id('2')], false),
            sw_guard_command('order_transaction', 'a', ['state_id' => sw_guard_id('4')], false)], $context);
    });
    sw_guard_rejected($dal, static function () use ($dal, $context) {
        $dal->write([sw_guard_command('order_transaction_capture', 'c', ['order_transaction_id' => sw_guard_id('d')], false),
            sw_guard_command('order_transaction_capture_refund', 'f', ['capture_id' => sw_guard_id('c')])], $context);
    });
}

function test_sw_native_guard_non_paymos_crud_and_state_transitions_are_unchanged()
{
    list($db, $scope, $dal, $context) = sw_guard_fixture('OtherPaymentHandler', false);
    $dal->write([
        sw_guard_command('order_transaction_capture', '0', ['order_transaction_id' => sw_guard_id('a')]),
        sw_guard_command('order_transaction_capture_refund', 'f', ['capture_id' => sw_guard_id('0'), 'state_id' => sw_guard_id('6')]),
        sw_guard_command('order_transaction_capture_refund_position', '0', ['refund_id' => sw_guard_id('f')]),
    ], $context);
    $dal->transition('order_transaction_capture_refund', str_repeat('f', 32), 'completed', $context);
    $dal->transition('order_transaction', str_repeat('a', 32), 'refunded_partially', $context);
    $dal->transition('order_transaction', str_repeat('a', 32), 'refunded', $context);
    assertSameValue(9, $dal->writes, 'Other gateway retains capture, refund, position, history and payment writes');
    assertSameValue(3, count($dal->transitions), 'Other gateway retains every state transition');
}

function test_sw_native_guard_cannot_hide_a_refunded_write_behind_a_later_paid_write()
{
    list($db, $scope, $dal, $context) = sw_guard_fixture();
    sw_guard_rejected($dal, static function () use ($dal, $context) {
        $dal->write([sw_guard_command('order_transaction', 'a', ['state_id' => sw_guard_id('4')], false),
            sw_guard_command('order_transaction', 'a', ['state_id' => sw_guard_id('3')], false)], $context);
    });
    list($db, $scope, $dal, $context) = sw_guard_fixture(PaymosPaymentHandler::class, false);
    sw_guard_rejected($dal, static function () use ($dal, $context) {
        $dal->write([sw_guard_command('order_transaction', 'f',
            ['payment_method_id' => sw_guard_id('1'), 'state_id' => sw_guard_id('4')]),
            sw_guard_command('order_transaction', 'f', ['payment_method_id' => sw_guard_id('2')], false)], $context);
    });
    sw_guard_rejected($dal, static function () use ($dal, $context) {
        $dal->write([sw_guard_command('order_transaction', 'f',
            ['payment_method_id' => sw_guard_id('1'), 'state_id' => sw_guard_id('3')]),
            sw_guard_command('order_transaction', 'f',
                ['payment_method_id' => sw_guard_id('2'), 'state_id' => sw_guard_id('4')], false)], $context);
    });
}

function test_sw_native_guard_untrusted_context_and_copied_or_expired_adapter_context_are_rejected()
{
    list($db, $scope, $dal, $context) = sw_guard_fixture();
    $context->addState(Context::SKIP_TRIGGER_FLOW);
    $context->addState('paymos_refund');
    $refund = sw_guard_command('order_transaction_capture_refund', 'f', ['capture_id' => sw_guard_id('c')]);
    sw_guard_rejected($dal, static function () use ($dal, $context, $refund) { $dal->write([$refund], $context); });
    $saved = null;
    $scope->run($context, static function (Context $owned) use ($dal, $context, $refund, &$saved) {
        $saved = $owned;
        sw_guard_rejected($dal, static function () use ($dal, $context, $refund) { $dal->write([$refund], $context); });
        sw_guard_rejected($dal, static function () use ($dal, $owned, $refund) { $dal->write([$refund], clone $owned); });
        $dal->write([$refund], $owned);
    });
    assertSameValue(1, $dal->writes, 'Only exact active adapter context can write');
    sw_guard_rejected($dal, static function () use ($dal, $saved) {
        $dal->transition('order_transaction', str_repeat('a', 32), 'refunded', $saved);
    });
    sw_refund_throws(static function () use ($scope, $context, &$saved) {
        $scope->run($context, static function ($owned) use (&$saved) { $saved = $owned; throw new RuntimeException('interrupted'); });
    }, 'interrupted');
    assertFalseValue($scope->allows($saved), 'Exception revokes adapter authority');
}

function test_sw_native_guard_allows_core_processing_and_ordinary_paymos_paid_state()
{
    list($db, $scope, $dal, $context) = sw_guard_fixture();
    $dal->transition('order_transaction_capture_refund', str_repeat('b', 32), 'in_progress', $context);
    $dal->transition('order_transaction_capture_refund', str_repeat('b', 32), 'failed', $context);
    $dal->transition('order_transaction', str_repeat('a', 32), 'paid', $context);
    assertSameValue(3, count($dal->transitions), 'Core can enter handler and reconciliation can keep payment paid');
}

function test_sw_native_guard_follows_explicit_versions_instead_of_live_rows()
{
    list($db, $scope, $dal, $context) = sw_guard_fixture();
    $version = sw_guard_id('f');
    $db->put('order_transaction_capture', ['id' => sw_guard_id('0'), 'version_id' => $version,
        'order_transaction_id' => sw_guard_id('a'), 'order_transaction_version_id' => hex2bin(Defaults::LIVE_VERSION)]);
    sw_guard_rejected($dal, static function () use ($dal, $context, $version) {
        $dal->write([sw_guard_command('order_transaction_capture_refund', 'f',
            ['capture_id' => sw_guard_id('0'), 'capture_version_id' => $version])], $context);
    });
    $db->put('order_transaction_capture', ['id' => sw_guard_id('c'), 'version_id' => $version,
        'order_transaction_id' => sw_guard_id('d'), 'order_transaction_version_id' => hex2bin(Defaults::LIVE_VERSION)]);
    $dal->write([sw_guard_command('order_transaction_capture_refund', 'f',
        ['capture_id' => sw_guard_id('c'), 'capture_version_id' => $version])], $context);
    assertSameValue(1, $dal->writes, 'Other gateway in the referenced version is unaffected');
}

function test_sw_native_full_requires_existing_capture_before_any_write()
{
    list($db, $scope, $dal, $context) = sw_guard_fixture();
    unset($db->entities['order_transaction_capture'], $db->entities['order_transaction_capture_refund']);
    $db->paymentRow = ['payment_key' => str_repeat('a', 32), 'amount' => '100.00', 'decimals' => '2',
        'currency' => 'USD', 'state' => 'paid', 'method' => 'paymos_crypto',
        'handler_identifier' => PaymosPaymentHandler::class,
        'paymos_invoice_id' => 'inv_123', 'project_id' => 'prj_123', 'environment' => 'sandbox',
        'invoice_amount' => '100', 'invoice_currency' => 'USD', 'invoice_status' => 'paid',
        'external_order_id' => '10001_0', 'price' => '{"totalPrice":100}'];
    $gateway = new ShopwareRefundGateway($db, new SwGuardRepository($dal, 'order_transaction_capture'),
        new SwGuardRepository($dal, 'order_transaction_capture_refund'), new SwGuardRefundStates($dal),
        new SwGuardPaymentStates($dal), $scope);
    $before = $db->entities;
    sw_refund_throws(static function () use ($gateway) {
        $gateway->prepareFull(str_repeat('a', 32), str_repeat('b', 32), [
            'invoice_id' => 'inv_123', 'project_id' => 'prj_123', 'is_test' => true, 'status' => 'paid',
            'order' => ['external_id' => '10001_0', 'amount' => '100', 'currency' => 'USD']]);
    }, 'refund_capture_missing');
    assertSameValue($before, $db->entities, 'Missing capture leaves native history unchanged');
    assertSameValue(0, $dal->writes, 'Missing capture refuses before native write');
}

function test_sw_native_full_rejects_known_legacy_capture_before_new_write()
{
    list($db, $scope, $dal, $context) = sw_guard_fixture();
    $payment = str_repeat('a', 32);
    $legacy = substr(hash('sha256', 'paymos-capture:' . $payment), 0, 32);
    $db->captures = [['id' => $legacy]];
    $db->paymentRow = ['payment_key' => $payment, 'amount' => '100.00', 'decimals' => '2',
        'currency' => 'USD', 'state' => 'paid', 'method' => 'paymos_crypto', 'handler_identifier' => PaymosPaymentHandler::class,
        'paymos_invoice_id' => 'inv_123', 'project_id' => 'prj_123', 'environment' => 'sandbox',
        'invoice_amount' => '100', 'invoice_currency' => 'USD', 'invoice_status' => 'paid',
        'external_order_id' => '10001_0', 'price' => '{"totalPrice":100}'];
    $gateway = new ShopwareRefundGateway($db, new SwGuardRepository($dal, 'order_transaction_capture'),
        new SwGuardRepository($dal, 'order_transaction_capture_refund'), new SwGuardRefundStates($dal),
        new SwGuardPaymentStates($dal), $scope);
    $before = $db->entities;
    sw_refund_throws(static function () use ($gateway, $payment) {
        $gateway->prepareFull($payment, str_repeat('f', 32), ['invoice_id' => 'inv_123', 'project_id' => 'prj_123',
            'is_test' => true, 'status' => 'paid', 'order' => ['external_id' => '10001_0', 'amount' => '100', 'currency' => 'USD']]);
    }, 'refund_capture_unverified');
    assertSameValue($before, $db->entities, 'Legacy synthetic history is retained unchanged');
    assertSameValue(0, $dal->writes, 'Known synthetic capture cannot create a new draft');
}

function sw_guard_gateway_roundtrip($handlerClass)
{
    list($db, $scope, $dal, $context) = sw_guard_fixture($handlerClass);
    $db->paymentRow = ['payment_key' => str_repeat('a', 32), 'amount' => '100.00', 'decimals' => '2',
        'currency' => 'USD', 'state' => 'paid', 'method' => 'paymos_crypto', 'handler_identifier' => $handlerClass,
        'paymos_invoice_id' => 'inv_123', 'project_id' => 'prj_123', 'environment' => 'sandbox',
        'invoice_amount' => '100', 'invoice_currency' => 'USD', 'invoice_status' => 'paid',
        'external_order_id' => '10001_0', 'price' => '{"totalPrice":100}'];
    $db->refundRow = ['native_id' => str_repeat('b', 32), 'payment_key' => str_repeat('a', 32),
        'capture_id' => str_repeat('c', 32), 'refund_amount' => '100', 'capture_amount' => '100',
        'capture_state' => 'completed', 'refund_price' => '{"totalPrice":100}', 'capture_price' => '{"totalPrice":100}'];
    $states = new SwGuardRefundStates($dal);
    $gateway = new ShopwareRefundGateway($db, new SwGuardRepository($dal, 'order_transaction_capture'),
        new SwGuardRepository($dal, 'order_transaction_capture_refund'), $states, new SwGuardPaymentStates($dal), $scope);
    unset($db->entities['order_transaction_capture_refund']);
    $db->captures = [['id' => str_repeat('c', 32)]]; // existing native capture, not created by the gateway
    $row = $gateway->prepareFull(str_repeat('a', 32), str_repeat('b', 32), [
        'invoice_id' => 'inv_123', 'project_id' => 'prj_123', 'is_test' => true, 'status' => 'paid',
        'order' => ['external_id' => '10001_0', 'amount' => '100', 'currency' => 'USD']]);
    assertSameValue(1, $dal->writes, 'Adapter creates only the refund on an existing capture');
    $store = new \PaymosPayments\Service\RefundIntentStore($db, static function () {
        return new DateTimeImmutable('2026-10-28T00:00:00Z');
    });
    $credentials = new \PaymosPayments\Service\CredentialStore(new SwRefundConfig(), 'guard-kernel-secret');
    sw_refund_credentials($credentials);
    $http = new SwRefundTransport($store, $db);
    $http->invoice['status'] = 'paid';
    $http->invoice['refund_summary']['full_refund_amount'] = '100';
    $client = new \Paymos\Client(new \Paymos\ClientConfig('pk_test_key', 'sk_test_key'), $http);
    $adapter = new \PaymosPayments\Service\RefundAdapter($store, $gateway, $credentials,
        static function ($environment) use ($client) { return $client; });
    $handler = new $handlerClass(new \PaymosPayments\Service\CheckoutProcessor(new \PaymosPayments\Service\InMemoryInvoiceStore()),
        new FakeShopwareGateway(), new SwTestSystemConfig(), new SwTestLogger(), $credentials, $adapter);
    $argument = $handlerClass === PaymosPaymentHandler::class ? str_repeat('b', 32)
        : new \Shopware\Core\Checkout\Payment\Cart\RefundPaymentTransactionStruct(str_repeat('b', 32));
    $handler->refund($argument, $context);
    $intent = $store->findByNativeRefundId(str_repeat('b', 32));
    assertTrueValue($intent !== null && $intent->withdrawalId() !== null, 'Native handler calls Paymos with the guard installed');
    assertSameValue('in_progress', $db->native['refund_state'], 'Pending Paymos cash is not claimed completed');
    $http->withdrawals[$intent->withdrawalId()]['status'] = 'completed';
    assertSameValue(1, $adapter->run(new DateTimeImmutable('2026-10-29T00:00:00Z')), 'Scheduled reconciliation completes accepted cash');
    assertSameValue('completed', $db->native['refund_state'], 'Adapter completes native refund');
    assertSameValue('refunded', $db->native['state'], 'Adapter refunds aggregate transaction');
    assertSameValue(3, count($dal->transitions), 'Adapter processes, completes, and refunds');
    assertFalseValue($scope->allows($states->context), 'Native handler context expires on return');
    assertFalseValue($context->hasState(Context::SKIP_TRIGGER_FLOW), 'Original caller context remains unchanged');
    sw_guard_rejected($dal, static function () use ($dal, $context) {
        $dal->write([sw_guard_command('order_transaction_capture_refund', 'f', ['capture_id' => sw_guard_id('c')])], $context);
    });
}

function test_sw_native_guard_both_handlers_and_scheduled_reconciliation_keep_adapter_writes_allowed()
{
    sw_guard_gateway_roundtrip(PaymosPaymentHandler::class);
    sw_guard_gateway_roundtrip(PaymosPaymentHandler67::class);
}
