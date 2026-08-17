--TEST--
stdlib dns_get_record() empty hostname DNS_ALL/DNS_ANY — DNS_ANY empty, DNS_ALL root delegation (#31940, ext/standard/dns.c)
--SKIPIF--
<?php
$r = @dns_get_record('', DNS_ALL);
if (!is_array($r) || [] === $r) {
    die('skip root DNS_ALL delegation unavailable');
}
?>
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
all-nonempty
null-nonempty
any-empty
a-empty
