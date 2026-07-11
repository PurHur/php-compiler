--TEST--
stdlib header_register_callback() — null callback TypeError (#14789, ext/standard/head.c)
--FILE--
<?php
declare(strict_types=1);

try {
    header_register_callback(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

function hcb_ok(): void {
    header('X-Hcb-Ok: 1');
}
header_register_callback('hcb_ok');
echo "ok\n";
--EXPECT--
header_register_callback(): Argument #1 ($callback) must be a valid callback, no array or string given
ok
--EXPECT_EXIT--
0
