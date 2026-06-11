--TEST--
stdlib dns_get_mx() example.com MX + empty hostname (issue #4125)
--SKIPIF--
<?php
require __DIR__ . '/../../../../ext/standard/VmDns.php';
if (false === PHPCompiler\ext\standard\VmDns::dnsGetMx('example.com')) {
    echo "skip\n";
}
?>
--FILE--
<?php
echo function_exists('dns_get_mx') ? "dns_get_mx-fn\n" : "no-dns_get_mx\n";
$hosts = [];
$weights = [];
$ok = dns_get_mx('example.com', $hosts, $weights);
echo is_bool($ok) ? "ok-bool\n" : "ok-not-bool\n";
echo $ok ? "ok-true\n" : "ok-false\n";
echo is_array($hosts) ? "hosts-array\n" : "hosts-not-array\n";
echo count($hosts) > 0 ? "hosts-nonempty\n" : "hosts-empty\n";
echo is_array($weights) ? "weights-array\n" : "weights-not-array\n";
echo count($weights) === count($hosts) ? "weights-parallel\n" : "weights-mismatch\n";
$h = [];
$w = [];
$empty = dns_get_mx('', $h, $w);
echo $empty === false ? "empty-false\n" : "empty-not-false\n";
echo [] === $h ? "empty-hosts\n" : "empty-hosts-not\n";
enum E: int { case A = 1; }
try {
    dns_get_mx(E::A, $hosts, $weights);
    echo "enum-accepted\n";
} catch (TypeError $e) {
    echo "enum-te\n";
}
--EXPECT--
dns_get_mx-fn
ok-bool
ok-true
hosts-array
hosts-nonempty
weights-array
weights-parallel
empty-false
empty-hosts
enum-te
