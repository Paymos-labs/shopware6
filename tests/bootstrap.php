<?php

declare(strict_types=1);

define('PAYMOS_SW_PLUGIN_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('PAYMOS_SW_SRC_DIR', PAYMOS_SW_PLUGIN_DIR . 'PaymosPayments' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR);

spl_autoload_register(static function ($class) {
    // Plugin's own framework-agnostic classes (Service/*). Shopware-typed shell
    // classes (Plugin, PaymentHandler, Controller, Migration, Command) reference
    // Shopware/Symfony types that are absent on the test runner, so they are NOT
    // autoloaded here — the tests exercise the agnostic core only.
    $prefix = 'PaymosPayments\\Service\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $relative = substr($class, strlen($prefix));
        $path = PAYMOS_SW_SRC_DIR . 'Service' . DIRECTORY_SEPARATOR . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require $path;
        }
        return;
    }

    // Paymos PHP SDK — resolve from the monorepo sibling, an explicit env var,
    // or a vendored copy.
    $sdkPrefix = 'Paymos\\';
    if (strncmp($class, $sdkPrefix, strlen($sdkPrefix)) === 0) {
        $relative = substr($class, strlen($sdkPrefix));
        $candidates = array(
            PAYMOS_SW_PLUGIN_DIR . 'PaymosPayments/vendor/paymos/php-sdk/src/' . str_replace('\\', '/', $relative) . '.php',
            getenv('PAYMOS_SDK_SRC')
                ? rtrim(getenv('PAYMOS_SDK_SRC'), '/\\') . '/' . str_replace('\\', '/', $relative) . '.php'
                : null,
            dirname(rtrim(PAYMOS_SW_PLUGIN_DIR, '/\\')) . '/php-sdk/src/' . str_replace('\\', '/', $relative) . '.php',
        );
        foreach ($candidates as $candidate) {
            if ($candidate !== null && is_file($candidate)) {
                require $candidate;
                return;
            }
        }
    }
});

// ── Minimal Doctrine DBAL stub ───────────────────────────────────────────────
// EventStore and PaymosInvoiceStore type-hint Doctrine\DBAL\Connection, which is
// absent on the plain test runner. A tiny in-memory stand-in (FakeDbalConnection
// below) extends it so the production EventStore can be unit-tested without a DB.
// Only the slice EventStore uses is modelled: executeStatement / insert / update
// / delete.
if (!class_exists('Doctrine\\DBAL\\Connection')) {
    eval('namespace Doctrine\\DBAL; class Connection {}');
}

/**
 * In-memory fake of the slice of Doctrine DBAL Connection that EventStore uses.
 * Enforces the event_id PRIMARY KEY (a repeat insert throws, exactly as MySQL
 * would), and honours the GC delete (`expires_at < :now`), the keyed update
 * (extend a row's expiry), and the keyed delete (drop one in-flight row).
 */
final class FakeDbalConnection extends \Doctrine\DBAL\Connection
{
    /** @var array<string, array<string, mixed>> Rows keyed by event_id. */
    public $rows = array();

    public function __construct()
    {
        // Intentionally do NOT call parent::__construct — the stub has none and
        // we never open a real connection.
    }

    /**
     * @param array<string, mixed> $params
     */
    public function executeStatement($sql, array $params = array(), array $types = array())
    {
        // Only the GC delete is issued this way: DELETE ... WHERE expires_at < :now
        if (stripos($sql, 'DELETE') !== false && isset($params['now'])) {
            $now = (int) $params['now'];
            $deleted = 0;
            foreach ($this->rows as $id => $row) {
                if ((int) $row['expires_at'] < $now) {
                    unset($this->rows[$id]);
                    $deleted++;
                }
            }

            return $deleted;
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert($table, array $data, array $types = array())
    {
        $id = (string) $data['event_id'];
        if (isset($this->rows[$id])) {
            // Duplicate PRIMARY KEY — mirror MySQL/DBAL throwing.
            throw new \RuntimeException('Duplicate entry for key PRIMARY: ' . $id);
        }
        $this->rows[$id] = $data;

        return 1;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $criteria
     */
    public function update($table, array $data, array $criteria = array(), array $types = array())
    {
        $id = isset($criteria['event_id']) ? (string) $criteria['event_id'] : '';
        if ($id === '' || !isset($this->rows[$id])) {
            return 0;
        }
        $this->rows[$id] = array_merge($this->rows[$id], $data);

        return 1;
    }

    /**
     * @param array<string, mixed> $criteria
     */
    public function delete($table, array $criteria = array(), array $types = array())
    {
        $id = isset($criteria['event_id']) ? (string) $criteria['event_id'] : '';
        if ($id === '' || !isset($this->rows[$id])) {
            return 0;
        }
        unset($this->rows[$id]);

        return 1;
    }
}

function assertSameValue($expected, $actual, $message)
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertTrueValue($actual, $message)
{
    if ($actual !== true) {
        throw new RuntimeException($message . ' Expected true, got ' . var_export($actual, true));
    }
}

function assertFalseValue($actual, $message)
{
    if ($actual !== false) {
        throw new RuntimeException($message . ' Expected false, got ' . var_export($actual, true));
    }
}

function assertContainsValue($needle, $haystack, $message)
{
    if (strpos((string) $haystack, (string) $needle) === false) {
        throw new RuntimeException($message . ' Missing ' . var_export($needle, true) . ' in ' . var_export($haystack, true));
    }
}

/**
 * The admin settings slice the processors receive (mode + debug). Secrets live
 * in the connected credential set the tests write via Config::fromArray.
 */
function sw_settings(array $overrides = array())
{
    return array_merge(array(
        'mode' => 'sandbox',
        'debug_logging' => '0',
    ), $overrides);
}

/**
 * Inject credentials into the test-only Config seam.
 */
function sw_write_generated_config(array $overrides = array())
{
    PaymosPayments\Service\Config::useConfigForTests(sw_generated_config($overrides));
}

function sw_reset_test_state()
{
    if (class_exists('PaymosPayments\\Service\\Config')) {
        PaymosPayments\Service\Config::resetForTests();
    }
}

function sw_generated_config(array $overrides = array())
{
    return array_replace_recursive(array(
        'config_version' => 2,
        'environments' => array(
            'sandbox' => array(
                'base_url' => 'https://api.paymos.test',
                'api_key' => 'pk_test_123',
                'api_secret' => 'sk_test_123',
                'project_id' => 'prj_123',
                'webhook_secret' => 'whsec_sandbox',
            ),
        ),
    ), $overrides);
}

function sw_order(array $overrides = array())
{
    return array_merge(array(
        'transaction_id' => 'txn_1',
        'order_number' => '10001',
        'amount' => '100.00',
        'currency' => 'USD',
        'customer_id' => 'cust_77',
        'return_url' => 'https://shop.test/payment/finalize-transaction?_sw_payment_token=tok123',
    ), $overrides);
}

function sw_signed_header($secret, $body, $timestamp)
{
    return 't=' . (int) $timestamp . ',v1=' . hash_hmac('sha256', (string) $timestamp . '.' . (string) $body, (string) $secret);
}

function sw_invoice_event($eventId, $eventType, $status, array $overrides = array())
{
    return array_replace_recursive(array(
        'event_id' => $eventId,
        'event_type' => $eventType,
        'version' => 1,
        'occurred_at' => 1709000000,
        'data' => array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => $status,
            'is_test' => true,
            'order' => array(
                'external_id' => '10001_0',
                'amount' => '100.00',
                'currency' => 'USD',
            ),
        ),
    ), $overrides);
}

/**
 * Build a real Paymos\Client backed by a MockTransport that returns the given
 * GET /invoices/{id} body — used to exercise the reverse-verifier with the
 * server's trailing-zero trimming ("100.00" -> "100").
 */
function sw_reverse_client(array $invoiceResponse = array(), $timestamp = 1709000000)
{
    $invoiceResponse = $invoiceResponse ?: array(
        'invoice_id' => 'inv_123',
        'project_id' => 'prj_123',
        'status' => 'paid',
        'order' => array(
            'external_id' => '10001_0',
            // Server trims trailing zeros; snapshot is "100.00". Equal.
            'amount' => '100',
            'currency' => 'USD',
        ),
    );

    return new Paymos\Client(
        new Paymos\ClientConfig('pk_test_123', 'sk_test_123', 'https://api.paymos.test', 30),
        new Paymos\Http\MockTransport(array(
            new Paymos\Http\HttpResponse(200, json_encode($invoiceResponse), array()),
        )),
        static function () use ($timestamp) {
            return $timestamp;
        }
    );
}

/**
 * In-memory fake of the Shopware gateway: records state transitions so tests can
 * assert paid/cancelled/failed/reopen without booting Shopware.
 */
final class FakeShopwareGateway implements PaymosPayments\Service\ShopwareGatewayInterface
{
    /** @var array<string, string> Current technical state per transaction id. */
    public $states = array();

    /** @var array<int, array{action: string, transaction_id: string}> */
    public $transitions = array();

    /** @var array<int, array{message: string, context: array}> */
    public $logs = array();

    public function __construct(array $states = array())
    {
        $this->states = $states;
    }

    public function markPaid($transactionId)
    {
        // Shopware: open/in_progress -> paid is legal; paid -> paid is an
        // UnnecessaryTransition the state handler swallows as a no-op; from any
        // other terminal state (cancelled/failed) -> paid throws.
        $current = $this->transactionState($transactionId);
        if ($current === 'paid') {
            return;
        }
        $this->assertTransitionAllowed($current, 'paid', array('open', 'in_progress'));
        $this->record('paid', $transactionId);
        $this->states[(string) $transactionId] = 'paid';
    }

    public function markCancelled($transactionId)
    {
        $current = $this->transactionState($transactionId);
        if ($current === 'cancelled') {
            return;
        }
        $this->assertTransitionAllowed($current, 'cancelled', array('open', 'in_progress'));
        $this->record('cancelled', $transactionId);
        $this->states[(string) $transactionId] = 'cancelled';
    }

    public function markFailed($transactionId)
    {
        $current = $this->transactionState($transactionId);
        if ($current === 'failed') {
            return;
        }
        $this->assertTransitionAllowed($current, 'failed', array('open', 'in_progress'));
        $this->record('failed', $transactionId);
        $this->states[(string) $transactionId] = 'failed';
    }

    public function reopen($transactionId)
    {
        // reopen is legal from cancelled/failed/in_progress (and a no-op from open).
        $this->record('reopen', $transactionId);
        $this->states[(string) $transactionId] = 'open';
    }

    /**
     * Mirror Shopware's state machine: a transition with no path throws (the real
     * OrderTransactionStateHandler raises IllegalTransitionException). Without this
     * the fake would mask cross-terminal bugs (failed->cancel, cancelled->fail,
     * cancelled->paid) that 400 + retry-loop in production.
     *
     * @param array<int, string> $allowedFrom
     */
    private function assertTransitionAllowed($current, $target, array $allowedFrom)
    {
        if ($current !== '' && !in_array($current, $allowedFrom, true)) {
            throw new \RuntimeException(
                'Illegal Shopware transition: ' . $current . ' -> ' . $target
            );
        }
    }

    public function transactionState($transactionId)
    {
        return isset($this->states[(string) $transactionId]) ? (string) $this->states[(string) $transactionId] : 'open';
    }

    /** @var string Latest-pending transaction id the bridge resolution should return. */
    public $latestPendingTransactionId = '';

    public function findLatestPendingTransactionIdForCustomer($customerId, $paymentHandlerIdentifier)
    {
        return (string) $this->latestPendingTransactionId;
    }

    public function log($message, array $context = array())
    {
        $this->logs[] = array('message' => (string) $message, 'context' => $context);
    }

    private function record($action, $transactionId)
    {
        $this->transitions[] = array('action' => (string) $action, 'transaction_id' => (string) $transactionId);
    }
}

/**
 * Fake Invoices resource for the checkout test (records the payload sent to
 * invoices()->create and returns a canned create response).
 */
final class FakePaymosInvoices
{
    /** @var array<int, array<string, mixed>> */
    public $payloads = array();

    /** @var array<string, mixed> */
    private $createResponse;

    /** @var array<string, mixed> */
    private $getResponse;

    public function __construct(array $createResponse = array(), array $getResponse = array())
    {
        $this->createResponse = $createResponse ?: array(
            'invoice_id' => 'inv_123',
            'payment_url' => 'https://pay.paymos.test/inv_123',
            'status' => 'awaiting_client',
        );
        $this->getResponse = $getResponse;
    }

    public function create(array $payload)
    {
        $this->payloads[] = $payload;

        return $this->createResponse;
    }

    public function get($invoiceId)
    {
        return $this->getResponse;
    }
}

final class FakePaymosClient
{
    /** @var FakePaymosInvoices */
    public $invoices;

    public function __construct(FakePaymosInvoices $invoices = null)
    {
        $this->invoices = $invoices ?: new FakePaymosInvoices();
    }

    public function invoices()
    {
        return $this->invoices;
    }
}
