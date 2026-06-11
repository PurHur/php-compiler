--TEST--
stdlib dns_get_mx() / getmxrr() MX lookup (issue #4125, #3662)
--SKIPIF--
<?php
require __DIR__ . '/../../../../ext/standard/VmDns.php';
$result = PHPCompiler\ext\standard\VmDns::dnsGetMx('example.com');
if (false === $result || [] === $result['hosts']) {
    echo "skip\n";
}
?>
--FILE--
<?php
echo function_exists('dns_get_mx') ? "dns-fn\n" : "no-dns-fn\n";
echo function_exists('getmxrr') ? "mxrr-fn\n" : "no-mxrr-fn\n";
$hosts = [];
$weights = [];
$ok = dns_get_mx('example.com', $hosts, $weights);
echo is_bool($ok) ? "ok-bool\n" : "ok-not-bool\n";
echo $ok ? "ok-true\n" : "ok-false\n";
echo isset($hosts[0]) && is_string($hosts[0]) ? "host0\n" : "no-host0\n";
echo isset($weights[0]) && is_int($weights[0]) ? "weight0\n" : "no-weight0\n";
$h2 = [];
$w2 = [];
echo dns_get_mx('', $h2, $w2) === false && $h2 === [] && $w2 === [] ? "empty-host\n" : "bad-empty\n";
$h3 = [];
echo getmxrr('example.com', $h3) ? "mxrr-ok\n" : "mxrr-fail\n";
echo isset($h3[0]) && is_string($h3[0]) ? "mxrr-host\n" : "no-mxrr-host\n";
enum E: string { case A = 'example.com'; }
try {
    dns_get_mx(E::A, $hosts, $weights);
    echo "enum-accepted\n";
} catch (TypeError $e) {
    echo "enum-te\n";
}
--EXPECT--
dns-fn
mxrr-fn
ok-bool
ok-true
host0
weight0
empty-host
mxrr-ok
mxrr-host
enum-te
