<?php

declare(strict_types=1);

use Paymos\Webhook\InMemoryEventStore;
use PaymosPayments\Service\InMemoryInvoiceStore;
use PaymosPayments\Service\WebhookProcessor;

// invoice-late-payment: a paid_late event on an invoice whose row holds a FINAL status (expired)
// touches NO transaction transition — Shopware's state machine is the only supported write surface,
// so the late fact lands in the gateway log plus a per-invoice marker. A sequential duplicate
// (distinct event id) stays silent.

function sw_paid_late_body($eventId)
{
    return json_encode(sw_invoice_event($eventId, 'invoice.paid_late', 'expired', array(
        'data' => array('paid_late_at' => 1777978800),
    )));
}

function test_sw_paid_late_never_transitions_and_logs_once()
{
    sw_write_generated_config();

    $gateway = new FakeShopwareGateway(array('txn_1' => 'cancelled'));
    $store = sw_seeded_store(array('status' => 'expired'));
    $processor = new WebhookProcessor($gateway, $store, new InMemoryEventStore(), static function () {
        return sw_reverse_client();
    });

    $body = sw_paid_late_body('evt_late_1');
    $result = $processor->handle($body, sw_signed_header('whsec_sandbox', $body, 1709000000), sw_settings(), 1709000000);

    assertSameValue(0, count($gateway->transitions), 'paid_late must never drive a state-machine transition.');
    assertSameValue('cancelled', $gateway->states['txn_1'], 'the transaction state stays as it was.');
    $noted = 0;
    foreach ($gateway->logs as $log) {
        if (strpos($log['message'], 'arrived after the invoice deadline') !== false) {
            $noted++;
        }
    }
    assertSameValue(1, $noted, 'paid_late must log the late fact exactly once.');
    assertSameValue('evt_late_1', $store->findByExternalOrderId('10001_0')['paid_late_noted'], 'marker records the event id.');
    assertSameValue('expired', $store->findByExternalOrderId('10001_0')['status'], 'row status stays final.');
    assertTrueValue($result->statusCode() === 200 || $result->statusCode() === 204, 'the note outcome acknowledges.');
}

function test_sw_paid_late_sequential_duplicate_is_silent()
{
    sw_write_generated_config();

    $gateway = new FakeShopwareGateway(array('txn_1' => 'cancelled'));
    $store = sw_seeded_store(array('status' => 'expired'));
    $eventStore = new InMemoryEventStore();
    $processor = new WebhookProcessor($gateway, $store, $eventStore, static function () {
        return sw_reverse_client();
    });

    foreach (array('evt_late_1', 'evt_late_2') as $eventId) {
        $body = sw_paid_late_body($eventId);
        $processor->handle($body, sw_signed_header('whsec_sandbox', $body, 1709000000), sw_settings(), 1709000000);
    }

    assertSameValue(0, count($gateway->transitions), 'duplicates never transition.');
    $noted = 0;
    foreach ($gateway->logs as $log) {
        if (strpos($log['message'], 'arrived after the invoice deadline') !== false) {
            $noted++;
        }
    }
    assertSameValue(1, $noted, 'a sequential duplicate must not log twice.');
}
