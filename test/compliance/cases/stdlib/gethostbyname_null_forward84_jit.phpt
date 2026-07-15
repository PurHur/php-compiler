--TEST--
stdlib gethostbyname(null) JIT — null coerces to empty string on 8.4 forward profile (#19098, ext/standard/dns.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
echo var_export(@gethostbyname(null), true), "\n";
?>
--EXPECT--
''
