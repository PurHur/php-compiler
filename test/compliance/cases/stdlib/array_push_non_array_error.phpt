--TEST--
stdlib: array_push() on non-array throws catchable Error (#4881, ext/standard/array.c)
--FILE--
<?php
try {
    array_push(1, 2);
    echo "push\n";
} catch (\Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
array_push(): Argument #1 ($array) could not be passed by reference
