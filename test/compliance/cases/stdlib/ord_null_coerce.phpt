--TEST--
stdlib ord(null) — coerces to 0 on 8.4 forward profile (#19161, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo ord(null), "\n";
?>
--EXPECT--
0
