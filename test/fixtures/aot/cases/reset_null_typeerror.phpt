--TEST--
AOT: reset(null) catchable TypeError (#27484, ext/standard/array.c)
--FILE--
<?php
$a = null;
try {
    var_export(reset($a));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
$b = [1, 2, 3];
var_export(reset($b));
echo "\n";
--EXPECT--
TypeError:reset(): Argument #1 ($array) must be of type array, null given
1
--CREDITS--
PurHur/php-compiler issue #27484
