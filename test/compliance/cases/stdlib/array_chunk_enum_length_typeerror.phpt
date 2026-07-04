--TEST--
stdlib array_chunk() — backed int enum case length TypeError (#9971, ext/standard/array.c)
--FILE--
<?php
enum Len: int { case Two = 2; }
try {
    array_chunk([1, 2, 3], Len::Two);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
array_chunk(): Argument #2 ($length) must be of type int, Len given
