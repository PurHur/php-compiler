--TEST--
AOT: array_is_assoc() — TypeError on non-array (#7016, ext/standard/array.c)
--FILE--
<?php
array_is_assoc('x');
--EXPECTF--
Fatal error: Uncaught TypeError: array_is_assoc(): Argument #1 ($array) must be of type array, string given in %s:%d
Stack trace:
#0 {main}
  thrown in %s on line %d
