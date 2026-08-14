--TEST--
stdlib: error_get_last / error_clear_last excess argc → ArgumentCountError (#30674)
--FILE--
<?php
foreach (['error_get_last', 'error_clear_last'] as $fn) {
    try {
        $fn(1);
        echo $fn, " NO_THROW\n";
    } catch (Throwable $e) {
        echo $fn, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
error_clear_last();
echo 'ok_get=', error_get_last() === null ? '1' : '0', "\n";
echo 'ok_clear=', error_clear_last() === null ? '1' : '0', "\n";
--EXPECT--
error_get_last ArgumentCountError: error_get_last() expects exactly 0 arguments, 1 given
error_clear_last ArgumentCountError: error_clear_last() expects exactly 0 arguments, 1 given
ok_get=1
ok_clear=1
