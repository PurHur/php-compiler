--TEST--
stdlib getmxrr() JIT example.com MX (issue #3662)
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
$ok = getmxrr('example.com', $hosts);
echo $ok ? "jit-ok\n" : "jit-fail\n";
echo count($hosts) > 0 ? "jit-hosts\n" : "jit-no-hosts\n";
$weights = [];
$ok2 = getmxrr('example.com', $hosts, $weights);
echo $ok2 && count($weights) > 0 ? "jit-weights\n" : "jit-no-weights\n";
--EXPECT--
jit-ok
jit-hosts
jit-weights
