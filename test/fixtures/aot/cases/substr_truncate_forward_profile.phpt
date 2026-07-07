--TEST--
AOT: substr() truncate: on PHP 8.4 forward profile (#17239)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo substr('hello world', 0, 50, truncate: true), "\n";
?>
--EXPECT--
hello world
