--TEST--
AOT: current(null)/key(null) catchable TypeError (#27493, ext/standard/array.c)
--FILE--
<?php
try {
    var_export(current(null));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
$a = null;
try {
    var_export(current($a));
    echo " NO_THROW_VAR\n";
} catch (Throwable $e) {
    echo 'var:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    var_export(key(null));
    echo " KEY_NO_THROW\n";
} catch (Throwable $e) {
    echo 'key:', get_class($e), ':', $e->getMessage(), "\n";
}
$b = ['x' => 1, 'y' => 2];
echo 'cur=', var_export(current($b), true), ' key=', var_export(key($b), true), "\n";
--EXPECT--
TypeError:current(): Argument #1 ($array) must be of type array, null given
var:TypeError:current(): Argument #1 ($array) must be of type array, null given
key:TypeError:key(): Argument #1 ($array) must be of type array, null given
cur=1 key='x'
--CREDITS--
PurHur/php-compiler issue #27493
