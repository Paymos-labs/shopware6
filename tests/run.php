<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

// Compile every plugin source file, not just the ones the tests touch: the
// platform loads all of them, so a defect in an untested file must fail HERE.
$_srcRoot = PAYMOS_SW_PLUGIN_DIR;
$_iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
    $_srcRoot,
    FilesystemIterator::SKIP_DOTS
));
$_files = array();
foreach ($_iter as $_f) {
    if (!$_f->isFile() || $_f->getExtension() !== 'php') {
        continue;
    }
    $_rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($_f->getPathname(), strlen($_srcRoot)));
    if (preg_match('!(^|/)(tests?|vendor|node_modules|\.github)(/|$)!', $_rel)) {
        continue;
    }
    $_files[] = $_f->getPathname();
}
sort($_files);

// Constants the harness itself pre-defined may be re-defined by the plugin's
// entry file (the platform loads that file FIRST in production). Tolerate
// exactly those collisions; anything else — including a real duplicate between
// two plugin files — still fails the run.
$_predefined = get_defined_constants(true);
$_predefined = isset($_predefined['user']) ? $_predefined['user'] : array();
foreach ($_files as $_file) {
    set_error_handler(static function ($severity, $message, $file, $line) use ($_predefined) {
        if (preg_match('/Constant (\\w+) already defined/', $message, $_m)
            && array_key_exists($_m[1], $_predefined)) {
            return true;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    try {
        require_once $_file;
    } finally {
        restore_error_handler();
    }
}
unset($_srcRoot, $_iter, $_files, $_file, $_rel, $_f, $_predefined);

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
