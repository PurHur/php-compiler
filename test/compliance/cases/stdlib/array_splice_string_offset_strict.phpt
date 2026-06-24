--TEST--
stdlib array_splice() string offset under strict call site (#11286, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);
$a = [1, 2, 3, 4];
try {
    array_splice($a, '1', 2);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: array_splice(): Argument #2 ($offset) must be of type int, string given
