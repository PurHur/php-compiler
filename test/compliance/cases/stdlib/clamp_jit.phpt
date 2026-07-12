--TEST--
JIT clamp() (#17336, ext/standard/math.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo clamp(5, 1, 3), "\n";
echo clamp(0, 1, 3), "\n";
echo clamp(2, 1, 3), "\n";
?>
--EXPECT--
3
1
2
