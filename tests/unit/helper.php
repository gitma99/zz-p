<?php
// Shared assertions and messages for unit tests. Required by every *_test.php.
function fail($msg) {
    echo "FAIL: $msg\n";
    exit(1);
}

function warning($msg) {
    echo "WARNING: $msg\n";
}

function info($msg) {
    echo "INFO: $msg\n";
}

function assert_eq($expected, $actual, $msg) {
    if ($expected === $actual) return;
    fail("$msg\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true));
}

function assert_true($cond, $msg) {
    if ($cond) return;
    fail($msg);
}

function assert_throws($fn, $exceptionClass, $msg) {
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $exceptionClass) return;
        fail("$msg\n  expected exception: $exceptionClass\n  actual exception:   " . get_class($e) . " (" . $e->getMessage() . ")");
    }
    fail("$msg\n  expected exception: $exceptionClass\n  actual: no exception thrown");
}
