--TEST--
AOT: chr(null) — coerces to NUL on 8.4 forward profile (#19161, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo bin2hex(chr(null)), "\n";
?>
--EXPECT--
00
