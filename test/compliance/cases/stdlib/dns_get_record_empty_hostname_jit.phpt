--TEST--
stdlib dns_get_record() empty hostname JIT — empty array (#13962, ext/standard/dns.c)
--FILE--
<?php
$result = dns_get_record('', DNS_A);
var_export($result === []);
?>
--EXPECT--
true
