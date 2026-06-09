--TEST--
stdlib register_shutdown_function() — enum case callback TypeError (#6245, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

enum E: string { case A = 'x'; }

try {
    register_shutdown_function(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

function shutdown_cb(): void {
    echo "ok\n";
}
register_shutdown_function('shutdown_cb');
--EXPECT--
register_shutdown_function(): Argument #1 ($callback) must be a valid callback, no array or string given
ok
--EXPECT_EXIT--
0
