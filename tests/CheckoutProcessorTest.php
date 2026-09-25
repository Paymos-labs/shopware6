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
    assertSameValue('100.00', $payload['amount'], 'Payload amount is the formatted order amount.');
    assertSameValue('USD', $payload['currency'], 'Payload currency is the order currency.');
    assertSameValue('10001_0', $payload['external_order_id'], 'External order id is order-number + renew suffix.');
    assertSameValue('cust_77', $payload['client_id'], 'Client id is the native customer id.');
    assertFalseValue(array_key_exists('merchant_id', $payload), 'Merchant id is NEVER sent.');
    assertFalseValue(array_key_exists('ttl', $payload), 'No TTL/lifetime field is sent (server-side only).');
    assertFalseValue(array_key_exists('url', $payload), 'No return/webhook URL field is sent.');

    // Snapshot persisted for the webhook AmountGuard to compare against.
    $row = $store->findByTransactionId('txn_1');
    assertSameValue('100.00', (string) $row['amount'], 'Snapshot stores the order amount.');
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
    assertSameValue('100.10', $invoices->payloads[0]['amount'], 'Float amount formats to 2dp dot-decimal.');
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
