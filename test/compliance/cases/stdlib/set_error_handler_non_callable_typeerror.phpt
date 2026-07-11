--TEST--
stdlib set_error_handler() — non-callable string TypeError (#12152, ext/standard/basic_functions.c)
--FILE--
<?php
try {
    set_error_handler('not_a_real_function_xyz');
    echo "no_throw\n";
} catch (TypeError $e) {
    echo "ok\n";
}
--EXPECT--
ok
