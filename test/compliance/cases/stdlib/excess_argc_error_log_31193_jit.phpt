--TEST--
stdlib: error_log() excess argc at-most wording JIT (#31193)
--FILE--
<?php
try {
    error_log('m', 0, '', '', 'x');
    echo "excess NO_THROW\n";
} catch (Throwable $e) {
    echo 'excess ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    error_log();
    echo "missing NO_THROW\n";
} catch (Throwable $e) {
    echo 'missing ', get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
excess ArgumentCountError: error_log() expects at most 4 arguments, 5 given
missing ArgumentCountError: error_log() expects at least 1 argument, 0 given
