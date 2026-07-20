--TEST--
AOT: sscanf(null) soft-null on 8.4 (#21209, reverts #19894 TypeError)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
var_export(sscanf(null, '%s'));
echo "\n";
?>
--EXPECT--
NULL
