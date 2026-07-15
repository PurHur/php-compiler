--TEST--
stdlib dns_get_record() empty hostname DNS_ALL — root delegation records (#19078, ext/standard/dns.c)
--SKIPIF--
<?php
if (!function_exists('dns_get_record')) {
    echo 'skip';
}
$r = @dns_get_record('', DNS_ALL);
if (!is_array($r) || [] === $r) {
    echo 'skip no root DNS in environment';
}
?>
--FILE--
<?php
$result = dns_get_record('', DNS_ALL);
$nullResult = dns_get_record(null, DNS_ALL);
echo count($result) > 0 ? "records\n" : "empty\n";
echo ($result === $nullResult) ? "null-match\n" : "null-mismatch\n";
$type = $result[0]['type'] ?? '';
echo in_array($type, ['NS', 'SOA'], true) ? "type-ok\n" : "type-bad\n";
$aOnly = dns_get_record('', DNS_A);
echo ($aOnly === []) ? "a-empty\n" : "a-nonempty\n";
?>
--EXPECT--
records
null-match
type-ok
a-empty
