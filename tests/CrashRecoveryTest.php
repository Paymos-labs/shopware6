<?php
declare(strict_types=1);

function test_shopware_paid_recovers_before_and_after_cms_commit()
{
    sw_write_generated_config();
    foreach (array('failBeforePayment', 'failAfterPayment') as $fault) {
        $store = sw_seeded_store();
        $gateway = new FakeShopwareGateway(array('txn_1' => 'in_progress'));
        $gateway->$fault = true;
        $processor = new PaymosPayments\Service\WebhookProcessor($gateway, $store,
            new Paymos\Webhook\InMemoryEventStore(), static function () { return sw_reverse_client(); });
        $body = json_encode(sw_invoice_event('evt_crash_' . $fault, 'invoice.paid', 'paid'));
        $sig = sw_signed_header('whsec_sandbox', $body, 1709000000);
        $first = $processor->handle($body, $sig, sw_settings(), 1709000000);
        assertSameValue(false, $first->statusCode() === 200, 'Fault must remain retriable.');
        assertSameValue('awaiting_client', $store->findByExternalOrderId('10001_0')['status'], 'No premature paid snapshot.');
        $second = $processor->handle($body, $sig, sw_settings(), 1709000000);
        assertSameValue(200, $second->statusCode(), 'Retry recovers.');
        assertSameValue('paid', $store->findByExternalOrderId('10001_0')['status'], 'Retry finalizes snapshot.');
        assertSameValue(1, count($gateway->transitions), 'CMS payment happens once.');
    }
}
