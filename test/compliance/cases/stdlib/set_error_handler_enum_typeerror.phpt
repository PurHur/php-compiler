--TEST--
stdlib set_error_handler() — enum case callback TypeError (#6234, ext/standard/basic_functions.c)
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
    return true;
}
set_error_handler('my_handler');
restore_error_handler();
echo "ok\n";
--EXPECT--
set_error_handler(): Argument #1 ($callback) must be a valid callback or null, no array or string given
ok
