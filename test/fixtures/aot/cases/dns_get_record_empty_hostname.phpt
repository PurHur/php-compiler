--TEST--
AOT: dns_get_record() empty hostname — empty array (#30322, ext/standard/dns.c)
--FILE--
<?php
$r = dns_get_record('', DNS_ANY);
echo is_array($r) && 0 === count($r) ? "empty\n" : "nonempty\n";
?>
--EXPECT--
empty
