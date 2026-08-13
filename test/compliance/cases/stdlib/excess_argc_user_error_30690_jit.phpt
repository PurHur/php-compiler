--TEST--
stdlib JIT: user_error() excess argc → ArgumentCountError at most 2 (#30690)
--FILE--
<?php
try {
    user_error('x', E_USER_NOTICE, 1);
    echo "ue NO_THROW\n";
} catch (Throwable $e) {
    echo 'ue ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    user_error();
    echo "ue0 NO_THROW\n";
} catch (Throwable $e) {
    echo 'ue0 ', get_class($e), ': ', $e->getMessage(), "\n";
}
$er = error_reporting(0);
echo 'ok=', user_error('x', E_USER_NOTICE) ? '1' : '0', "\n";
error_reporting($er);
--EXPECT--
ue ArgumentCountError: user_error() expects at most 2 arguments, 3 given
ue0 ArgumentCountError: user_error() expects at least 1 argument, 0 given
ok=1
