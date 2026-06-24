--TEST--
stdlib array_chunk() float length under strict call site (#11287, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);
try {
    array_chunk([1, 2, 3], 1.9);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: array_chunk(): Argument #2 ($length) must be of type int, float given
