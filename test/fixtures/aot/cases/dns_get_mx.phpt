--TEST--
AOT dns_get_mx() example.com MX probe (#4125)
--SKIPIF--
<?php
require __DIR__ . '/../../../../ext/standard/VmDns.php';
$probe = PHPCompiler\ext\standard\VmDns::dnsGetMx('example.com');
if (!$probe['ok']) {
    echo "skip\n";
}
?>
--FILE--
<?php
$hosts = [];
$weights = [];
$ok = dns_get_mx('example.com', $hosts, $weights);
echo $ok ? "ok\n" : "fail\n";
echo count($hosts) > 0 ? "hosts\n" : "no-hosts\n";
--EXPECT--
ok
hosts
