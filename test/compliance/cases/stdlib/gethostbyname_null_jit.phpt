--TEST--
stdlib gethostbyname(null) JIT — null coerces to empty string on default profile (#19069, ext/standard/dns.c)
--JIT--
--FILE--
<?php
echo var_export(@gethostbyname(null), true), "\n";
?>
--EXPECT--
''
