<?php

declare(strict_types=1);

use PaymosPayments\Service\CheckoutProcessor;
use PaymosPayments\Service\InMemoryInvoiceStore;

function test_sw_checkout_creates_invoice_and_snapshots_order()
{
    sw_write_generated_config();

    $store = new InMemoryInvoiceStore();
    $invoices = new FakePaymosInvoices();
    $processor = new CheckoutProcessor($store, static function () use ($invoices) {
        return new FakePaymosClient($invoices);
    });

    $result = $processor->start(sw_order(), sw_settings());

    assertSameValue('inv_123', $result['invoice_id'], 'Checkout returns the Paymos invoice id.');
    assertSameValue('https://pay.paymos.test/inv_123', $result['payment_url'], 'Checkout returns the hosted payment URL.');
    assertSameValue('0', $result['reused'], 'A fresh invoice is not reused.');

    // Payload contains only the allowed fields.
    $payload = $invoices->payloads[0];
    assertSameValue('prj_123', $payload['project_id'], 'Payload carries the project id.');
    assertSameValue('100', $payload['amount'], 'Payload amount is the canonical order amount.');
    assertSameValue('USD', $payload['currency'], 'Payload currency is the order currency.');
    assertSameValue('10001_0', $payload['external_order_id'], 'External order id is order-number + renew suffix.');
    assertSameValue('cust_77', $payload['client_id'], 'Client id is the native customer id.');
    assertFalseValue(array_key_exists('merchant_id', $payload), 'Merchant id is NEVER sent.');
    assertFalseValue(array_key_exists('ttl', $payload), 'No TTL/lifetime field is sent (server-side only).');
    assertFalseValue(array_key_exists('url', $payload), 'No return/webhook URL field is sent.');

    // Snapshot persisted for the webhook AmountGuard to compare against.
    $row = $store->findByTransactionId('txn_1');
    assertSameValue('100', (string) $row['amount'], 'Snapshot stores the order amount.');
    assertSameValue('USD', (string) $row['currency'], 'Snapshot stores the order currency.');
    assertSameValue('inv_123', (string) $row['paymos_invoice_id'], 'Snapshot stores the invoice id.');
    assertSameValue('10001_0', (string) $row['external_order_id'], 'Snapshot stores the external order id.');
    assertSameValue('sandbox', (string) $row['environment'], 'Snapshot stores the environment.');
}

function test_sw_checkout_reuses_invoice_when_snapshot_matches()
{
    sw_write_generated_config();

    $store = new InMemoryInvoiceStore();
    $invoices = new FakePaymosInvoices();
    $processor = new CheckoutProcessor($store, static function () use ($invoices) {
        return new FakePaymosClient($invoices);
    });

    $first = $processor->start(sw_order(), sw_settings());
    $second = $processor->start(sw_order(), sw_settings());

    assertSameValue('0', $first['reused'], 'First call creates an invoice.');
    assertSameValue('1', $second['reused'], 'Second call with the same snapshot reuses it.');
    assertSameValue(1, count($invoices->payloads), 'Reuse must not create a second invoice.');
    assertSameValue('inv_123', $second['invoice_id'], 'Reuse returns the same invoice id.');
}

function test_sw_checkout_version_bumps_external_id_when_amount_changes()
{
    sw_write_generated_config();

    $store = new InMemoryInvoiceStore();
    $invoices = new FakePaymosInvoices();
    $processor = new CheckoutProcessor($store, static function () use ($invoices) {
        return new FakePaymosClient($invoices);
    });

    $processor->start(sw_order(), sw_settings());
    $processor->start(sw_order(array('amount' => '150.00')), sw_settings());

    assertSameValue(2, count($invoices->payloads), 'A changed amount creates a fresh invoice.');
    assertSameValue('10001_0', $invoices->payloads[0]['external_order_id'], 'First external id has suffix 0.');
    assertSameValue('10001_1', $invoices->payloads[1]['external_order_id'], 'Changed order bumps the suffix to 1.');
    // BUG-166: the old invoice is cancelled on the server first, or the buyer
    // could pay both.
    assertSameValue(array('create', 'cancel inv_123', 'create'), $invoices->calls, 'the old invoice is cancelled before the new one is created.');
}

function test_sw_checkout_omits_client_id_for_guest()
{
    sw_write_generated_config();

    $store = new InMemoryInvoiceStore();
    $invoices = new FakePaymosInvoices();
    $processor = new CheckoutProcessor($store, static function () use ($invoices) {
        return new FakePaymosClient($invoices);
    });

    $processor->start(sw_order(array('customer_id' => '')), sw_settings());

    assertFalseValue(array_key_exists('client_id', $invoices->payloads[0]), 'Guest checkout omits client_id.');
}

function test_sw_checkout_amount_is_decimal_safe()
{
    sw_write_generated_config();

    $store = new InMemoryInvoiceStore();
    $invoices = new FakePaymosInvoices();
    $processor = new CheckoutProcessor($store, static function () use ($invoices) {
        return new FakePaymosClient($invoices);
    });

    // Float total from Shopware (getTotalPrice returns float) must serialise to
    // a clean dot-decimal string, never "100" with a lost cent or scientific
    // notation.
    $processor->start(sw_order(array('amount' => 100.1)), sw_settings());
    assertSameValue('100.1', $invoices->payloads[0]['amount'], 'Native float amount normalizes without forcing a scale.');
}

function test_sw_checkout_preserves_three_decimal_currency_and_renews_on_third_digit_change()
{
    sw_write_generated_config();
    $store = new InMemoryInvoiceStore();
    $invoices = new FakePaymosInvoices();
    $processor = new CheckoutProcessor($store, static function () use ($invoices) {
        return new FakePaymosClient($invoices);
    });

    $processor->start(sw_order(array('amount' => '10.001', 'currency' => 'BHD')), sw_settings());
    assertSameValue('10.001', $invoices->payloads[0]['amount'], 'BHD invoice must retain its third decimal digit.');
    assertSameValue('10.001', $store->findByTransactionId('txn_1')['amount'], 'BHD snapshot keeps the exact amount.');

    $same = $processor->start(sw_order(array('amount' => '10.0010', 'currency' => 'BHD')), sw_settings());
    assertSameValue('1', $same['reused'], 'Equivalent decimal spellings reuse the invoice.');
    $processor->start(sw_order(array('amount' => '10.002', 'currency' => 'BHD')), sw_settings());
    assertSameValue(2, count($invoices->payloads), 'Third digit change requires a new invoice.');
    assertSameValue('10.002', $invoices->payloads[1]['amount'], 'Renewed invoice keeps the changed exact amount.');
    assertSameValue('10001_1', $invoices->payloads[1]['external_order_id'], 'Third digit change bumps the external ID.');
}

function test_sw_checkout_rejects_missing_currency()
{
    sw_write_generated_config();

    $processor = new CheckoutProcessor(new InMemoryInvoiceStore(), static function () {
        return new FakePaymosClient();
    });

    $threw = false;
    try {
        $processor->start(sw_order(array('currency' => '')), sw_settings());
    } catch (\RuntimeException $e) {
        $threw = true;
    }

    assertTrueValue($threw, 'Missing currency must fail checkout.');
}

function test_sw_checkout_snapshots_return_url_but_never_sends_it_to_paymos()
{
    sw_write_generated_config();

    $store = new InMemoryInvoiceStore();
    $invoices = new FakePaymosInvoices();
    $processor = new CheckoutProcessor($store, static function () use ($invoices) {
        return new FakePaymosClient($invoices);
    });

    $returnUrl = 'https://shop.test/payment/finalize-transaction?_sw_payment_token=abc';
    $processor->start(sw_order(array('return_url' => $returnUrl)), sw_settings());

    // The Shopware return URL is snapshotted for the return bridge...
    $row = $store->findByTransactionId('txn_1');
    assertSameValue($returnUrl, (string) $row['return_url'], 'Snapshot stores the Shopware return URL.');

    // ...but is NEVER part of the Paymos create-invoice payload (no URL field).
    assertFalseValue(array_key_exists('return_url', $invoices->payloads[0]), 'return_url is not sent to Paymos.');
    assertFalseValue(array_key_exists('url', $invoices->payloads[0]), 'No URL field is sent to Paymos.');
    assertFalseValue(array_key_exists('success_url', $invoices->payloads[0]), 'No success_url is sent to Paymos.');
}

function test_sw_checkout_reuse_refreshes_return_url_for_retry()
{
    sw_write_generated_config();

    $store = new InMemoryInvoiceStore();
    $invoices = new FakePaymosInvoices();
    $processor = new CheckoutProcessor($store, static function () use ($invoices) {
        return new FakePaymosClient($invoices);
    });

    // First pay() snapshots the original Shopware return token.
    $processor->start(sw_order(array('return_url' => 'https://shop.test/finalize?_sw_payment_token=first')), sw_settings());

    // An afterOrderEnabled retry reuses the invoice (same amount) but Shopware
    // issues a FRESH return token; the bridge must get the latest one.
    $second = $processor->start(sw_order(array('return_url' => 'https://shop.test/finalize?_sw_payment_token=second')), sw_settings());

    assertSameValue('1', $second['reused'], 'A same-amount retry reuses the invoice.');
    assertSameValue(1, count($invoices->payloads), 'Reuse must not create a second invoice.');

    $row = $store->findByTransactionId('txn_1');
    assertSameValue(
        'https://shop.test/finalize?_sw_payment_token=second',
        (string) $row['return_url'],
        'Reuse refreshes the snapshot return URL to the latest Shopware token.'
    );
}

function test_sw_checkout_renews_an_invoice_that_expired_on_the_server()
{
    // BUG-090 (Shopware): an afterOrder payment retry after the Paymos
    // invoice's 30 minutes ran out. Same amount, same currency — the old link
    // leads to an expired checkout, so a new invoice is cut.
    sw_write_generated_config();

    $store = new InMemoryInvoiceStore();
    $invoices = new FakePaymosInvoices(array(), array(
        'invoice_id' => 'inv_123',
        'status' => 'awaiting_client',
        'expires_at' => time() - 3600,
    ));
    $processor = new CheckoutProcessor($store, static function () use ($invoices) {
        return new FakePaymosClient($invoices);
    });

    $processor->start(sw_order(), sw_settings());
    $second = $processor->start(sw_order(), sw_settings());

    assertSameValue('0', $second['reused'], 'an expired invoice must not be reused.');
    assertSameValue(2, count($invoices->payloads), 'a fresh invoice must be created.');
    assertSameValue('10001_1', $invoices->payloads[1]['external_order_id'], 'the fresh invoice needs a new external order id.');
    // Still awaiting_client on the server (its expiry job has not run): it is
    // cancelled before the replacement.
    assertSameValue(array('create', 'get inv_123', 'cancel inv_123', 'create'), $invoices->calls, 'read, cancel, create.');
}

function test_sw_checkout_renews_without_a_lookup_when_the_invoice_is_already_final()
{
    sw_write_generated_config();

    $store = new InMemoryInvoiceStore();
    $invoices = new FakePaymosInvoices();
    $processor = new CheckoutProcessor($store, static function () use ($invoices) {
        return new FakePaymosClient($invoices);
    });

    $processor->start(sw_order(), sw_settings());
    $store->updateStatus('inv_123', 'cancelled');
    $second = $processor->start(sw_order(), sw_settings());

    assertSameValue('0', $second['reused'], 'a cancelled invoice must not be reused.');
    assertSameValue('10001_1', $invoices->payloads[1]['external_order_id'], 'the fresh invoice needs a new external order id.');
    assertSameValue(array('create', 'create'), $invoices->calls, 'a recorded final status needs neither a read nor a cancel.');
}

function test_sw_checkout_keeps_an_invoice_the_server_holds_open_past_the_old_deadline()
{
    // BUG-163: confirming a network moves expires_at on the server and sends
    // no webhook. Only the server's answer decides; an open invoice is kept.
    foreach (array('awaiting_payment', 'confirming', 'underpaid_waiting') as $status) {
        sw_write_generated_config();

        $store = new InMemoryInvoiceStore();
        $invoices = new FakePaymosInvoices(array(), array(
            'invoice_id' => 'inv_123',
            'status' => $status,
            'is_final' => false,
            'expires_at' => time() - 3600,
        ));
        $processor = new CheckoutProcessor($store, static function () use ($invoices) {
            return new FakePaymosClient($invoices);
        });

        $processor->start(sw_order(), sw_settings());
        $second = $processor->start(sw_order(), sw_settings());

        assertSameValue('1', $second['reused'], $status . ': the open invoice is reused.');
        assertSameValue(1, count($invoices->payloads), $status . ': no second invoice is created.');
    }
}

function sw_start_expecting_block(CheckoutProcessor $processor, array $order)
{
    try {
        $processor->start($order, sw_settings());
    } catch (\Paymos\Plugin\InvoiceReplacementBlockedException $e) {
        return $e;
    }

    throw new RuntimeException('checkout must refuse to replace an invoice it cannot prove closed.');
}

function test_sw_checkout_does_not_replace_an_invoice_the_buyer_can_still_pay()
{
    // BUG-166: only awaiting_client is cancellable on the server. A network
    // picked, funds confirming, a part paid or a full payment keeps the old
    // invoice; no second one is cut.
    foreach (array('awaiting_payment', 'confirming', 'underpaid_waiting', 'paid') as $status) {
        sw_write_generated_config();

        $store = new InMemoryInvoiceStore();
        $invoices = new FakePaymosInvoices(array(), array('invoice_id' => 'inv_123', 'status' => $status));
        $processor = new CheckoutProcessor($store, static function () use ($invoices) {
            return new FakePaymosClient($invoices);
        });
        $processor->start(sw_order(), sw_settings());
        $invoices->cancelException = sw_api_error(409, 'invoice_cannot_be_cancelled', 'Invoice cannot be cancelled in status ' . $status . '.');

        $e = sw_start_expecting_block($processor, sw_order(array('amount' => '150.00')));

        assertSameValue($status, $e->result()->status(), $status . ': the blocking status is reported.');
        assertSameValue(array('create', 'cancel inv_123', 'get inv_123'), $invoices->calls, $status . ': cancel, read, and no second create.');
        assertSameValue('awaiting_client', (string) $store->findByTransactionId('txn_1')['status'], $status . ': an open or paid status read here is not recorded.');
        assertSameValue('10001_0', (string) $store->findByTransactionId('txn_1')['external_order_id'], $status . ': the old invoice stays the order\'s invoice.');
    }
}

function test_sw_checkout_does_not_replace_an_invoice_the_server_answers_404_for()
{
    sw_write_generated_config();

    $store = new InMemoryInvoiceStore();
    $invoices = new FakePaymosInvoices();
    $processor = new CheckoutProcessor($store, static function () use ($invoices) {
        return new FakePaymosClient($invoices);
    });
    $processor->start(sw_order(), sw_settings());
    $invoices->cancelException = sw_api_error(404, 'not_found', 'Invoice not found.');

    $e = sw_start_expecting_block($processor, sw_order(array('amount' => '150.00')));

    assertSameValue(\Paymos\Plugin\InvoiceReplacementResult::REASON_NOT_FOUND, $e->result()->reason(), 'the 404 is the reason.');
    assertSameValue(array('create', 'cancel inv_123'), $invoices->calls, 'no invoice is created after a 404.');
}

function test_sw_checkout_does_not_renew_when_the_lookup_answers_404()
{
    // Same amount; before BUG-166 a 404 on the read cut a new invoice.
    sw_write_generated_config();

    $store = new InMemoryInvoiceStore();
    $invoices = new FakePaymosInvoices();
    $processor = new CheckoutProcessor($store, static function () use ($invoices) {
        return new FakePaymosClient($invoices);
    });
    $processor->start(sw_order(), sw_settings());
    $invoices->getException = sw_api_error(404, 'not_found', 'Invoice not found.');
    $invoices->cancelException = sw_api_error(404, 'not_found', 'Invoice not found.');

    sw_start_expecting_block($processor, sw_order());

    assertSameValue(array('create', 'get inv_123', 'cancel inv_123'), $invoices->calls, 'read, cancel attempt, and no second create.');
}

function test_sw_checkout_cancels_the_old_invoice_in_its_own_environment()
{
    sw_write_generated_config(array('environments' => array('live' => array(
        'base_url' => 'https://api.paymos.test',
        'api_key' => 'pk_live_123',
        'api_secret' => 'sk_live_123',
        'project_id' => 'prj_live_123',
        'webhook_secret' => 'whsec_live',
    ))));

    $store = new InMemoryInvoiceStore();
    $sandbox = new FakePaymosInvoices();
    $live = new FakePaymosInvoices(array(
        'invoice_id' => 'inv_live',
        'payment_url' => 'https://pay.paymos.test/inv_live',
        'status' => 'awaiting_client',
    ));
    $processor = new CheckoutProcessor($store, static function ($config, $environment = null) use ($sandbox, $live) {
        $environment = $environment === null ? $config->environment() : $environment;

        return new FakePaymosClient($environment === 'live' ? $live : $sandbox);
    });
    $processor->start(sw_order(), sw_settings());

    $result = $processor->start(sw_order(), sw_settings(array('mode' => 'live')));

    assertSameValue('inv_live', $result['invoice_id'], 'the live invoice is issued.');
    assertSameValue(array('create', 'cancel inv_123'), $sandbox->calls, 'the sandbox invoice is cancelled with sandbox credentials.');
    assertSameValue(array('create'), $live->calls, 'only the create goes to live.');
}

/**
 * A47-01: an idempotent create replay after a lost first response returns an
 * invoice that is already paid on the server. The new snapshot must stay
 * applicable, so the verified webhook or the reconciler marks the Shopware
 * transaction paid exactly once.
 */
function test_sw_checkout_terminal_create_replay_is_applied_exactly_once()
{
    foreach (array('paid', 'paid_over') as $status) {
        foreach (array('callback', 'reconcile') as $route) {
            sw_write_generated_config();
            $store = new InMemoryInvoiceStore();
            $invoices = new FakePaymosInvoices(array(
                'invoice_id' => 'inv_123',
                'payment_url' => 'https://pay.paymos.test/inv_123',
                'status' => $status,
            ));
            (new CheckoutProcessor($store, static function () use ($invoices) {
                return new FakePaymosClient($invoices);
            }))->start(sw_order(), sw_settings());

            $row = $store->findByTransactionId('txn_1');
            assertSameValue('created', (string) $row['status'], $status . ': a terminal create response is not an applied native payment.');

            $gateway = new FakeShopwareGateway(array('txn_1' => 'open'));
            $live = array(
                'invoice_id' => 'inv_123',
                'project_id' => 'prj_123',
                'status' => $status,
                'is_test' => true,
                'order' => array('external_id' => '10001_0', 'amount' => '100', 'currency' => 'USD'),
            );
            $factory = static function () use ($live) { return sw_reverse_client($live, time()); };

            if ($route === 'callback') {
                $events = new \Paymos\Webhook\InMemoryEventStore();
                $processor = new \PaymosPayments\Service\WebhookProcessor($gateway, $store, $events, $factory);
                $body = json_encode(sw_invoice_event('evt_replayed_' . $status, 'invoice.' . $status, $status));
                $header = sw_signed_header('whsec_sandbox', $body, 1709000000);
                assertSameValue(200, $processor->handle($body, $header, sw_settings(), 1709000000)->statusCode(), $status . ': the verified event is applied.');
                assertSameValue(200, $processor->handle($body, $header, sw_settings(), 1709000000)->statusCode(), $status . ': the duplicate is acknowledged.');
            } else {
                $reconciler = new \PaymosPayments\Service\Reconciler($gateway, $store, $factory);
                assertSameValue(1, $reconciler->run(sw_settings(), time() + 7200), $status . ': the reconciler applies the payment.');
                assertSameValue(0, $reconciler->run(sw_settings(), time() + 7200), $status . ': an applied payment is not applied again.');
            }

            $paid = array_filter($gateway->transitions, static function ($t) { return $t['action'] === 'paid'; });
            assertSameValue(1, count($paid), $status . '/' . $route . ': exactly one native paid transition.');
            assertSameValue('paid', $gateway->states['txn_1'], $status . '/' . $route . ': the transaction is paid.');
            assertSameValue($status, (string) $store->findByTransactionId('txn_1')['status'], $status . '/' . $route . ': the snapshot is final only after the native apply.');
        }
    }
}

/**
 * A47-H01: Shopware's SetPaymentOrderRoute cancels the order's transaction and
 * creates a new one for the same order number when the buyer changes the
 * payment method after the order or the total changed. The new transaction
 * must not reuse the old external order id (a changed amount would be refused
 * as an idempotency conflict, the same amount would hand back and rebind the
 * old invoice); the old invoice is closed first and keeps its own binding.
 */
function test_sw_checkout_second_transaction_of_an_order_never_rebinds_the_first_invoice()
{
    foreach (array('100.00', '120.00') as $amount) {
        sw_write_generated_config();
        $store = new InMemoryInvoiceStore();
        $first = new FakePaymosInvoices();
        (new CheckoutProcessor($store, static function () use ($first) {
            return new FakePaymosClient($first);
        }))->start(sw_order(), sw_settings());

        $second = new FakePaymosInvoices(array(
            'invoice_id' => 'inv_456',
            'payment_url' => 'https://pay.paymos.test/inv_456',
            'status' => 'awaiting_client',
        ));
        $result = (new CheckoutProcessor($store, static function () use ($second) {
            return new FakePaymosClient($second);
        }))->start(sw_order(array('transaction_id' => 'txn_2', 'amount' => $amount)), sw_settings());

        assertSameValue(array('cancel inv_123', 'create'), $second->calls, $amount . ': the first transaction\'s invoice is closed before the next one.');
        assertSameValue('10001_1', $second->payloads[0]['external_order_id'], $amount . ': the new transaction gets the next key of the order.');
        assertSameValue('inv_456', $result['invoice_id'], $amount . ': the new transaction gets its own invoice.');
        $old = $store->findByExternalOrderId('10001_0');
        assertSameValue('txn_1', (string) $old['transaction_id'], $amount . ': the first invoice stays bound to its transaction.');
        assertSameValue('cancelled', (string) $old['status'], $amount . ': the first invoice records its close.');
        assertSameValue('10001_1', (string) $store->findByTransactionId('txn_2')['external_order_id'], $amount . ': the new row belongs to the new transaction.');
    }

    // The previous transaction's invoice can still be paid: nothing new is issued.
    sw_write_generated_config();
    $store = new InMemoryInvoiceStore();
    $first = new FakePaymosInvoices();
    (new CheckoutProcessor($store, static function () use ($first) {
        return new FakePaymosClient($first);
    }))->start(sw_order(), sw_settings());
    $second = new FakePaymosInvoices(array(), array('invoice_id' => 'inv_123', 'status' => 'awaiting_payment', 'is_final' => false));
    $second->cancelException = sw_api_error(409, 'invoice_cannot_be_cancelled', 'Only invoices awaiting client can be cancelled.');
    $blocked = false;
    try {
        (new CheckoutProcessor($store, static function () use ($second) {
            return new FakePaymosClient($second);
        }))->start(sw_order(array('transaction_id' => 'txn_2')), sw_settings());
    } catch (\Paymos\Plugin\InvoiceReplacementBlockedException $e) {
        $blocked = true;
    }
    assertTrueValue($blocked, 'a payable invoice of the previous transaction blocks the new one.');
    assertSameValue(0, count($second->payloads), 'no second invoice is created while the first can be paid.');
    assertSameValue(null, $store->findByTransactionId('txn_2'), 'the new transaction gets no binding.');
    assertSameValue('txn_1', (string) $store->findByExternalOrderId('10001_0')['transaction_id'], 'the first binding is unchanged.');
}

function test_sw_checkout_sends_single_product_and_omits_optional_fields()
{
    sw_write_generated_config();

    $store = new InMemoryInvoiceStore();
    $invoices = new FakePaymosInvoices();
    $processor = new CheckoutProcessor($store, static function () use ($invoices) {
        return new FakePaymosClient($invoices);
    });

    $processor->start(sw_order(array(
        'amount' => '47.00',
        'products' => array(
            array(
                'title' => '<b>Canvas</b> · Blue',
                'description' => '<p>Line 1</p><p>Line 2</p>',
                'quantity' => 2,
                'unit_amount' => '24.50',
            ),
        ),
    )), sw_settings());

    $payload = $invoices->payloads[0];
    assertFalseValue(array_key_exists('image_url', $payload['products'][0]), 'Product images must be absent.');
    assertSameValue('47', $payload['amount'], 'Native grand total must remain intact.');
    assertSameValue(
        array(
            array(
                'title' => 'Canvas · Blue',
                'quantity' => 2,
                'unit_amount' => '24.50',
                'description' => "Line 1\nLine 2",
            ),
        ),
        $payload['products'],
        'Single valid product is normalized and included.'
    );

    $minimalStore = new InMemoryInvoiceStore();
    $minimalInvoices = new FakePaymosInvoices();
    $minimalProcessor = new CheckoutProcessor($minimalStore, static function () use ($minimalInvoices) {
        return new FakePaymosClient($minimalInvoices);
    });
    $minimalProcessor->start(sw_order(array(
        'transaction_id' => 'txn_min',
        'order_number' => '10002',
        'amount' => '12.00',
        'products' => array(
            array(
                'title' => 'Minimal Item',
                'description' => '&nbsp;',
                'quantity' => '1',
                'unit_amount' => 12.0,
            ),
        ),
    )), sw_settings());

    assertSameValue(
        array(
            array(
                'title' => 'Minimal Item',
                'quantity' => 1,
                'unit_amount' => '12',
            ),
        ),
        $minimalInvoices->payloads[0]['products'],
        'Blank description is omitted without failing the product.'
    );
}

function test_sw_gateway_products_keep_quantity_price_and_variations_without_image_argument()
{
    sw_write_generated_config();
    $connection = new class {
        public $queries = array();

        public function fetchAssociative($sql, array $params)
        {
            $this->queries[] = $sql;
            return array(
                'order_number' => '10001', 'amount' => '{"totalPrice":47.5}',
                'currency' => 'USD', 'customer_id' => 'customer_1', 'sales_channel_id' => 'channel_1',
            );
        }

        public function fetchAllAssociative($sql, array $params)
        {
            $this->queries[] = $sql;
            return array(
                array(
                    'type' => 'product', 'parent_id' => null, 'label' => 'Canvas',
                    'description' => '<p>Giclée print</p>', 'quantity' => 2,
                    'price' => '{"unitPrice":"24.50"}',
                    'payload' => '{"options":[{"group":"Color","option":"Blue"}]}',
                ),
                array(
                    'type' => 'promotion', 'parent_id' => null, 'label' => 'Discount',
                    'quantity' => 1, 'unit_price' => -5,
                ),
                array(
                    'type' => 'product', 'parent_id' => 'parent_1', 'label' => 'Nested option',
                    'quantity' => 1, 'unit_price' => 0,
                ),
                array(
                    'type' => 'product', 'parent_id' => null, 'label' => 'Free Support',
                    'description' => null, 'quantity' => 1, 'unit_price' => '0.00', 'payload' => '{}',
                ),
            );
        }
    };
    // This public read uses only DBAL; state-transition dependencies are intentionally unused.
    $gatewayClass = new ReflectionClass(\PaymosPayments\Service\ShopwareGateway::class);
    $gateway = $gatewayClass->newInstanceWithoutConstructor();
    $connectionProperty = $gatewayClass->getProperty('connection');
    $connectionProperty->setValue($gateway, $connection);
    $context = $gateway->orderContext('txn_1');
    assertSameValue('47.5', $context['amount'], 'Gateway keeps the native order total.');

    $invoices = new FakePaymosInvoices();
    $processor = new CheckoutProcessor(new InMemoryInvoiceStore(), static function () use ($invoices) {
        return new FakePaymosClient($invoices);
    });
    $processor->start(sw_order($context), sw_settings());
    assertSameValue('47.5', $invoices->payloads[0]['amount'], 'Grand total is independent from product prices.');
    assertSameValue(
        array(
            array('title' => 'Canvas · Blue', 'quantity' => 2, 'unit_amount' => '24.50', 'description' => 'Giclée print'),
            array('title' => 'Free Support', 'quantity' => 1, 'unit_amount' => '0.00'),
        ),
        $invoices->payloads[0]['products'],
        'Gateway passes quantity, exact price and variation payload in the correct argument positions.'
    );
    foreach ($connection->queries as $query) {
        assertFalseValue(stripos($query, 'media') !== false || stripos($query, 'cover') !== false, 'Gateway does not query product images.');
    }
}

function test_sw_checkout_sends_cart_and_falls_back_all_or_nothing()
{
    sw_write_generated_config();

    $store = new InMemoryInvoiceStore();
    $invoices = new FakePaymosInvoices();
    $processor = new CheckoutProcessor($store, static function () use ($invoices) {
        return new FakePaymosClient($invoices);
    });

    $processor->start(sw_order(array(
        'amount' => '42.50',
        'products' => array(
            array(
                'title' => 'Canvas',
                'description' => 'Giclée',
                'quantity' => 2,
                'unit_amount' => 15.0,
            ),
            array(
                'title' => 'Notebook',
                'description' => '',
                'quantity' => 1,
                'unit_amount' => 12.5,
            ),
        ),
    )), sw_settings());

    $payload = $invoices->payloads[0];
    assertSameValue('42.5', $payload['amount'], 'Grand total stays 42.5.');
    assertSameValue(
        array(
            array(
                'title' => 'Canvas',
                'quantity' => 2,
                'unit_amount' => '15',
                'description' => 'Giclée',
            ),
            array(
                'title' => 'Notebook',
                'quantity' => 1,
                'unit_amount' => '12.5',
            ),
        ),
        $payload['products'],
        'Multi-item cart preserves all valid products.'
    );

    $fifty = array();
    for ($i = 1; $i <= 50; $i++) {
        $fifty[] = array('title' => 'Item ' . $i, 'quantity' => 1, 'unit_amount' => '1.00');
    }
    $fiftyInvoices = new FakePaymosInvoices();
    (new CheckoutProcessor(new InMemoryInvoiceStore(), static function () use ($fiftyInvoices) {
        return new FakePaymosClient($fiftyInvoices);
    }))->start(sw_order(array('transaction_id' => 'txn_50', 'order_number' => '10050', 'amount' => '50.00', 'products' => $fifty)), sw_settings());
    assertSameValue(50, count($fiftyInvoices->payloads[0]['products']), 'Exactly 50 products must be sent.');

    $fiftyOne = $fifty;
    $fiftyOne[] = array('title' => 'Item 51', 'quantity' => 1, 'unit_amount' => '1.00');

    $fallbackCases = array(
        'empty' => array(),
        'over_50' => $fiftyOne,
        'fractional_qty' => array(
            array('title' => 'Shirt', 'quantity' => 1, 'unit_amount' => '20.00'),
            array('title' => 'Fabric', 'quantity' => 1.5, 'unit_amount' => '10.00'),
        ),
        'empty_title' => array(
            array('title' => 'Shirt', 'quantity' => 1, 'unit_amount' => '20.00'),
            array('title' => '   ', 'quantity' => 1, 'unit_amount' => '10.00'),
        ),
        'negative_price' => array(
            array('title' => 'Shirt', 'quantity' => 1, 'unit_amount' => '20.00'),
            array('title' => 'Discount', 'quantity' => 1, 'unit_amount' => -5.0),
        ),
    );

    foreach ($fallbackCases as $label => $badProducts) {
        $fbInvoices = new FakePaymosInvoices();
        (new CheckoutProcessor(new InMemoryInvoiceStore(), static function () use ($fbInvoices) {
            return new FakePaymosClient($fbInvoices);
        }))->start(sw_order(array('transaction_id' => 'txn_fb_' . $label, 'order_number' => '109' . $label, 'amount' => '30.00', 'products' => $badProducts)), sw_settings());

        assertFalseValue(array_key_exists('products', $fbInvoices->payloads[0]), $label . ': falls back all-or-nothing to amount-only.');
        assertSameValue('30', $fbInvoices->payloads[0]['amount'], $label . ': grand total remains intact.');
    }
}

function test_sw_checkout_retry_ignores_presentation_metadata_differences()
{
    sw_write_generated_config();

    $store = new InMemoryInvoiceStore();
    $invoices = new FakePaymosInvoices();
    $processor = new CheckoutProcessor($store, static function () use ($invoices) {
        return new FakePaymosClient($invoices);
    });

    $first = $processor->start(sw_order(array(
        'products' => array(
            array(
                'title' => 'Studio Pro',
                'description' => 'Initial description',
                'quantity' => 2,
                'unit_amount' => '50.00',
            ),
        ),
    )), sw_settings());

    $second = $processor->start(sw_order(array(
        'products' => array(
            array(
                'title' => 'Studio Pro Changed',
                'description' => 'Updated description',
                'quantity' => 1,
                'unit_amount' => '100.00',
            ),
        ),
    )), sw_settings());

    assertSameValue('0', $first['reused'], 'First call creates the invoice.');
    assertSameValue('1', $second['reused'], 'Changed presentation metadata with same money reuses the invoice.');
    assertSameValue(array('create', 'get inv_123'), $invoices->calls, 'No cancel or second create on presentation change.');
    assertSameValue(1, count($invoices->payloads), 'Only one invoice create request is made.');

    // Terminal replay with products[] still leaves snapshot applicable for webhook/reconciler.
    $replayStore = new InMemoryInvoiceStore();
    $replayInvoices = new FakePaymosInvoices(array(
        'invoice_id' => 'inv_123',
        'payment_url' => 'https://pay.paymos.test/inv_123',
        'status' => 'paid',
    ));
    (new CheckoutProcessor($replayStore, static function () use ($replayInvoices) {
        return new FakePaymosClient($replayInvoices);
    }))->start(sw_order(array(
        'products' => array(
            array('title' => 'Studio Pro', 'quantity' => 2, 'unit_amount' => '50.00'),
        ),
    )), sw_settings());

    $gateway = new FakeShopwareGateway(array('txn_1' => 'open'));
    $events = new \Paymos\Webhook\InMemoryEventStore();
    $live = array(
        'invoice_id' => 'inv_123',
        'project_id' => 'prj_123',
        'status' => 'paid',
        'is_test' => true,
        'order' => array('external_id' => '10001_0', 'amount' => '100', 'currency' => 'USD'),
    );
    $webhook = new \PaymosPayments\Service\WebhookProcessor($gateway, $replayStore, $events, static function () use ($live) {
        return sw_reverse_client($live, time());
    });
    $body = json_encode(sw_invoice_event('evt_products_replay', 'invoice.paid', 'paid'));
    $sig = sw_signed_header('whsec_sandbox', $body, 1709000000);
    assertSameValue(200, $webhook->handle($body, $sig, sw_settings(), 1709000000)->statusCode(), 'Webhook marks paid after lost create with products[].');
    assertSameValue('paid', $gateway->states['txn_1'], 'Transaction becomes paid.');
}

function test_sw_gateway_filters_live_version_strictly_when_draft_order_exists()
{
    // BUG-1435: Shopware 6.7 order edits create draft versions with the same order_transaction id.
    $liveVersion = \Shopware\Core\Defaults::LIVE_VERSION;
    $draftVersion = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    $connection = new class ($liveVersion, $draftVersion) {
        public $liveVersion;
        public $draftVersion;
        public $calls = array();

        public function __construct(string $liveVersion, string $draftVersion)
        {
            $this->liveVersion = $liveVersion;
            $this->draftVersion = $draftVersion;
        }

        public function fetchOne($sql, array $params = array())
        {
            $this->calls[] = array('method' => 'fetchOne', 'sql' => $sql, 'params' => $params);
            $rows = array(
                array('version_id' => $this->draftVersion, 'state' => 'cancelled'),
                array('version_id' => $this->liveVersion, 'state' => 'open'),
            );
            $filterLive = stripos($sql, '`txn`.`version_id` = UNHEX(:liveVersion)') !== false
                && ($params['liveVersion'] ?? null) === $this->liveVersion;
            foreach ($rows as $row) {
                if (!$filterLive || $row['version_id'] === $this->liveVersion) {
                    return $row['state'];
                }
            }
            return false;
        }

        public function fetchAssociative($sql, array $params = array())
        {
            $this->calls[] = array('method' => 'fetchAssociative', 'sql' => $sql, 'params' => $params);
            $rows = array(
                array(
                    'version_id' => $this->draftVersion,
                    'order_number' => '10001',
                    'amount' => '{"totalPrice":999.99}',
                    'currency' => 'EUR',
                    'customer_id' => 'draft_customer',
                    'sales_channel_id' => 'channel_1',
                ),
                array(
                    'version_id' => $this->liveVersion,
                    'order_number' => '10001',
                    'amount' => '{"totalPrice":47.5}',
                    'currency' => 'USD',
                    'customer_id' => 'live_customer',
                    'sales_channel_id' => 'channel_1',
                ),
            );
            $filterLive = stripos($sql, '`txn`.`version_id` = UNHEX(:liveVersion)') !== false
                && ($params['liveVersion'] ?? null) === $this->liveVersion;
            foreach ($rows as $row) {
                if (!$filterLive || $row['version_id'] === $this->liveVersion) {
                    return $row;
                }
            }
            return false;
        }

        public function fetchAllAssociative($sql, array $params = array())
        {
            $this->calls[] = array('method' => 'fetchAllAssociative', 'sql' => $sql, 'params' => $params);
            $rows = array(
                array(
                    'txn_version_id' => $this->draftVersion,
                    'li_version_id' => $this->draftVersion,
                    'type' => 'product',
                    'parent_id' => null,
                    'label' => 'Draft Item',
                    'description' => null,
                    'quantity' => 5,
                    'price' => '{"unitPrice":"199.99"}',
                    'payload' => '{}',
                ),
                array(
                    'txn_version_id' => $this->liveVersion,
                    'li_version_id' => $this->liveVersion,
                    'type' => 'product',
                    'parent_id' => null,
                    'label' => 'Live Item',
                    'description' => 'Live description',
                    'quantity' => 2,
                    'price' => '{"unitPrice":"23.75"}',
                    'payload' => '{}',
                ),
            );
            // Native MySQL prepared statements (PDO::ATTR_EMULATE_PREPARES = false) throw HY093 if any named
            // placeholder appears more than once in the same SQL statement.
            preg_match_all('/:[a-zA-Z_][a-zA-Z0-9_]*/', $sql, $placeholders);
            assertSameValue(
                count($placeholders[0]),
                count(array_unique($placeholders[0])),
                'orderProducts SQL must not repeat named placeholders (breaks native MySQL prepared statements).'
            );
            $filterTxn = stripos($sql, '`txn`.`version_id` = UNHEX(:liveVersion)') !== false
                && ($params['liveVersion'] ?? null) === $this->liveVersion;
            $filterLi = stripos($sql, '`li`.`version_id` = `txn`.`version_id`') !== false;
            $out = array();
            foreach ($rows as $row) {
                if ($filterTxn && $row['txn_version_id'] !== $this->liveVersion) {
                    continue;
                }
                if ($filterLi && $row['li_version_id'] !== $row['txn_version_id']) {
                    continue;
                }
                $out[] = $row;
            }
            return $out;
        }
    };

    $gatewayClass = new ReflectionClass(\PaymosPayments\Service\ShopwareGateway::class);
    $gateway = $gatewayClass->newInstanceWithoutConstructor();
    $connectionProperty = $gatewayClass->getProperty('connection');
    $connectionProperty->setValue($gateway, $connection);

    assertSameValue('open', $gateway->transactionState('txn_1'), 'transactionState must read strictly LIVE_VERSION, not draft.');
    $context = $gateway->orderContext('txn_1');
    assertSameValue('47.5', $context['amount'], 'orderContext must return LIVE_VERSION amount, not draft.');
    assertSameValue('USD', $context['currency'], 'orderContext must return LIVE_VERSION currency, not draft.');
    assertSameValue('live_customer', $context['customer_id'], 'orderContext must return LIVE_VERSION customer.');
    assertSameValue(1, count($context['products']), 'orderProducts must return single LIVE_VERSION line item set.');
    assertSameValue('Live Item', $context['products'][0]['title'], 'orderProducts must exclude draft line items.');
}

function test_sw_option_suffixes_respect_200_scalar_title_budget()
{
    // BUG-1480: appending variation options must not push a valid base title past 200 UTF-8 scalars.
    sw_write_generated_config();
    $base195 = str_repeat('Ж', 195);
    $overflowCandidate = CheckoutProcessor::buildProductCandidate(
        $base195,
        null,
        1,
        '15.00',
        array('options' => array(array('group' => 'Color', 'option' => 'Midnight Blue')))
    );
    assertSameValue($base195, $overflowCandidate['title'], 'Overflowing options must keep the base title intact.');

    $exactBase = str_repeat('Б', 193); // 193 + ' · ' (3) + 'Blue' (4) = 200 scalars
    $exactCandidate = CheckoutProcessor::buildProductCandidate(
        $exactBase,
        null,
        1,
        '15.00',
        array('options' => array(array('group' => 'Color', 'option' => 'Blue')))
    );
    assertSameValue($exactBase . ' · Blue', $exactCandidate['title'], 'Options fitting within 200 UTF-8 scalars are appended.');

    $invoices = new FakePaymosInvoices();
    (new CheckoutProcessor(new InMemoryInvoiceStore(), static function () use ($invoices) {
        return new FakePaymosClient($invoices);
    }))->start(sw_order(array(
        'amount' => '30.00',
        'products' => array($overflowCandidate, $exactCandidate),
    )), sw_settings());

    assertSameValue(2, count($invoices->payloads[0]['products']), 'Cart with long-title variant must keep products[] instead of falling back to amount-only.');
    assertSameValue($base195, $invoices->payloads[0]['products'][0]['title'], 'First item keeps base title.');
    assertSameValue($exactBase . ' · Blue', $invoices->payloads[0]['products'][1]['title'], 'Second item keeps 200-scalar variant title.');
}
