--TEST--
stdlib set_exception_handler() — int callback TypeError (#16693, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

try {
    set_exception_handler(1);
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
