--TEST--
stdlib JIT: gc_enabled / restore_*_handler excess argc → ArgumentCountError (#30653)
--FILE--
<?php
foreach ([
    'gc_enabled' => static fn () => gc_enabled(1),
    'restore_error_handler' => static fn () => restore_error_handler(1),
    'restore_exception_handler' => static fn () => restore_exception_handler(1),
] as $name => $call) {
    try {
        $call();
        echo $name, " NO_THROW\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
echo 'ok_gc=', gc_enabled() ? '1' : '0', "\n";
echo 'ok_reh=', restore_error_handler() ? '1' : '0', "\n";
echo 'ok_rxh=', restore_exception_handler() ? '1' : '0', "\n";
--EXPECT--
gc_enabled ArgumentCountError: gc_enabled() expects exactly 0 arguments, 1 given
restore_error_handler ArgumentCountError: restore_error_handler() expects exactly 0 arguments, 1 given
restore_exception_handler ArgumentCountError: restore_exception_handler() expects exactly 0 arguments, 1 given
ok_gc=1
ok_reh=1
ok_rxh=1
