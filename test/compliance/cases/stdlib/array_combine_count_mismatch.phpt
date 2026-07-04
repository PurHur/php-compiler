--TEST--
stdlib array_combine() inline literal count mismatch — ValueError (#16080, ext/standard/array.c)
--FILE--
<?php
try {
    array_combine(['a'], [1, 2]);
    echo "no throw\n";
} catch (ValueError $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
ValueError
array_combine(): Argument #1 ($keys) and argument #2 ($values) must have the same number of elements
