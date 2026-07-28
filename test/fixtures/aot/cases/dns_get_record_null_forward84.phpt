--TEST--
AOT: dns_get_record(null) soft-null coerce on 8.4 forward profile (#24178, reverts #23858, ext/standard/dns.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
var_export(dns_get_record(null));
echo "\n";
?>
--EXPECT--
array (
)
