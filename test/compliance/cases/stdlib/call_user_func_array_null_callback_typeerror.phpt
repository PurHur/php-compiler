--TEST--
call_user_func_array(null) TypeError names call_user_func_array() (#19837)
--FILE--
<?php
try {
    call_user_func_array(null, []);
} catch (Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}
try {
    call_user_func(null);
} catch (Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}
?>
--EXPECT--
TypeError: call_user_func_array(): Argument #1 ($callback) must be a valid callback, no array or string given
TypeError: call_user_func(): Argument #1 ($callback) must be a valid callback, no array or string given
