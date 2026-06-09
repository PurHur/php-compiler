--TEST--
stdlib set_error_handler() JIT — enum case callback TypeError (#6234)
--FILE--
<?php
declare(strict_types=1);

enum E: string { case A = 'x'; }

try {
    set_error_handler(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

function my_handler($errno, $errstr, $errfile, $errline) {
    echo "handled\n";
    return true;
}
set_error_handler('my_handler');
trigger_error('test', E_USER_WARNING);
restore_error_handler();
echo "ok\n";
--EXPECT--
set_error_handler(): Argument #1 ($callback) must be a valid callback or null, no array or string given
handled
ok
