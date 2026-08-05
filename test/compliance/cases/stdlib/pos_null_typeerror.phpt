--TEST--
stdlib pos(null) TypeError catchable (#27512, ext/standard/array.c)
--FILE--
<?php
try {
    var_export(pos(null));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
$a = null;
try {
    var_export(pos($a));
    echo " NO_THROW_VAR\n";
} catch (Throwable $e) {
    echo 'var:', get_class($e), ':', $e->getMessage(), "\n";
}
$b = ['x' => 1, 'y' => 2];
echo 'pos=', var_export(pos($b), true), ' cur=', var_export(current($b), true), "\n";
--EXPECT--
TypeError:pos(): Argument #1 ($array) must be of type array, null given
var:TypeError:pos(): Argument #1 ($array) must be of type array, null given
pos=1 cur=1
--CREDITS--
PurHur/php-compiler issue #27512
