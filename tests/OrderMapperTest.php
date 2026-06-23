<?php

declare(strict_types=1);

use Paymos\Webhook\WebhookEvent;
use PaymosPayments\Service\OrderMapper;

function sw_mapper_row(array $overrides = array())
{
    return array_merge(array(
        'transaction_id' => 'txn_1',
        'paymos_invoice_id' => 'inv_123',
        'external_order_id' => '10001_0',
        'environment' => 'sandbox',
        'project_id' => 'prj_123',
        'amount' => '100.00',
        'currency' => 'USD',
    ), $overrides);
}

function sw_mapper_event($type, $status, array $orderOverrides = array())
{
    return new WebhookEvent(array(
        'event_id' => 'evt_1',
        'event_type' => $type,
        'data' => array_replace_recursive(array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => $status,
            'order' => array(
                'external_id' => '10001_0',
                'amount' => '100.00',
                'currency' => 'USD',
            ),
        ), array('order' => $orderOverrides)),
    ));
}

function test_sw_mapper_marks_paid_on_invoice_paid()
{
    $gateway = new FakeShopwareGateway(array('txn_1' => 'in_progress'));
    $paid = (new OrderMapper($gateway))->apply(sw_mapper_event('invoice.paid', 'paid'), sw_mapper_row(), false);

    assertTrueValue($paid, 'invoice.paid returns paid=true.');
    assertSameValue('paid', $gateway->states['txn_1'], 'Transaction transitions to paid.');
}

function test_sw_mapper_marks_paid_on_invoice_paid_over()
{
    $gateway = new FakeShopwareGateway(array('txn_1' => 'in_progress'));
    $paid = (new OrderMapper($gateway))->apply(sw_mapper_event('invoice.paid_over', 'paid_over'), sw_mapper_row(), false);

    assertTrueValue($paid, 'invoice.paid_over also completes payment.');
    assertSameValue('paid', $gateway->states['txn_1'], 'Overpaid transitions to paid.');
}

function test_sw_mapper_fails_on_invoice_underpaid()
{
    $gateway = new FakeShopwareGateway(array('txn_1' => 'in_progress'));
    (new OrderMapper($gateway))->apply(sw_mapper_event('invoice.underpaid', 'underpaid'), sw_mapper_row(), false);

    assertSameValue('failed', $gateway->states['txn_1'], 'Underpaid transitions to failed.');
}

function test_sw_mapper_cancels_on_invoice_expired()
{
    $gateway = new FakeShopwareGateway(array('txn_1' => 'in_progress'));
    (new OrderMapper($gateway))->apply(sw_mapper_event('invoice.expired', 'expired'), sw_mapper_row(), false);

    assertSameValue('cancelled', $gateway->states['txn_1'], 'Expired transitions to cancelled.');
}

function test_sw_mapper_cancels_on_invoice_cancelled()
{
    $gateway = new FakeShopwareGateway(array('txn_1' => 'in_progress'));
    (new OrderMapper($gateway))->apply(sw_mapper_event('invoice.cancelled', 'cancelled'), sw_mapper_row(), false);

    assertSameValue('cancelled', $gateway->states['txn_1'], 'Cancelled transitions to cancelled.');
}

function test_sw_mapper_keeps_open_on_confirming()
{
    $gateway = new FakeShopwareGateway(array('txn_1' => 'open'));
    (new OrderMapper($gateway))->apply(sw_mapper_event('invoice.confirming', 'confirming'), sw_mapper_row(), false);

    assertSameValue(0, count($gateway->transitions), 'Confirming on an open transaction does not transition.');
}

function test_sw_mapper_reopens_after_reorg_regression()
{
    // A previously-advanced transaction (not yet paid) returns to awaiting on a
    // reorg regression event.
    $gateway = new FakeShopwareGateway(array('txn_1' => 'cancelled'));
    (new OrderMapper($gateway))->apply(sw_mapper_event('invoice.awaiting_payment', 'awaiting_payment'), sw_mapper_row(), false);

    assertSameValue('open', $gateway->states['txn_1'], 'Regression reopens a non-paid transaction.');
}

function test_sw_mapper_rolls_back_guard_blocks_late_cancel_after_paid()
{
    $gateway = new FakeShopwareGateway(array('txn_1' => 'paid'));
    $paid = (new OrderMapper($gateway))->apply(sw_mapper_event('invoice.cancelled', 'cancelled'), sw_mapper_row(), false);

    assertFalseValue($paid, 'Late cancel after paid returns paid=false.');
    assertSameValue(0, count($gateway->transitions), 'Late cancel must not downgrade a paid transaction.');
    assertSameValue('paid', $gateway->states['txn_1'], 'Paid transaction stays paid.');
}

function test_sw_mapper_rolls_back_guard_blocks_late_confirming_after_paid()
{
    $gateway = new FakeShopwareGateway(array('txn_1' => 'paid'));
    (new OrderMapper($gateway))->apply(sw_mapper_event('invoice.confirming', 'confirming'), sw_mapper_row(), false);

    assertSameValue(0, count($gateway->transitions), 'Stale confirming must not touch a paid transaction.');
}

function test_sw_mapper_amount_mismatch_holds_for_manual_review()
{
    $gateway = new FakeShopwareGateway(array('txn_1' => 'in_progress'));

    // The webhook order amount disagrees with the snapshot -> AmountGuard fails.
    $event = sw_mapper_event('invoice.paid', 'paid', array('amount' => '90.00'));

    // Must NOT throw (that would 400 and the server would retry the same
    // permanently-mismatched event forever). Hold for manual review, return false.
    $paid = (new OrderMapper($gateway))->apply($event, sw_mapper_row(), false);

    assertFalseValue($paid, 'An amount mismatch must not mark paid.');
    assertSameValue(0, count($gateway->transitions), 'A mismatch must not transition the transaction.');
    assertSameValue(1, count($gateway->logs), 'A mismatch must leave a manual-review log entry.');
}

function test_sw_mapper_no_cross_terminal_cancel_after_failed()
{
    // underpaid already set the tx to failed; a late cancelled/expired must NOT
    // attempt failed->cancel (illegal SW transition -> throw -> 400 -> retry loop).
    $gateway = new FakeShopwareGateway(array('txn_1' => 'failed'));
    $paid = (new OrderMapper($gateway))->apply(sw_mapper_event('invoice.cancelled', 'cancelled'), sw_mapper_row(), false);

    assertFalseValue($paid, 'Late cancel after failed is not a completion.');
    assertSameValue(0, count($gateway->transitions), 'Cross-terminal failed->cancel must be skipped, not attempted.');
    assertSameValue('failed', $gateway->states['txn_1'], 'The terminal state is left unchanged.');
}

function test_sw_mapper_no_cross_terminal_fail_after_cancelled()
{
    // expired already set the tx to cancelled; a later underpaid must NOT attempt
    // cancelled->fail (illegal SW transition -> throw -> 400 -> retry loop).
    $gateway = new FakeShopwareGateway(array('txn_1' => 'cancelled'));
    $paid = (new OrderMapper($gateway))->apply(sw_mapper_event('invoice.underpaid', 'underpaid'), sw_mapper_row(), false);

    assertFalseValue($paid, 'Late fail after cancelled is not a completion.');
    assertSameValue(0, count($gateway->transitions), 'Cross-terminal cancelled->fail must be skipped, not attempted.');
    assertSameValue('cancelled', $gateway->states['txn_1'], 'The terminal state is left unchanged.');
}

function test_sw_mapper_recovers_paid_after_cancelled_via_reopen()
{
    // A cancelled (expired) invoice that is genuinely paid afterwards (reorg / paid
    // right at expiry) must recover: cancelled->paid is illegal directly, so the
    // mapper reopens first, then pays.
    $gateway = new FakeShopwareGateway(array('txn_1' => 'cancelled'));
    $paid = (new OrderMapper($gateway))->apply(sw_mapper_event('invoice.paid', 'paid'), sw_mapper_row(), false);

    assertTrueValue($paid, 'A genuine late paid on a cancelled tx must recover to paid.');
    assertSameValue('paid', $gateway->states['txn_1'], 'The transaction ends paid via reopen+pay.');
}

function test_sw_mapper_ignores_unknown_event()
{
    $gateway = new FakeShopwareGateway(array('txn_1' => 'open'));
    $paid = (new OrderMapper($gateway))->apply(sw_mapper_event('invoice.something_else', 'weird'), sw_mapper_row(), false);

    assertFalseValue($paid, 'Unknown event is not a completion.');
    assertSameValue(0, count($gateway->transitions), 'Unknown event leaves the transaction unchanged.');
}
