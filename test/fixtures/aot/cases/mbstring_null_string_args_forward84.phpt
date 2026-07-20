--TEST--
AOT: mb_strlen(null) soft-null under 8.4 — empty length 0 (#21197)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
echo mb_strlen(null), "\n";
?>
--EXPECT--
0
