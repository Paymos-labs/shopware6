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
