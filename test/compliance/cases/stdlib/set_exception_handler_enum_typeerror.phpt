--TEST--
stdlib set_exception_handler() — enum case callback TypeError (#6243, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

enum E: string { case A = 'x'; }

try {
    set_exception_handler(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

function my_handler(Throwable $e): void {
}
set_exception_handler('my_handler');
restore_exception_handler();
echo "ok\n";
--EXPECT--
set_exception_handler(): Argument #1 ($callback) must be a valid callback or null, no array or string given
ok
