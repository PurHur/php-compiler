--TEST--
stdlib array_walk() JIT — null array TypeError message matches Zend (#19836, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

$a = null;
try {
    array_walk($a, static function ($v): void {
    });
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

$b = 1;
try {
    array_walk($b, static function ($v): void {
    });
    echo "uncaught int\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
array_walk(): Argument #1 ($array) must be of type array, null given
array_walk(): Argument #1 ($array) must be of type array, int given
--EXPECT_EXIT--
0
