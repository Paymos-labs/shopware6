<?php

declare(strict_types=1);

use PaymosPayments\Service\FinalizeDecision;

function test_sw_finalize_cancel_flag_returns_cancel()
{
    assertSameValue(
        FinalizeDecision::CANCEL,
        FinalizeDecision::decide(true, 'open'),
        'Cancel link on an open transaction returns cancel.'
    );
}

function test_sw_finalize_cancel_flag_on_paid_returns_complete()
{
    // The webhook already confirmed payment; a cancel link must NOT undo it.
    assertSameValue(
        FinalizeDecision::COMPLETE,
        FinalizeDecision::decide(true, 'paid'),
        'Cancel link on an already-paid transaction returns complete (webhook wins).'
    );
}

function test_sw_finalize_open_leaves_open()
{
    assertSameValue(
        FinalizeDecision::LEAVE_OPEN,
        FinalizeDecision::decide(false, 'open'),
        'A normal return on an open transaction leaves it open (webhook in flight).'
    );
}

function test_sw_finalize_in_progress_leaves_open()
{
    assertSameValue(
        FinalizeDecision::LEAVE_OPEN,
        FinalizeDecision::decide(false, 'in_progress'),
        'in_progress leaves the transaction open.'
    );
}

function test_sw_finalize_unknown_state_leaves_open()
{
    assertSameValue(
        FinalizeDecision::LEAVE_OPEN,
        FinalizeDecision::decide(false, ''),
        'An unknown/empty state leaves the transaction open.'
    );
}

function test_sw_finalize_paid_completes()
{
    assertSameValue(
        FinalizeDecision::COMPLETE,
        FinalizeDecision::decide(false, 'paid'),
        'A paid transaction completes (routes to the finish page).'
    );
}

function test_sw_finalize_refunded_completes()
{
    // refunded is "at or past paid" — never downgrade on a browser return.
    assertSameValue(
        FinalizeDecision::COMPLETE,
        FinalizeDecision::decide(false, 'refunded'),
        'A refunded transaction is treated as complete (past paid).'
    );
}

function test_sw_finalize_failed_fails()
{
    assertSameValue(
        FinalizeDecision::FAIL,
        FinalizeDecision::decide(false, 'failed'),
        'A failed transaction returns fail.'
    );
}

function test_sw_finalize_cancelled_cancels()
{
    assertSameValue(
        FinalizeDecision::CANCEL,
        FinalizeDecision::decide(false, 'cancelled'),
        'A cancelled transaction returns cancel.'
    );
}

function test_sw_finalize_is_case_insensitive()
{
    assertSameValue(
        FinalizeDecision::COMPLETE,
        FinalizeDecision::decide(false, 'PAID'),
        'State comparison is case-insensitive.'
    );
}
