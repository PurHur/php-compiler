--TEST--
AOT: ord(null) soft-null coerce on 8.4 forward profile (#21222, supersedes #19319 TypeError)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo ord(null), "\n";
?>
--EXPECT--
0
