--TEST--
stdlib dns_get_mx() JIT example.com MX (issue #4125)
--SKIPIF--
<?php
require __DIR__ . '/../../../../ext/standard/VmDns.php';
if (false === PHPCompiler\ext\standard\VmDns::dnsGetMx('example.com')) {
    echo "skip\n";
}
?>
--FILE--
<?php
$hosts = [];
$weights = [];
$ok = dns_get_mx('example.com', $hosts, $weights);
echo $ok ? "jit-ok\n" : "jit-fail\n";
echo count($hosts) > 0 ? "jit-hosts\n" : "jit-no-hosts\n";
--EXPECT--
jit-ok
jit-hosts
