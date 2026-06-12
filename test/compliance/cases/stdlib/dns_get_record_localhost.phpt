--TEST--
stdlib dns_get_record() localhost A records (ext/standard/dns.c, #6392)
--SKIPIF--
<?php
require __DIR__ . '/../../../../ext/standard/VmDns.php';
if (false === PHPCompiler\ext\standard\VmDns::dnsGetRecord('localhost', 1)) {
    echo "skip\n";
}
?>
--FILE--
<?php
echo function_exists('dns_get_record') ? "fn\n" : "no-fn\n";
$r = dns_get_record('localhost', DNS_A);
echo is_array($r) ? "is-arr\n" : "not-arr\n";
if (is_array($r)) {
    echo isset($r[0]['type']) && $r[0]['type'] === 'A' ? "a-type\n" : "bad-type\n";
    echo isset($r[0]['ip']) ? "has-ip\n" : "no-ip\n";
}
try {
    dns_get_record('localhost', 0);
    echo "zero-ok\n";
} catch (ValueError $e) {
    echo "zero-ve\n";
}
enum E: int { case A = 1; }
try {
    dns_get_record(E::A, DNS_A);
    echo "enum-ok\n";
} catch (TypeError $e) {
    echo "enum-te\n";
}
--EXPECT--
fn
is-arr
a-type
has-ip
zero-ve
enum-te
