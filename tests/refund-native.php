<?php

declare(strict_types=1);

// Run on the approved stand with real core/DAL, DB and configured FlowBuilder mail.
$refundNativeContract = [
    'suite' => 'cms-shopware',
    'packages' => ['shopware/core:6.5.7.0', 'shopware/core:6.6.9.0', 'shopware/core:6.7.0.0'],
    'required_evidence' => ['composer show --format=json', 'plugin migration status', 'DB before/after',
        'signed HTTP request log without secrets', 'FlowBuilder mail count', 'crash/restart log'],
    'cases' => [
        'protected-actions' => 'Core refund UUID and managed full/overpayment; unauthorized API and partial/manual input make zero POSTs.',
        'over20-full100' => 'G120/P100: over20 leaves Paid/refunded0/stock unchanged; full100 completes one original native full refund.',
        'backfill-live-version' => 'Reverse-verified old paid order: one completed capture; draft/non-live context rejected before POST.',
        'unknown-restart' => 'Kill after accepted POST, remove request process: replay identical key, one Withdrawal, native in_progress.',
        'atomic-native-marker' => 'Kill before/after native commit: rollback or exact marker recovery; no second native entity or stock effect.',
        'reorg-mail' => 'Completed/reorg/recomplete: reopen/process/complete same UUID, consistent aggregate, isolated Context and no repeated FlowBuilder mail.',
        'notification-unknown' => 'Commit started before external send; kill after SMTP acceptance: unknown, no automatic resend; unrelated gateway mail still works.',
        'upgrade-reinstall' => 'Install/upgrade twice on MySQL/MariaDB; unique token/key/Withdrawal, durable pending history across keep-data reinstall.',
    ],
];

foreach ($refundNativeContract['packages'] as $package) {
    foreach ($refundNativeContract['cases'] as $case => $procedure) {
        echo 'SKIP cms-shopware ' . $package . ' ' . $case
            . ': approved native stand, installed platform/vendor and DB/FlowBuilder evidence unavailable; stubs are not native proof.' . PHP_EOL;
    }
}

return $refundNativeContract;
