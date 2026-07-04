--TEST--
stdlib array_pad() — backed int enum case length TypeError (#9971, ext/standard/array.c)
--FILE--
<?php
enum Len: int { case Two = 2; }
try {
    array_pad([1], Len::Two, 0);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
array_pad(): Argument #2 ($length) must be of type int, Len given
