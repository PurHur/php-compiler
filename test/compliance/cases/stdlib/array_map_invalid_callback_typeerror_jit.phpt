--TEST--
stdlib array_map() invalid scalar callback TypeError JIT (#12676, ext/standard/array.c)
--JIT--
--FILE--
<?php
try {
    array_map(1, [1]);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
array_map(): Argument #1 ($callback) must be a valid callback or null, no array or string given
