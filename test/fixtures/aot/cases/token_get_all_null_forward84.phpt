--TEST--
AOT: token_get_all(null) soft-null on 8.4 (#21503, reverts #19894 TypeError)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
echo count(token_get_all(null)), "\n";
?>
--EXPECT--
0
