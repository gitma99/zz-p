<?php
// Unit test: Logger::log fail-fast invalid level assertions.
require_once __DIR__ . '/helper.php';
require_once __DIR__ . '/../../App/Utils/Logger.php';
require_once __DIR__ . '/../../App/Utils/handlers/NullHandler.php';
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

// Regression: Location must be the Logger::* call site, not unknown:0.
Logger::setInstance(null);
Logger::init(LoggerConfig::fromArray(array('level' => 'DEBUG')));
$__probe = new NullHandler();
Logger::addHandler($__probe);
$__expectedLine = __LINE__ + 1;
Logger::error('caller probe');
Logger::flush();
$__record = $__probe->seen[count($__probe->seen) - 1];
assert_eq($__record['file'], basename(__FILE__), 'Location file is the call site');
assert_eq($__record['line'], $__expectedLine, 'Location line is the call site');

echo "OK logger_test.php\n";
exit(0);
