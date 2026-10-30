<?php
// Unit test: Logger::log fail-fast invalid level assertions.
require_once __DIR__ . '/helper.php';
require_once __DIR__ . '/../../App/Utils/Logger.php';
error_reporting(E_ALL ^ E_NOTICE);

// Valid logs do not throw
Logger::info('test info');
Logger::warning('test warning');
Logger::error('test error');

// Fail fast on invalid level names
assert_throws(function() {
    Logger::log('INVALID_LEVEL_NAME', 'message');
}, InvalidArgumentException::class, 'Logger::log throws on invalid string level');

assert_throws(function() {
    Logger::log('WARN', 'message'); // must be WARNING
}, InvalidArgumentException::class, 'Logger::log throws on WARN (expected WARNING)');

echo "OK logger_test.php\n";
exit(0);
