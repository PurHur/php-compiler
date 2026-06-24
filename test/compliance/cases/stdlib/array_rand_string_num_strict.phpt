--TEST--
stdlib array_rand() string num under strict call site (#11289, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);
try {
    array_rand([1, 2, 3], '2');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: array_rand(): Argument #2 ($num) must be of type int, string given
