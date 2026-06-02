--TEST--
Stdlib: trigger_error() invalid error level throws ValueError (#4214)
--FILE--
<?php
try {
    trigger_error('x', 999);
} catch (ValueError $e) {
    echo "ValueError\n";
    echo $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
--EXPECT--
ValueError
trigger_error(): Argument #2 ($error_level) must be one of E_USER_ERROR, E_USER_WARNING, E_USER_NOTICE, or E_USER_DEPRECATED
