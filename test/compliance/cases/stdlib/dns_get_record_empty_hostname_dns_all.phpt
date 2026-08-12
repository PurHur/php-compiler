--TEST--
stdlib dns_get_record() empty hostname DNS_ALL/DNS_ANY — empty array (#30322, ext/standard/dns.c)
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(function ($n, $m) { return true; });
$result = dns_get_record('', DNS_ALL);
$nullResult = dns_get_record(null, DNS_ALL);
$anyResult = dns_get_record('');
echo ($result === []) ? "all-empty\n" : "all-nonempty\n";
echo ($nullResult === []) ? "null-empty\n" : "null-nonempty\n";
echo ($anyResult === []) ? "any-empty\n" : "any-nonempty\n";
$aOnly = dns_get_record('', DNS_A);
echo ($aOnly === []) ? "a-empty\n" : "a-nonempty\n";
?>
--EXPECT--
all-empty
null-empty
any-empty
a-empty
