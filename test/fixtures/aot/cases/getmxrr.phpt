--TEST--
AOT getmxrr() example.com MX probe (#3662)
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
echo $ok ? "ok\n" : "fail\n";
echo count($hosts) > 0 ? "hosts\n" : "no-hosts\n";
--EXPECT--
ok
hosts
