--TEST--
stdlib ord(null) — coerces to 0 on 8.4 forward profile (#18821, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo 'ord(null)=', ord(null), "\n";
--EXPECT--
ord(null)=0
