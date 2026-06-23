<?php

declare(strict_types=1);

use PaymosPayments\Service\InMemoryInvoiceStore;
use PaymosPayments\Service\Reconciler;

/**
 * The missed-webhook recovery path: re-pull open invoices and re-apply them
 * through the SAME mapper (so AmountGuard + roll-back guard still apply), while
 * skipping the signature reverse-verify (the GET is already authenticated) and
 * respecting the grace window + terminal-status skip.
 */

function sw_reconcile_store(array $rows = array())
{
    $store = new InMemoryInvoiceStore();
    foreach ($rows as $row) {
        $store->save($row);
    }

    return $store;
}

function sw_reconcile_row(array $overrides = array())
{
    return array_merge(array(
        'transaction_id' => 'txn_1',
        'paymos_invoice_id' => 'inv_123',
        'external_order_id' => '10001_0',
        'environment' => 'sandbox',
        'project_id' => 'prj_123',
        'amount' => '100.00',
        'currency' => 'USD',
        'payment_url' => 'https://pay.paymos.test/inv_123',
        'status' => 'confirming',
        'renew_count' => 0,
        // Created two hours ago: outside the 30-minute grace window.
        'created_at' => date('Y-m-d H:i:s', 1709000000 - 7200),
    ), $overrides);
}

function test_sw_reconcile_marks_paid_for_open_invoice_now_paid()
{
    sw_write_generated_config();

    $gateway = new FakeShopwareGateway(array('txn_1' => 'in_progress'));
    $store = sw_reconcile_store(array(sw_reconcile_row()));

    $reconciler = new Reconciler($gateway, $store, static function () {
        // The live invoice has since become paid.
        return sw_reverse_client(array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'paid',
            'order' => array('external_id' => '10001_0', 'amount' => '100', 'currency' => 'USD'),
        ));
    });

    $count = $reconciler->run(sw_settings(), 1709000000);

    assertSameValue(1, $count, 'A now-paid open invoice is reconciled to paid.');
    assertSameValue('paid', $gateway->states['txn_1'], 'The transaction is marked paid by reconcile.');
}

function test_sw_reconcile_skips_invoice_inside_grace_window()
{
    sw_write_generated_config();

    $gateway = new FakeShopwareGateway(array('txn_1' => 'in_progress'));
    // Created 5 minutes ago: still inside the 30-minute grace window, so the
    // webhook is still expected and reconcile must not touch it.
    $store = sw_reconcile_store(array(sw_reconcile_row(array(
        'created_at' => date('Y-m-d H:i:s', 1709000000 - 300),
    ))));

    $reconciler = new Reconciler($gateway, $store, static function () {
        return sw_reverse_client(array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'paid',
            'order' => array('external_id' => '10001_0', 'amount' => '100', 'currency' => 'USD'),
        ));
    });

    $count = $reconciler->run(sw_settings(), 1709000000);

    assertSameValue(0, $count, 'An invoice still inside the grace window is not reconciled.');
    assertSameValue('in_progress', $gateway->states['txn_1'], 'The transaction is left untouched inside the grace window.');
}

function test_sw_reconcile_does_not_roll_back_a_paid_transaction()
{
    sw_write_generated_config();

    // The transaction is already paid; the live invoice reports cancelled. The
    // roll-back guard must still protect it through the reconcile path.
    $gateway = new FakeShopwareGateway(array('txn_1' => 'paid'));
    $store = sw_reconcile_store(array(sw_reconcile_row(array('status' => 'confirming'))));

    $reconciler = new Reconciler($gateway, $store, static function () {
        return sw_reverse_client(array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'cancelled',
            'order' => array('external_id' => '10001_0', 'amount' => '100', 'currency' => 'USD'),
        ));
    });

    $count = $reconciler->run(sw_settings(), 1709000000);

    assertSameValue(0, $count, 'Nothing is marked paid.');
    assertSameValue('paid', $gateway->states['txn_1'], 'A paid transaction is not downgraded by reconcile.');
    assertSameValue(0, count($gateway->transitions), 'No transition happens on a guarded reconcile.');
}
