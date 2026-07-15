--TEST--
stdlib gethostbynamel(null) JIT — null coerces to false on 8.4 forward profile (#19098, ext/standard/dns.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
echo var_export(@gethostbynamel(null), true), "\n";
?>
--EXPECT--
false
