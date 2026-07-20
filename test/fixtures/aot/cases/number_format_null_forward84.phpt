--TEST--
AOT: number_format(null) soft-null on 8.4 (#21429, reverts #21379 TypeError)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
echo number_format(null), "\n";
?>
--EXPECT--
0
