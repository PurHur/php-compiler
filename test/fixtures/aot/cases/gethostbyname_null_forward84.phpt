--TEST--
AOT: gethostbyname(null) soft-null coerce on 8.4 forward profile (#24178, reverts #23858, ext/standard/dns.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
var_export(gethostbyname(null));
echo "\n";
?>
--EXPECT--
''
