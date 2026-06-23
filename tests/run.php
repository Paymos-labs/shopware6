<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$testFiles = array(
    __DIR__ . '/ConfigTest.php',
    __DIR__ . '/CheckoutProcessorTest.php',
    __DIR__ . '/OrderMapperTest.php',
    __DIR__ . '/EventStoreTest.php',
    __DIR__ . '/WebhookProcessorTest.php',
    __DIR__ . '/ReconcilerTest.php',
    __DIR__ . '/FinalizeDecisionTest.php',
    __DIR__ . '/ReturnBridgeResolverTest.php',
);

foreach ($testFiles as $file) {
    require $file;
}

$tests = array_filter(get_defined_functions()['user'], static function ($name) {
    return strpos($name, 'test_sw_') === 0;
});
sort($tests);

$count = 0;
foreach ($tests as $test) {
    sw_reset_test_state();
    $test();
    $count++;
    echo "PASS {$test}\n";
}

sw_reset_test_state();
echo "OK {$count} tests\n";
