--TEST--
AOT: substr() PHP_INT_MAX length silent clamp on PROFILE=8.4 (#28556)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo substr('abc', 1, PHP_INT_MAX), "\n";
echo substr('hello', 0, 50), "\n";
?>
--EXPECT--
bc
hello
