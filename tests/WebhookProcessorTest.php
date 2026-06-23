<?php

declare(strict_types=1);

use Paymos\Webhook\InMemoryEventStore;
use PaymosPayments\Service\InMemoryInvoiceStore;
use PaymosPayments\Service\WebhookProcessor;

function sw_seeded_store(array $overrides = array())
{
    $store = new InMemoryInvoiceStore();
    $store->save(array_merge(array(
        'transaction_id' => 'txn_1',
        'paymos_invoice_id' => 'inv_123',
        'external_order_id' => '10001_0',
        'environment' => 'sandbox',
        'project_id' => 'prj_123',
        'amount' => '100.00',
        'currency' => 'USD',
        'payment_url' => 'https://pay.paymos.test/inv_123',
        'status' => 'awaiting_client',
        'renew_count' => 0,
    ), $overrides));

    return $store;
}

function test_sw_webhook_marks_paid_after_verify_and_reverse_lookup()
{
    sw_write_generated_config();

    $gateway = new FakeShopwareGateway(array('txn_1' => 'in_progress'));
    $store = sw_seeded_store();

    $processor = new WebhookProcessor(
        $gateway,
        $store,
        new InMemoryEventStore(),
        static function () {
            // Reverse-verify GET returns "100" (server trims "100.00") — must
            // compare equal to the "100.00" snapshot.
            return sw_reverse_client();
        }
    );

    $body = json_encode(sw_invoice_event('evt_paid', 'invoice.paid', 'paid'));
    $result = $processor->handle($body, sw_signed_header('whsec_sandbox', $body, 1709000000), sw_settings(), 1709000000);

    assertSameValue(200, $result->statusCode(), 'A verified paid webhook returns 200.');
    assertSameValue('paid', $gateway->states['txn_1'], 'The transaction is marked paid.');
}

function test_sw_webhook_reverse_verify_accepts_server_trimmed_amount()
{
    // Explicit decimal-safety case: snapshot "100.00", live invoice "100".
    sw_write_generated_config();

    $gateway = new FakeShopwareGateway(array('txn_1' => 'in_progress'));
    $store = sw_seeded_store(array('amount' => '100.00'));

    $processor = new WebhookProcessor(
        $gateway,
        $store,
        new InMemoryEventStore(),
        static function () {
            return sw_reverse_client(array(
                'invoice_id' => 'inv_123',
                'project_id' => 'prj_123',
                'status' => 'paid',
                'order' => array(
                    'external_id' => '10001_0',
                    'amount' => '100', // trimmed
                    'currency' => 'USD',
                ),
            ));
        }
    );

    $body = json_encode(sw_invoice_event('evt_trim', 'invoice.paid', 'paid'));
    $result = $processor->handle($body, sw_signed_header('whsec_sandbox', $body, 1709000000), sw_settings(), 1709000000);

    assertSameValue(200, $result->statusCode(), 'Trimmed-amount reverse-verify must pass.');
    assertSameValue('paid', $gateway->states['txn_1'], 'Order is paid despite "100" vs "100.00".');
}

function test_sw_webhook_is_idempotent_for_duplicate_events()
{
    sw_write_generated_config();

    $gateway = new FakeShopwareGateway(array('txn_1' => 'in_progress'));
    $store = sw_seeded_store();
    $eventStore = new InMemoryEventStore();

    $processor = new WebhookProcessor($gateway, $store, $eventStore, static function () {
        return sw_reverse_client();
    });

    $body = json_encode(sw_invoice_event('evt_dup', 'invoice.paid', 'paid'));
    $sig = sw_signed_header('whsec_sandbox', $body, 1709000000);

    $first = $processor->handle($body, $sig, sw_settings(), 1709000000);
    $second = $processor->handle($body, $sig, sw_settings(), 1709000000);

    assertSameValue(200, $first->statusCode(), 'First delivery returns 200.');
    assertSameValue(200, $second->statusCode(), 'Duplicate delivery still returns 200.');
    assertTrueValue($second->isDuplicate(), 'Second delivery is flagged duplicate.');

    $paidTransitions = array_filter($gateway->transitions, static function ($t) {
        return $t['action'] === 'paid';
    });
    assertSameValue(1, count($paidTransitions), 'Duplicate must not mark the order paid twice.');
}

function test_sw_webhook_rejects_bad_signature_with_401()
{
    sw_write_generated_config();

    $processor = new WebhookProcessor(
        new FakeShopwareGateway(array('txn_1' => 'in_progress')),
        sw_seeded_store(),
        new InMemoryEventStore(),
        static function () {
            return sw_reverse_client();
        }
    );

    $body = json_encode(sw_invoice_event('evt_bad', 'invoice.paid', 'paid'));
    $result = $processor->handle($body, sw_signed_header('whsec_WRONG', $body, 1709000000), sw_settings(), 1709000000);

    assertSameValue(401, $result->statusCode(), 'A bad signature returns 401.');
}

function test_sw_webhook_amount_mismatch_holds_for_manual_review()
{
    sw_write_generated_config();

    $gateway = new FakeShopwareGateway(array('txn_1' => 'in_progress'));
    $store = sw_seeded_store(array('amount' => '100.00'));

    // The webhook payload's order amount disagrees with the snapshot; the
    // signature is valid and reverse-verify passes, but the AmountGuard catches the
    // drift. This is NOT transient — the figures won't change on redelivery — so the
    // webhook must be acknowledged (200) and the order held, never 400'd into an
    // endless retry loop.
    $processor = new WebhookProcessor($gateway, $store, new InMemoryEventStore(), static function () {
        return sw_reverse_client();
    });

    $event = sw_invoice_event('evt_mismatch', 'invoice.paid', 'paid', array(
        'data' => array('order' => array('amount' => '90.00')),
    ));
    $body = json_encode($event);
    $result = $processor->handle($body, sw_signed_header('whsec_sandbox', $body, 1709000000), sw_settings(), 1709000000);

    assertSameValue(200, $result->statusCode(), 'An amount mismatch must acknowledge the webhook (200), not retry forever.');
    assertSameValue('in_progress', $gateway->states['txn_1'], 'A mismatch must not mark the order paid.');
}

function test_sw_webhook_does_not_roll_back_paid_order_on_late_cancel()
{
    sw_write_generated_config();

    $gateway = new FakeShopwareGateway(array('txn_1' => 'paid'));
    $store = sw_seeded_store(array('status' => 'paid'));

    $processor = new WebhookProcessor(
        $gateway,
        $store,
        new InMemoryEventStore(),
        static function () {
            // Reverse-verify sees the invoice as cancelled, but the roll-back
            // guard must still protect the already-paid transaction.
            return sw_reverse_client(array(
                'invoice_id' => 'inv_123',
                'project_id' => 'prj_123',
                'status' => 'cancelled',
                'order' => array('external_id' => '10001_0', 'amount' => '100', 'currency' => 'USD'),
            ));
        }
    );

    $body = json_encode(sw_invoice_event('evt_late_cancel', 'invoice.cancelled', 'cancelled'));
    $result = $processor->handle($body, sw_signed_header('whsec_sandbox', $body, 1709000000), sw_settings(), 1709000000);

    assertSameValue(200, $result->statusCode(), 'Late cancel still acks with 200.');
    assertSameValue('paid', $gateway->states['txn_1'], 'A paid order must not be downgraded by a late cancel.');
    assertSameValue(0, count($gateway->transitions), 'No transition happens on a guarded roll-back.');
}

function test_sw_webhook_ignores_non_invoice_event()
{
    sw_write_generated_config();

    $gateway = new FakeShopwareGateway();
    $processor = new WebhookProcessor($gateway, new InMemoryInvoiceStore(), new InMemoryEventStore(), static function () {
        return sw_reverse_client();
    });

    $event = array(
        'event_id' => 'evt_wd',
        'event_type' => 'withdrawal.completed',
        'occurred_at' => 1709000000,
        'data' => array('status' => 'completed'),
    );
    $body = json_encode($event);
    $result = $processor->handle($body, sw_signed_header('whsec_sandbox', $body, 1709000000), sw_settings(), 1709000000);

    assertSameValue(200, $result->statusCode(), 'A non-invoice event is acknowledged with 200.');
    assertSameValue(0, count($gateway->transitions), 'A non-invoice event triggers no transition.');
}

function test_sw_webhook_missing_config_returns_500()
{
    // No webhook secrets configured -> the verifier construction throws
    // InvalidArgumentException -> 500 (configuration error).
    sw_reset_test_state();
    PaymosPayments\Service\Config::useConfigPathForTests(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'paymos-sw-missing.php');

    $processor = new WebhookProcessor(
        new FakeShopwareGateway(),
        new InMemoryInvoiceStore(),
        new InMemoryEventStore(),
        static function () {
            return sw_reverse_client();
        }
    );

    $body = json_encode(sw_invoice_event('evt_noconf', 'invoice.paid', 'paid'));
    $result = $processor->handle($body, sw_signed_header('whsec_sandbox', $body, 1709000000), sw_settings(), 1709000000);

    assertSameValue(500, $result->statusCode(), 'Missing config returns 500.');
}
